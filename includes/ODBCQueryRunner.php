<?php
/**
 * ODBC Query Runner for MediaWiki ODBC Extension.
 *
 * Executes queries against ODBC data sources, supporting both
 * arbitrary SQL (when allowed) and prepared statements.
 *
 * @file
 * @ingroup Extensions
 * @license GPL-2.0-or-later
 */

use MediaWiki\MediaWikiServices;

class ODBCQueryRunner {

	/** @var int Maximum length of any single composed-query clause (FROM, WHERE, ORDER BY, etc.). */
	private const MAX_CLAUSE_LENGTH = 1000;

	/** @var string The source ID. */
	private $sourceId;

	/** @var array The source configuration. */
	private $config;

	/** @var resource|object The ODBC connection. */
	private $connection;

	/**
	 * @param string $sourceId The ODBC source identifier.
	 * @throws MWException If connection fails.
	 */
	public function __construct( string $sourceId ) {
		$this->sourceId = $sourceId;
		$this->config = ODBCConnectionManager::getSourceConfig( $sourceId );
		if ( $this->config === null ) {
			throw new MWException(
				wfMessage( 'odbc-error-unknown-source', $sourceId )->text()
			);
		}
		$this->connection = ODBCConnectionManager::connect( $sourceId );
	}

	/**
	 * Execute a composed (dynamic) SQL query built from parameters.
	 *
	 * @param string $from Table(s) — FROM clause.
	 * @param array $columns Column mappings [ 'localVar' => 'dbColumn', ... ].
	 * @param string $where Optional WHERE clause.
	 * @param string $orderBy Optional ORDER BY clause.
	 * @param string $groupBy Optional GROUP BY clause.
	 * @param string $having Optional HAVING clause.
	 * @param int $limit Optional row limit.
	 * @return array Two-dimensional associative result set.
	 * @throws MWException On query error.
	 */
	public function executeComposed(
		string $from,
		array $columns,
		string $where = '',
		string $orderBy = '',
		string $groupBy = '',
		string $having = '',
		int $limit = 0
	): array {
		// Check if arbitrary queries are allowed.
		$mainConfig = MediaWikiServices::getInstance()->getMainConfig();
		$allowArbitrary = $mainConfig->get( 'ODBCAllowArbitraryQueries' );

		if ( !$allowArbitrary && empty( $this->config['allow_queries'] ) ) {
			throw new MWException(
				wfMessage( 'odbc-error-arbitrary-not-allowed' )->text()
			);
		}

		// Enforce maximum length on clause inputs to prevent resource exhaustion.
		$clauses = [ 'from' => $from, 'where' => $where, 'order by' => $orderBy, 'group by' => $groupBy, 'having' => $having ];
		foreach ( $clauses as $clauseName => $clauseValue ) {
			if ( strlen( $clauseValue ) > self::MAX_CLAUSE_LENGTH ) {
				throw new MWException(
					wfMessage( 'odbc-error-illegal-input', 'clause too long', $clauseName )->text()
				);
			}
		}

		// Sanitize inputs — block dangerous SQL patterns.
		self::sanitize( $from, 'from' );
		self::sanitize( $where, 'where' );
		self::sanitize( $orderBy, 'order by' );
		self::sanitize( $groupBy, 'group by' );
		self::sanitize( $having, 'having' );
		foreach ( $columns as $k => $v ) {
			self::sanitize( (string)$k, 'data' );
			self::sanitize( (string)$v, 'data' );
			// Validate that column/alias names are valid SQL identifiers.
			self::validateIdentifier( (string)$k, 'column alias' );
			self::validateIdentifier( (string)$v, 'column name' );
		}

		// Build SELECT columns.
		$selectCols = [];
		foreach ( $columns as $alias => $dbCol ) {
			if ( $alias === $dbCol || is_numeric( $alias ) ) {
				$selectCols[] = $dbCol;
			} else {
				// Properly quote column name and alias to prevent injection.
				$selectCols[] = $dbCol . ' AS ' . $alias;
			}
		}
		$colStr = empty( $selectCols ) ? '*' : implode( ', ', $selectCols );

		$maxRows = $mainConfig->get( 'ODBCMaxRows' );
		$effectiveLimit = $limit > 0 ? min( $limit, $maxRows ) : $maxRows;

		// Build query with LIMIT in SQL for efficiency.
		// Use TOP for SQL Server compatibility, LIMIT for others.
		// This is a simplified approach - in production, detect driver type.
		// Choose the correct row-limit syntax based on the ODBC driver.
		// SQL Server and MS Access use TOP n (before column list);
		// virtually all others (MySQL, PostgreSQL, SQLite, Oracle 12c+) use LIMIT n.
		$usesTopSyntax = self::requiresTopSyntax( $this->config );

		$sql = "SELECT";
		if ( $effectiveLimit > 0 && $usesTopSyntax ) {
			$sql .= " TOP $effectiveLimit";
		}
		$sql .= " $colStr FROM $from";
		if ( $where !== '' ) {
			$sql .= " WHERE $where";
		}
		if ( $groupBy !== '' ) {
			$sql .= " GROUP BY $groupBy";
		}
		if ( $having !== '' ) {
			$sql .= " HAVING $having";
		}
		if ( $orderBy !== '' ) {
			$sql .= " ORDER BY $orderBy";
		}
		if ( $effectiveLimit > 0 && !$usesTopSyntax ) {
			$sql .= " LIMIT $effectiveLimit";
		}

		return $this->executeRawQuery( $sql, [], $effectiveLimit );
	}

