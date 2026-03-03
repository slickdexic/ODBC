<?php
/**
 * External Data connector bridge for the ODBC Extension.
 *
 * This class integrates with the External Data extension's connector system,
 * allowing External Data's parser functions (#get_db_data, #get_external_data,
 * #for_external_table, etc.) to use ODBC sources configured via $wgODBCSources.
 *
 * To use with External Data, set 'type' => 'odbc_generic' in $wgExternalDataSources
 * or configure sources in $wgODBCSources and reference them via this bridge.
 *
 * Usage in wikitext (via External Data):
 *   {{#get_db_data: db=my_odbc_source | from=TableName | data=localVar=dbCol,... }}
 *
 * Configuration in LocalSettings.php:
 *   $wgExternalDataSources['my_odbc_source'] = [
 *       'type'     => 'odbc_generic',
 *       'dsn'      => 'MySystemDSN',         // or use driver+server+database
 *       'driver'   => 'ODBC Driver 17 for SQL Server',
 *       'server'   => 'localhost,1433',
 *       'name'     => 'MyDatabase',
 *       'user'     => 'username',
 *       'password' => 'password',
 *   ];
 *
 * Or configure in $wgODBCSources and reference from External Data:
 *   $wgODBCSources['my_source'] = [ ... ];
 *   $wgExternalDataSources['my_source'] = [
 *       'type' => 'odbc_generic',
 *       'odbc_source' => 'my_source',  // references $wgODBCSources entry
 *   ];
 *
 * @file
 * @ingroup Extensions
 * @license GPL-2.0-or-later
 */

use MediaWiki\Title\Title;

// Guard: EDConnectorComposed is provided by the External Data extension.
// If External Data is not installed, attempting to define a class that extends
// EDConnectorComposed would produce a fatal 'Class not found' error at autoload
// time. Returning early here prevents that failure (see §3.10 / P2-059).
if ( !class_exists( 'EDConnectorComposed', false ) ) {
	return;
}

/**
 * EDConnectorOdbcGeneric — an External Data connector that routes through
 * the ODBC extension's connection and query infrastructure.
 *
 * This class is only autoloaded when External Data tries to instantiate it,
 * so EDConnectorComposed will always be available at that point.
 */
class EDConnectorOdbcGeneric extends EDConnectorComposed {

	/** @var bool $keepExternalVarsCase External variables' case ought to be preserved. */
	public $keepExternalVarsCase = true;

	/** @const string TEMPLATE SQL query template (no trailing semicolon for ODBC driver compatibility). */
	protected const TEMPLATE = 'SELECT $columns $from $where $group $having $order $limit';

	/** @const string DEFAULT_ENCODING Default encoding for detection. */
	protected const DEFAULT_ENCODING = 'ISO-8859-15';

	/** @var resource $odbcConnection The ODBC connection resource. */
	private $odbcConnection;

	/** @var string|null $odbcSourceId Reference to $wgODBCSources entry. */
	private $odbcSourceId;

	/**
	 * Constructor. Analyse parameters and wiki settings; set $this->errors.
	 *
	 * @param array &$args Arguments to parser or Lua function.
	 * @param Title $title A Title object.
	 */
	protected function __construct( array &$args, Title $title ) {
		parent::__construct( $args, $title );

		// Check for the odbc PHP extension.
		if ( !function_exists( 'odbc_connect' ) ) {
			$this->error(
				'externaldata-missing-library',
				'odbc',
				'#get_db_data (type = odbc_generic)',
				'mw.ext.getExternalData.getDbData (type = odbc_generic)'
			);
		}

		// Check for SQL injections in composed queries.
		$this->checkComposedParams();

		// See if this references an $wgODBCSources entry.
		$this->odbcSourceId = $args['odbc_source'] ?? null;

		// When referencing an ODBCSources entry, inherit the driver name from that source's
		// config. The External Data source entry for odbc_source mode typically only specifies
		// 'type' and 'odbc_source', so $this->credentials['driver'] would otherwise be empty,
		// causing getQuery() to default to LIMIT syntax even for SQL Server sources (KI-027 fix).
		if ( $this->odbcSourceId !== null ) {
			$referencedConfig = ODBCConnectionManager::getSourceConfig( $this->odbcSourceId );
			if ( $referencedConfig !== null ) {
				$this->credentials['driver'] = $referencedConfig['driver'] ?? '';
			}
		}
	}

