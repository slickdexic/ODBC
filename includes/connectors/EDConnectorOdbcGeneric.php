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
	}

	/**
	 * Set credentials from configuration parameters.
	 *
	 * @param array $params Supplemented parameters.
	 */
	protected function setCredentials( array $params ) {
		parent::setCredentials( $params );

		// Build ODBC DSN.
		if ( !empty( $params['connection_string'] ) ) {
			$this->credentials['dsn'] = $params['connection_string'];
		} elseif ( !empty( $params['driver'] ) ) {
			// Build a driver-based connection string.
			$parts = [];
			$parts[] = 'Driver={' . $params['driver'] . '}';
			if ( isset( $params['server'] ) ) {
				$parts[] = 'Server=' . $params['server'];
			}
			if ( isset( $this->credentials['dbname'] ) ) {
				$parts[] = 'Database=' . $this->credentials['dbname'];
			}
			if ( !empty( $params['trust server certificate'] ) || !empty( $params['trust_certificate'] ) ) {
				$parts[] = 'TrustServerCertificate=yes';
			}
			$this->credentials['dsn'] = implode( ';', $parts );
		} elseif ( !empty( $params['dsn'] ) ) {
			$this->credentials['dsn'] = $params['dsn'];
		} else {
			$this->error( 'externaldata-db-incomplete-information', $this->dbId ?? '', 'dsn or driver' );
		}
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
				$this->odbcConnection = ODBCConnectionManager::connect( $this->odbcSourceId );
				// Verify connection is alive.
				if ( @odbc_error( $this->odbcConnection ) !== '' ) {
					$this->error( 'externaldata-db-could-not-connect', 
						'Connection test failed for ODBC source: ' . $this->odbcSourceId );
					return false;
				}
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

		set_error_handler( static function ( $errno, $errstr ) {
			throw new \RuntimeException( $errstr );
		}, E_WARNING );

		try {
			$this->odbcConnection = odbc_connect( $dsn, $user, $password );
		} catch ( \RuntimeException $e ) {
			$this->error( 'externaldata-db-could-not-connect', $e->getMessage() );
			return false;
		} finally {
			restore_error_handler();
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
		return strtr( static::TEMPLATE, [
			'$columns' => static::listColumns( $this->columns ),
			'$from' => static::from( $this->tables, $this->joins ),
			'$where' => $this->conditions ? "\nWHERE {$this->conditions}" : '',
			'$group' => ( $this->sqlOptions['GROUP BY'] ?? '' ) ? "\nGROUP BY {$this->sqlOptions['GROUP BY']}" : '',
			'$having' => ( $this->sqlOptions['HAVING'] ?? '' ) ? "\nHAVING {$this->sqlOptions['HAVING']}" : '',
			'$order' => ( $this->sqlOptions['ORDER BY'] ?? '' ) ? "\nORDER BY {$this->sqlOptions['ORDER BY']}" : '',
			'$limit' => static::limit( $this->sqlOptions['LIMIT'] ?? 0 ),
		] );
	}

	/**
	 * Get query result as a two-dimensional array.
	 *
	 * @return array|null
	 */
	protected function fetch(): ?array {
		$query = $this->getQuery();

		set_error_handler( static function ( $errno, $errstr ) {
			throw new \RuntimeException( $errstr );
		}, E_WARNING );

		try {
			$rowset = odbc_exec( $this->odbcConnection, $query );
		} catch ( \RuntimeException $e ) {
			$this->error( 'externaldata-db-invalid-query', $query, $e->getMessage() );
			return null;
		} finally {
			restore_error_handler();
		}

		if ( !$rowset ) {
			$this->error( 'externaldata-db-invalid-query', $query );
			return null;
		}

		$result = [];
		while ( $row = odbc_fetch_object( $rowset ) ) {
			$result[] = $row;
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