	/**
	 * Execute a prepared statement configured for this source.
	 *
	 * @param string $queryName The prepared statement name (key in 'prepared' config).
	 * @param array $parameters Parameters to bind.
	 * @return array Two-dimensional result set.
	 * @throws MWException On error.
	 */
	public function executePrepared( string $queryName, array $parameters = [] ): array {
		$prepared = $this->config['prepared'] ?? null;

		if ( $prepared === null ) {
			throw new MWException(
				wfMessage( 'odbc-error-no-prepared', $this->sourceId )->text()
			);
		}

		// Single prepared statement (string) or multiple (associative array).
		if ( is_string( $prepared ) ) {
			$sql = $prepared;
		} elseif ( is_array( $prepared ) && isset( $prepared[$queryName] ) ) {
			$entry = $prepared[$queryName];
			$sql = is_array( $entry ) ? ( $entry['query'] ?? '' ) : $entry;
		} else {
			throw new MWException(
				wfMessage( 'odbc-error-prepared-not-found', $queryName, $this->sourceId )->text()
			);
		}

		if ( empty( $sql ) ) {
			throw new MWException(
				wfMessage( 'odbc-error-empty-query' )->text()
			);
		}

		$mainConfig = MediaWikiServices::getInstance()->getMainConfig();
		$maxRows = $mainConfig->get( 'ODBCMaxRows' );

		return $this->executeRawQuery( $sql, $parameters, $maxRows );
	}