	/**
	 * Set credentials from configuration parameters.
	 *
	 * @param array $params Supplemented parameters.
	 */
	protected function setCredentials( array $params ) {
		parent::setCredentials( $params );

		// Normalise config keys to the format expected by ODBCConnectionManager::buildConnectionString().
		// External Data stores the database name in credentials['dbname'] (set by the parent class),
		// while $wgODBCSources uses 'database' or 'name'. Gather all variants so nothing is lost.
		$connConfig = [
			'connection_string'        => $params['connection_string'] ?? '',
			'dsn'                      => $params['dsn'] ?? '',
			'driver'                   => $params['driver'] ?? '',
			'server'                   => $params['server'] ?? '',
			'database'                 => $params['database'] ?? $params['name'] ?? $this->credentials['dbname'] ?? '',
			'port'                     => $params['port'] ?? '',
			'trust_certificate'        => $params['trust_certificate'] ?? '',
			'trust server certificate' => $params['trust server certificate'] ?? '',
			'dsn_params'               => $params['dsn_params'] ?? [],
		];

		$dsn = ODBCConnectionManager::buildConnectionString( $connConfig );
		if ( $dsn === '' ) {
			$this->error( 'externaldata-db-incomplete-information', $this->dbId ?? '', 'dsn or driver' );
			return;
		}

		// Preserve the driver name so getQuery() can detect SQL Server TOP N syntax later.
		$this->credentials['driver'] = $params['driver'] ?? '';
		$this->credentials['dsn'] = $dsn;
	}

	/**
	 * Check composed query parameters for dangerous SQL patterns.
	 *
	 * Delegates to the shared ODBCQueryRunner::sanitize() method for
	 * a single consistent blocklist across the entire extension.
	 */
	private function checkComposedParams(): void {
		$paramsToCheck = [
			'from' => $this->tables ?? [],
			'data' => $this->columns ?? [],
			'where' => $this->conditions ?? '',
			'order by' => $this->sqlOptions['ORDER BY'] ?? '',
			'group by' => $this->sqlOptions['GROUP BY'] ?? '',
			'having' => $this->sqlOptions['HAVING'] ?? '',
		];
		foreach ( $paramsToCheck as $context => $param ) {
			try {
				$this->checkParamWithSharedSanitizer( $param, $context );
			} catch ( MWException $e ) {
				$this->error( 'odbc-ed-error-illegal', $e->getMessage(), $context );
			}
		}
	}

	/**
	 * Check a parameter using the shared ODBCQueryRunner sanitizer.
	 *
	 * @param mixed $param The parameter to check.
	 * @param string $context The context name for error messages.
	 * @throws MWException If a dangerous pattern is detected.
	 */
	private function checkParamWithSharedSanitizer( $param, string $context ): void {
		if ( is_string( $param ) ) {
			ODBCQueryRunner::sanitize( $param, $context );
		} elseif ( is_array( $param ) ) {
			foreach ( $param as $key => $value ) {
				if ( is_string( $key ) ) {
					ODBCQueryRunner::sanitize( $key, $context );
				}
				if ( is_string( $value ) ) {
					ODBCQueryRunner::sanitize( $value, $context );
				}
			}
		}
	}