	/**
	 * Execute a raw SQL query string with optional parameters (for prepared statements).
	 *
	 * @param string $sql The SQL query.
	 * @param array $params Parameters to bind for prepared statements.
	 * @param int $maxRows Maximum rows to fetch.
	 * @return array Two-dimensional result set [ [ 'col' => 'val', ... ], ... ].
	 * @throws MWException On query error.
	 */
	public function executeRawQuery( string $sql, array $params = [], int $maxRows = 1000 ): array {
		// Check cache first if caching is enabled.
		$mainConfig = MediaWikiServices::getInstance()->getMainConfig();
		$cacheExpiry = $mainConfig->get( 'ODBCCacheExpiry' );
		$cacheKey = null;

		if ( $cacheExpiry > 0 ) {
			$cache = ObjectCache::getLocalClusterInstance();
			$cacheKey = $cache->makeKey(
				'odbc-query',
				$this->sourceId,
				md5( $sql . '|' . implode( ',', $params ) . '|' . $maxRows )
			);
			$cached = $cache->get( $cacheKey );
			if ( $cached !== false ) {
				return $cached;
			}
		}

		set_error_handler( static function ( $errno, $errstr ) {
			throw new MWException( $errstr );
		}, E_WARNING );

		$resultResource = null;
		try {
			if ( !empty( $params ) ) {
				// Prepared statement execution.
				$stmt = odbc_prepare( $this->connection, $sql );
				if ( !$stmt ) {
					$odbcErr = odbc_errormsg( $this->connection );
				wfDebugLog( 'odbc', "Prepare failed [{$this->sourceId}]: $sql — $odbcErr" );
					throw new MWException(
						wfMessage( 'odbc-error-prepare-failed', $odbcErr )->text()
					);
				}
				$success = odbc_execute( $stmt, $params );
				if ( !$success ) {
					$odbcErr = odbc_errormsg( $this->connection );
					wfDebugLog( 'odbc', "Execute failed [{$this->sourceId}]: $sql — $odbcErr" );
					throw new MWException(
						wfMessage( 'odbc-error-execute-failed', $odbcErr )->text()
					);
				}
				$resultResource = $stmt;
			} else {
				// Direct execution.
				$resultResource = odbc_exec( $this->connection, $sql );
				if ( !$resultResource ) {
					$odbcErr = odbc_errormsg( $this->connection );
					wfDebugLog( 'odbc', "Query failed [{$this->sourceId}]: $sql — $odbcErr" );
					throw new MWException(
						wfMessage( 'odbc-error-query-failed', $odbcErr )->text()
					);
				}
			}

			// Fetch results.
			$result = [];
			$rowCount = 0;
			while ( ( $row = odbc_fetch_array( $resultResource ) ) && $rowCount < $maxRows ) {
				// Convert encoding to UTF-8 if needed.
				$cleanRow = [];
				foreach ( $row as $key => $value ) {
					if ( $value !== null && is_string( $value ) ) {
						// More comprehensive encoding detection.
						$encoding = mb_detect_encoding( 
							$value, 
							[ 'UTF-8', 'ISO-8859-1', 'ISO-8859-15', 'Windows-1252', 'ASCII' ], 
							true 
						);
						if ( $encoding && $encoding !== 'UTF-8' && $encoding !== 'ASCII' ) {
							$value = mb_convert_encoding( $value, 'UTF-8', $encoding );
						}
					}
					$cleanRow[$key] = $value;
				}
				$result[] = $cleanRow;
				$rowCount++;
			}

			if ( $resultResource ) {
				odbc_free_result( $resultResource );
			}

			// Store in cache if caching is enabled.
			if ( $cacheKey !== null && $cacheExpiry > 0 ) {
				$cache->set( $cacheKey, $result, $cacheExpiry );
			}

			// Log successful query execution for audit trail.
			wfDebugLog( 'odbc', "Query executed on source '{$this->sourceId}': " . 
				substr( $sql, 0, 100 ) . ( strlen( $sql ) > 100 ? '...' : '' ) . 
				" - Returned $rowCount rows" );

			return $result;

		} catch ( MWException $e ) {
			// Clean up resources on error.
			if ( $resultResource ) {
				@odbc_free_result( $resultResource );
			}
			throw $e;
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Sanitize a query component to prevent SQL injection via dangerous keywords.
	 *
	 * This is the single authoritative blocklist for the entire extension.
	 * Both the standalone parser functions and the External Data connector
	 * should call this method.
	 *
	 * @param string $input The input string.
	 * @param string $context Name of the parameter (for error messages).
	 * @throws MWException If a dangerous pattern is detected.
	 */
	public static function sanitize( string $input, string $context ): void {
		if ( $input === '' ) {
			return;
		}

		// Strip control characters including null bytes that could bypass checks.
		$clean = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $input );
		$upper = strtoupper( $clean );

		// Exact-character patterns (always dangerous in composed SQL).
		$charPatterns = [ ';', '--', '/*', '*/', '<?', 'CHAR(', 'CONCAT(', 'UNION' ];
		foreach ( $charPatterns as $pattern ) {
			if ( strpos( $upper, strtoupper( $pattern ) ) !== false ) {
				throw new MWException(
					wfMessage( 'odbc-error-illegal-input', $pattern, $context )->text()
				);
			}
		}

		// Keyword patterns — matched as whole words with word-boundary checks
		// to reduce false positives (e.g. "DESCRIPTION" should not match "DROP").
		$keywords = [
			'GRANT', 'REVOKE',
			'DROP', 'DELETE', 'TRUNCATE',
			'CREATE', 'ALTER',
			'INSERT', 'UPDATE', 'MERGE', 'REPLACE',
			'EXEC', 'EXECUTE', 'CALL',
			'SHUTDOWN', 'BACKUP', 'RESTORE',
			'INTO OUTFILE', 'INTO DUMPFILE',
			'LOAD_FILE', 'LOAD DATA',
			'XP_', 'SP_',
			'OPENROWSET', 'OPENDATASOURCE', 'OPENQUERY',
			'DBCC',
			'INFORMATION_SCHEMA', 'SYS.',
		];
		foreach ( $keywords as $keyword ) {
			// Use word-boundary regex to avoid false positives.
			$pattern = '/\b' . preg_quote( $keyword, '/' ) . '/i';
			if ( preg_match( $pattern, $clean ) ) {
				throw new MWException(
					wfMessage( 'odbc-error-illegal-input', $keyword, $context )->text()
				);
			}
		}
	}

	/**
	 * Validate that a string is a valid SQL identifier (alphanumeric + underscore).
	 *
	 * @param string $identifier The identifier to validate.
	 * @param string $context Context for error messages.
	 * @throws MWException If invalid.
	 */
	private static function validateIdentifier( string $identifier, string $context ): void {
		if ( $identifier === '' || $identifier === '*' ) {
			return; // Allow empty and wildcard.
		}
		
		// Allow identifiers with: letters, numbers, underscore, dot (for qualified names).
		// Limit length to prevent abuse.
		if ( strlen( $identifier ) > 128 ) {
			throw new MWException(
				wfMessage( 'odbc-error-identifier-too-long', $context )->text()
			);
		}
		
		if ( !preg_match( '/^[a-zA-Z_][a-zA-Z0-9_\.]*$/', $identifier ) ) {
			throw new MWException(
				wfMessage( 'odbc-error-invalid-identifier', $identifier, $context )->text()
			);
		}
	}

	/**
	 * Determine whether the ODBC driver uses TOP n syntax (SQL Server / Access)
	 * rather than the standard LIMIT n syntax.
	 *
	 * For DSN-only configs where no driver string is available, we default to
	 * LIMIT syntax, which is correct for the majority of databases.
	 *
	 * @param array $config The source configuration array.
	 * @return bool True if the driver uses TOP n; false if it uses LIMIT n.
	 */
	private static function requiresTopSyntax( array $config ): bool {
		$driver = strtolower( $config['driver'] ?? '' );
		if ( $driver === '' ) {
			return false; // DSN-only: default to LIMIT
		}
		return strpos( $driver, 'sql server' ) !== false
			|| strpos( $driver, 'sqlserver' ) !== false
			|| strpos( $driver, 'access' ) !== false
			|| strpos( $driver, 'sybase' ) !== false
			|| strpos( $driver, 'adaptive server' ) !== false;
	}

	/**
	 * Get the source ID.
	 *
	 * @return string
	 */
	public function getSourceId(): string {
		return $this->sourceId;
	}

	/**
	 * Get column names from the last result set or from table metadata.
	 *
	 * @param string $tableName The table name.
	 * @return array Column name list, or empty array on error (check logs).
	 */
	public function getTableColumns( string $tableName ): array {
		set_error_handler( static function ( $errno, $errstr ) {
			throw new MWException( $errstr );
		}, E_WARNING );

		try {
			$columns = odbc_columns( $this->connection, '', '', $tableName );
			if ( !$columns ) {
				wfDebugLog( 'odbc', "Failed to get columns for table '$tableName' on source '{$this->sourceId}'" );
				return [];
			}
			$result = [];
			while ( $row = odbc_fetch_array( $columns ) ) {
				$result[] = $row['COLUMN_NAME'] ?? $row['column_name'] ?? '';
			}
			odbc_free_result( $columns );
			return array_filter( $result );
		} catch ( MWException $e ) {
			wfDebugLog( 'odbc', "Exception getting columns for table '$tableName': " . $e->getMessage() );
			return [];
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Get list of tables accessible through this connection.
	 *
	 * @return array Table name list, or empty array on error (check logs).
	 */
	public function getTables(): array {
		set_error_handler( static function ( $errno, $errstr ) {
			throw new MWException( $errstr );
		}, E_WARNING );

		try {
			$tables = odbc_tables( $this->connection );
			if ( !$tables ) {
				wfDebugLog( 'odbc', "Failed to get tables on source '{$this->sourceId}'" );
				return [];
			}
			$result = [];
			while ( $row = odbc_fetch_array( $tables ) ) {
				$type = $row['TABLE_TYPE'] ?? $row['table_type'] ?? '';
				if ( $type === 'TABLE' || $type === 'VIEW' ) {
					$result[] = $row['TABLE_NAME'] ?? $row['table_name'] ?? '';
				}
			}
			odbc_free_result( $tables );
			return array_filter( $result );
		} catch ( MWException $e ) {
			wfDebugLog( 'odbc', "Exception getting tables on source '{$this->sourceId}': " . $e->getMessage() );
			return [];
		} finally {
			restore_error_handler();
		}
	}
}