	/**
	 * Connect to the database server via ODBC.
	 *
	 * @return bool True on success.
	 */
	protected function connect(): bool {
		// If referencing an ODBCSources entry, connect via ODBCConnectionManager.
		if ( $this->odbcSourceId !== null ) {
			try {
				// ODBCConnectionManager::connect() already validates liveness via SELECT 1 ping.
				$this->odbcConnection = ODBCConnectionManager::connect( $this->odbcSourceId );
				return true;
			} catch ( MWException $e ) {
				$this->error( 'externaldata-db-could-not-connect', $e->getMessage() );
				return false;
			}
		}

		// Otherwise use credentials built from External Data config.
		$dsn = $this->credentials['dsn'] ?? '';
		$user = $this->credentials['user'] ?? '';
		$password = $this->credentials['password'] ?? '';

		try {
			$this->odbcConnection = ODBCConnectionManager::withOdbcWarnings(
				fn() => odbc_connect( $dsn, $user, $password )
			);
		} catch ( MWException $e ) {
			$this->error( 'externaldata-db-could-not-connect', $e->getMessage() );
			return false;
		}

		if ( !$this->odbcConnection ) {
			$this->error( 'externaldata-db-could-not-connect' );
			return false;
		}

		return true;
	}

	/**
	 * Get the list of columns for the SELECT clause.
	 *
	 * @param array $columns An array of columns.
	 * @return string A list of columns.
	 */
	protected static function listColumns( array $columns ): string {
		return implode( ', ', $columns );
	}

	/**
	 * Build the FROM clause.
	 *
	 * @param array $tables An associative array of tables.
	 * @param array $joins JOIN conditions.
	 * @return string The FROM clause.
	 */
	protected static function from( array $tables, array $joins ): string {
		$tableList = [];
		foreach ( $tables as $alias => $table ) {
			if ( $alias !== $table && !is_numeric( $alias ) ) {
				$tableList[] = "$table AS $alias";
			} else {
				$tableList[] = $table;
			}
		}
		return 'FROM ' . implode( ', ', $tableList );
	}

	/**
	 * Get the LIMIT clause (standard SQL).
	 *
	 * @param int $limit Number of rows.
	 * @return string The LIMIT clause.
	 */
	protected static function limit( int $limit ): string {
		return $limit ? 'LIMIT ' . (string)$limit : '';
	}

	/**
	 * Get the full query text.
	 *
	 * @return string
	 */
	protected function getQuery(): string {
		$limit = (int)( $this->sqlOptions['LIMIT'] ?? 0 );

		// Detect the correct row-limit syntax for this driver.
		// SQL Server/Access/Sybase → TOP n, Progress OpenEdge → FIRST n, others → LIMIT n.
		$rowLimitStyle = $limit > 0 ? ODBCQueryRunner::getRowLimitStyle( [
			'driver' => $this->credentials['driver'] ?? '',
		] ) : 'limit';

		if ( $limit > 0 && $rowLimitStyle === 'top' ) {
			// SQL Server / Access / Sybase: INSERT TOP before column list.
			$template = 'SELECT TOP ' . $limit . ' $columns $from $where $group $having $order';
		} elseif ( $limit > 0 && $rowLimitStyle === 'first' ) {
			// Progress OpenEdge: INSERT FIRST before column list.
			$template = 'SELECT FIRST ' . $limit . ' $columns $from $where $group $having $order';
		} else {
			$template = static::TEMPLATE;
		}

		return strtr( $template, [
			'$columns' => static::listColumns( $this->columns ),
			'$from'    => static::from( $this->tables, $this->joins ),
			'$where'   => $this->conditions ? "\nWHERE {$this->conditions}" : '',
			'$group'   => ( $this->sqlOptions['GROUP BY'] ?? '' ) ? "\nGROUP BY {$this->sqlOptions['GROUP BY']}" : '',
			'$having'  => ( $this->sqlOptions['HAVING'] ?? '' ) ? "\nHAVING {$this->sqlOptions['HAVING']}" : '',
			'$order'   => ( $this->sqlOptions['ORDER BY'] ?? '' ) ? "\nORDER BY {$this->sqlOptions['ORDER BY']}" : '',
			'$limit'   => ( $rowLimitStyle === 'limit' ) ? static::limit( $limit ) : '',
		] );
	}

	/**
	 * Get query result as a two-dimensional array.
	 *
	 * When the connector bridges a $wgODBCSources entry (odbc_source mode), the query is
	 * routed through ODBCQueryRunner::executeRawQuery() to gain:
	 * - Query result caching via $wgODBCCacheExpiry (P2-016)
	 * - Automatic UTF-8 encoding conversion (P2-016)
	 * - Consistent audit logging
	 *
	 * In standalone mode (direct External Data credentials), queries are executed via the
	 * pre-opened connection with UTF-8 conversion applied row by row.
	 *
	 * @return array|null
	 */
	protected function fetch(): ?array {
		$query = $this->getQuery();
		$maxRows = (int)MediaWiki\MediaWikiServices::getInstance()->getMainConfig()->get( 'ODBCMaxRows' );

		// odbc_source mode: route through ODBCQueryRunner for caching, UTF-8, and logging.
		if ( $this->odbcSourceId !== null ) {
			try {
				$runner = new ODBCQueryRunner( $this->odbcSourceId );
				$rows = $runner->executeRawQuery( $query, [], $maxRows );
			} catch ( MWException $e ) {
				$this->error( 'externaldata-db-invalid-query', $query, $e->getMessage() );
				return null;
			}
			// Convert associative arrays to stdClass objects, matching the format
			// expected by the External Data framework when iterating result rows.
			return array_map( static fn( array $row ): \stdClass => (object)$row, $rows );
		}

		// Standalone mode: execute directly via the pre-opened connection.
		try {
			$rowset = ODBCConnectionManager::withOdbcWarnings(
				fn() => odbc_exec( $this->odbcConnection, $query )
			);
		} catch ( MWException $e ) {
			$this->error( 'externaldata-db-invalid-query', $query, $e->getMessage() );
			return null;
		}

		if ( !$rowset ) {
			$this->error( 'externaldata-db-invalid-query', $query );
			return null;
		}

		$result = [];
		$count = 0;
		while ( $row = odbc_fetch_object( $rowset ) ) {
			// Apply UTF-8 encoding conversion for non-UTF-8 data (partial KI-020 fix).
			foreach ( $row as $key => $value ) {
				if ( $value !== null && is_string( $value ) ) {
					$encoding = mb_detect_encoding(
						$value,
						[ 'UTF-8', 'ISO-8859-1', 'ISO-8859-15', 'Windows-1252', 'ASCII' ],
						true
					);
					if ( $encoding && $encoding !== 'UTF-8' && $encoding !== 'ASCII' ) {
						$row->$key = mb_convert_encoding( $value, 'UTF-8', $encoding );
					}
				}
			}
			$result[] = $row;
			if ( $maxRows > 0 && ++$count >= $maxRows ) {
				// Respect $wgODBCMaxRows — stop fetching once the global row limit is hit.
				wfDebugLog( 'odbc', "ED fetch truncated at $maxRows rows (ODBCMaxRows) for source '{$this->dbId}'" );
				break;
			}
		}
		odbc_free_result( $rowset );
		return $result;
	}

	/**
	 * Disconnect from the ODBC data source.
	 */
	protected function disconnect() {
		// If using ODBCConnectionManager (odbc_source reference), the manager
		// handles connection lifecycle — do not close here.
		if ( $this->odbcSourceId !== null ) {
			$this->odbcConnection = null;
			return;
		}
		// For standalone External Data connections opened in connect(), close them
		// explicitly to avoid leaking the connection resource for the rest of the request.
		if ( $this->odbcConnection ) {
			odbc_close( $this->odbcConnection );
			$this->odbcConnection = null;
		}
	}
}
