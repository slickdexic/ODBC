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

	/** @var int SQL_HANDLE_STMT for odbc_setoption() — identifies a statement handle (ODBC constant). */
	private const SQL_HANDLE_STMT = 1;

	/** @var int SQL_QUERY_TIMEOUT attribute for odbc_setoption() — sets per-statement timeout in seconds. */
	private const SQL_QUERY_TIMEOUT = 0;

	/** @var string The source ID. */
	private $sourceId;

	/** @var array The source configuration. */
	private $config;

	/** @var \Config Cached main config instance. Fetched once in the constructor to avoid
	 *              repeated MediaWikiServices::getInstance()->getMainConfig() calls in hot paths. */
	private $mainConfig;

	/** @var resource|object The ODBC connection. */
	private $connection;

	/**
	 * @param string $sourceId The ODBC source identifier.
	 * @throws MWException If connection fails.
	 */
	public function __construct( string $sourceId ) {
		$this->mainConfig = MediaWikiServices::getInstance()->getMainConfig();
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
		$mainConfig = $this->mainConfig;
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

		// Sanitize clause-level inputs — block dangerous SQL patterns.
		self::sanitize( $from, 'from' );
		self::sanitize( $where, 'where' );
		self::sanitize( $orderBy, 'order by' );
		self::sanitize( $groupBy, 'group by' );
		self::sanitize( $having, 'having' );

		// HAVING without GROUP BY is invalid on PostgreSQL and SQL Server.
		// Catch it early and return a clear error rather than letting the DBMS
		// issue a cryptic driver-level message (KI-064 / P2-064).
		if ( $having !== '' && $groupBy === '' ) {
			throw new MWException(
				wfMessage( 'odbc-error-having-without-groupby' )->text()
			);
		}

		// Validate columns, build SELECT list — single pass.
		$selectCols = [];
		foreach ( $columns as $alias => $dbCol ) {
			self::sanitize( (string)$alias, 'data' );
			self::sanitize( (string)$dbCol, 'data' );
			self::validateIdentifier( (string)$alias, 'column alias' );
			self::validateIdentifier( (string)$dbCol, 'column name' );
			if ( $alias === $dbCol || is_numeric( $alias ) ) {
				$selectCols[] = $dbCol;
			} else {
				$selectCols[] = $dbCol . ' AS ' . $alias;
			}
		}
		$colStr = empty( $selectCols ) ? '*' : implode( ', ', $selectCols );

		$maxRows = $mainConfig->get( 'ODBCMaxRows' );
		$effectiveLimit = $limit > 0 ? min( $limit, $maxRows ) : $maxRows;

		// Choose the correct row-limit syntax based on the ODBC driver:
		// - 'top'   → SELECT TOP n col FROM tbl     (SQL Server, MS Access, Sybase)
		// - 'first' → SELECT FIRST n col FROM tbl   (Progress OpenEdge)
		// - 'limit' → SELECT col FROM tbl LIMIT n   (MySQL, PostgreSQL, SQLite, Oracle 12c+, etc.)
		$rowLimitStyle = self::getRowLimitStyle( $this->config );

		$sql = 'SELECT';
		if ( $effectiveLimit > 0 && $rowLimitStyle === 'top' ) {
			$sql .= " TOP $effectiveLimit";
		} elseif ( $effectiveLimit > 0 && $rowLimitStyle === 'first' ) {
			$sql .= " FIRST $effectiveLimit";
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
		if ( $effectiveLimit > 0 && $rowLimitStyle === 'limit' ) {
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

		$mainConfig = $this->mainConfig;
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
		$mainConfig = $this->mainConfig;
		$cacheExpiry = $mainConfig->get( 'ODBCCacheExpiry' );
		$cache = null;
		$cacheKey = null;

		if ( $cacheExpiry > 0 ) {
			$cache = ObjectCache::getLocalClusterInstance();
			$cacheKey = $cache->makeKey(
				'odbc-query',
				$this->sourceId,
				md5( $sql . '|' . json_encode( $params ) . '|' . $maxRows )
			);
			$cached = $cache->get( $cacheKey );
			if ( $cached !== false ) {
				return $cached;
			}
		}

		// Determine query timeout for this source.
		$timeout = (int)( $this->config['timeout'] ?? $mainConfig->get( 'ODBCQueryTimeout' ) );
		// Slow-query log threshold. 0 = disabled (default).
		$slowThreshold = (float)$mainConfig->get( 'ODBCSlowQueryThreshold' );

		// Wrap all ODBC calls in withOdbcWarnings() so PHP E_WARNINGs (e.g. from
		// odbc_prepare() or odbc_execute() on connection failure) are automatically
		// converted to MWException — DRY (P2-051).
		$stmt = null;
		try {
			return ODBCConnectionManager::withOdbcWarnings(
				function () use ( &$stmt, $sql, $params, $maxRows, $timeout, $cacheExpiry, $cache, $cacheKey, $slowThreshold ) {
					// Always use prepare + execute, even for parameter-less queries.
					// This enables statement-level timeout and allows the driver to cache the query plan.
					$stmt = odbc_prepare( $this->connection, $sql );
					if ( !$stmt ) {
						$odbcErr = odbc_errormsg( $this->connection );
						wfDebugLog( 'odbc', "Prepare failed on source '{$this->sourceId}': $sql — $odbcErr" );
						throw new MWException(
							wfMessage( 'odbc-error-prepare-failed', $odbcErr )->text()
						);
					}

					// Apply statement-level timeout (SQL_HANDLE_STMT = 1, SQL_QUERY_TIMEOUT = 0).
					// This is the ODBC-standard approach and better supported by drivers than
					// the connection-level setting. Not all drivers implement this attribute;
					// suppress the PHP warning (@ avoids the outer error handler turning it into
					// an MWException) but check the return value so operators see a log entry
					// rather than nothing when their driver does not support it (KI-033).
					if ( $timeout > 0 ) {
						$timeoutSet = @odbc_setoption( $stmt, self::SQL_HANDLE_STMT, self::SQL_QUERY_TIMEOUT, $timeout );
						if ( $timeoutSet === false ) {
							wfDebugLog( 'odbc', "Could not set query timeout ({$timeout}s) for source '{$this->sourceId}'" .
								" — driver may not support per-statement timeouts. Queries may run indefinitely." );
						}
					}

					// Start timing before odbc_execute() so the slow-query threshold correctly
					// measures total execution time (DB processing + row fetch), not just fetch time (KI-073).
					$queryStart = microtime( true );

					$success = odbc_execute( $stmt, $params ?: [] );
					if ( !$success ) {
						$odbcErr = odbc_errormsg( $this->connection );
						wfDebugLog( 'odbc', "Execute failed on source '{$this->sourceId}': $sql — $odbcErr" );
						throw new MWException(
							empty( $params )
								? wfMessage( 'odbc-error-query-failed', $odbcErr )->text()
								: wfMessage( 'odbc-error-execute-failed', $odbcErr )->text()
						);
					}

					// Detect result-set encoding once from the first non-empty string value,
					// then apply it uniformly to all rows (KI-069 / P2-069).
					// O(1) mb_detect_encoding() calls per query instead of O(rows × columns).
					// A per-source 'charset' key in $wgODBCSources overrides auto-detection.
					$resultEncoding = $this->config['charset'] ?? null;
					$encodingDetected = ( $resultEncoding !== null );

					// Fetch results, enforcing row limit.
					$result = [];
					$rowCount = 0;
					while ( ( $row = odbc_fetch_array( $stmt ) ) && $rowCount < $maxRows ) {
						// On the first row, sample encoding from the first non-empty string value.
						if ( !$encodingDetected ) {
							foreach ( $row as $sampleValue ) {
								if ( $sampleValue !== null && is_string( $sampleValue ) && $sampleValue !== '' ) {
									$detected = mb_detect_encoding(
										$sampleValue,
										[ 'UTF-8', 'ISO-8859-1', 'ISO-8859-15', 'Windows-1252', 'ASCII' ],
										true
									);
									if ( $detected ) {
										$resultEncoding = $detected;
									}
									break; // One sample per result set is sufficient.
								}
							}
							$encodingDetected = true; // Do not re-probe on subsequent rows.
						}

						// Convert non-UTF-8 rows in a single pass per row.
						$needsConversion = $resultEncoding !== null
							&& $resultEncoding !== 'UTF-8'
							&& $resultEncoding !== 'ASCII';
						$cleanRow = [];
						foreach ( $row as $key => $value ) {
							if ( $needsConversion && $value !== null && is_string( $value ) ) {
								$value = mb_convert_encoding( $value, 'UTF-8', $resultEncoding );
							}
							$cleanRow[$key] = $value;
						}
						$result[] = $cleanRow;
						$rowCount++;
					}

					odbc_free_result( $stmt );
					$stmt = null;

					$elapsed = round( microtime( true ) - $queryStart, 3 );

					// Store in cache if caching is enabled.
					if ( $cacheKey !== null && $cacheExpiry > 0 ) {
						$cache->set( $cacheKey, $result, $cacheExpiry );
					}

					wfDebugLog( 'odbc', "Query executed on source '{$this->sourceId}': " .
						substr( $sql, 0, 100 ) . ( strlen( $sql ) > 100 ? '...' : '' ) .
						" — Returned $rowCount rows in {$elapsed}s" );

					// Log a warning for slow queries when threshold is configured.
					if ( $slowThreshold > 0 && $elapsed > $slowThreshold ) {
						wfDebugLog( 'odbc-slow', "Slow query ({$elapsed}s > threshold {$slowThreshold}s) on source '{$this->sourceId}': " .
							substr( $sql, 0, 200 ) . ( strlen( $sql ) > 200 ? '...' : '' ) .
							" — $rowCount rows fetched" );
					}

					return $result;
				}
			);
		} catch ( MWException $e ) {
			if ( $stmt ) {
				@odbc_free_result( $stmt );
			}
			throw $e;
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
		// Normalize internal whitespace: collapse tabs, multiple spaces, etc. to a single
		// space so that multi-space or tab-based evasion (e.g. "INTO  OUTFILE", "LOAD\tDATA")
		// is blocked consistently by the multi-word keyword patterns below (KI-049).
		$clean = (string)preg_replace( '/\s+/', ' ', $clean );
		$upper = strtoupper( $clean );

		// Exact-character and short-pattern blocklist (always dangerous in composed SQL).
		// These are matched as plain substrings because they are structural operators,
		// not identifiers — word boundaries do not apply to them.
		// '#' is the MySQL single-line comment character (equivalent to '--').
		//
		// CAST( and CONVERT( are included as defence-in-depth against hex-encoding obfuscation:
		//   CAST(0x44524F50 AS CHAR)          → 'DROP'  (SQL Server / MySQL)
		//   CONVERT(0x44454C455445 USING utf8) → 'DELETE' (MySQL)
		// Note: CONVERT() also appears in legitimate read-only SQL (e.g. CONVERT(price, DECIMAL)).
		// Operators who need CONVERT() in composed queries should use the prepared-statement path.
		// See KI-088 / P2-089 for background. (KI-088)
		$charPatterns = [ ';', '--', '#', '/*', '*/', '<?', 'CHAR(', 'CONCAT(', 'CAST(', 'CONVERT(' ];
		foreach ( $charPatterns as $pattern ) {
			if ( strpos( $upper, strtoupper( $pattern ) ) !== false ) {
				throw new MWException(
					wfMessage( 'odbc-error-illegal-input', $pattern, $context )->text()
				);
			}
		}

		// Keyword patterns — matched with a leading word boundary (\b) to prevent false
		// positives (e.g. DECLARED_AT, GRANTED_BY, EXECUTIVE must not trigger
		// DECLARE/GRANT/EXEC).
		//
		// Trailing word boundary rules (KI-049):
		// - Keywords ending with an alphanumeric character get a trailing \b so they are
		//   matched only as complete words (e.g. \bGRANT\b, \bUNION\b).
		// - Keywords ending with '_' (e.g. XP_, SP_) MUST NOT have a trailing \b because
		//   '_' is a PCRE word character: \bXP_\b would never match XP_cmdshell since
		//   there is no word boundary between '_' and 'c'. These use prefix-only matching
		//   (\bXP_) to block the entire XP_* / SP_* stored-procedure namespace.
		// - Keywords ending with '(' (e.g. SLEEP(, BENCHMARK() MUST NOT have a trailing
		//   \b because '(' is a non-word character and the adjacent \b would require the
		//   very next character to be a word char, causing SLEEP() and SLEEP( 1) to evade.
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
			// Time-delay / blind injection attacks.
			'WAITFOR', 'SLEEP(', 'PG_SLEEP(', 'BENCHMARK(',
			// DDL that may not be caught by the DML list above.
			'DECLARE',
			// Oracle file/network I/O packages.
			'UTL_FILE', 'UTL_HTTP',
			// Set operations — listed here (not in charPatterns) so that word-boundary
			// matching prevents false positives on valid identifiers such as
			// TRADE_UNION, LABOUR_UNION_ID, REUNION, etc. (KI-024 fix).
			'UNION',
		];
		foreach ( $keywords as $keyword ) {
			// Only add a trailing \b when the keyword ends with a plain alphanumeric
			// character (a-z, A-Z, 0-9). Keywords ending with '_' or a non-word char
			// like '(' or '.' use prefix-only or infix matching instead (see above).
			$lastChar = substr( $keyword, -1 );
			$trailingBoundary = ctype_alnum( $lastChar ) ? '\b' : '';
			$pattern = '/\b' . preg_quote( $keyword, '/' ) . $trailingBoundary . '/i';
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
	 * Accepts unqualified names (`table`), two-part names (`schema.table`), and
	 * three-part names (`catalog.schema.table`). Trailing dots, double dots, and
	 * chains deeper than three segments are all rejected (KI-065 / P2-065).
	 *
	 * Promoted to public static so that EDConnectorOdbcGeneric can validate alias
	 * keys before injecting them into SQL (KI-067 / P2-067).
	 *
	 * @param string $identifier The identifier to validate.
	 * @param string $context Context for error messages.
	 * @throws MWException If invalid.
	 */
	public static function validateIdentifier( string $identifier, string $context ): void {
		if ( $identifier === '' || $identifier === '*' ) {
			return; // Allow empty and wildcard.
		}

		// Limit length to prevent abuse.
		if ( strlen( $identifier ) > 128 ) {
			throw new MWException(
				wfMessage( 'odbc-error-identifier-too-long', $context )->text()
			);
		}

		// Allow 1–3 properly-formed dot-separated identifier segments:
		//   table              → valid
		//   schema.table       → valid
		//   catalog.schema.table → valid
		//   table.             → invalid (trailing dot)
		//   table..column      → invalid (double dot)
		//   a.b.c.d.e          → invalid (too deep)
		if ( !preg_match( '/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*){0,2}$/', $identifier ) ) {
			throw new MWException(
				wfMessage( 'odbc-error-invalid-identifier', $identifier, $context )->text()
			);
		}
	}

	/**
	 * Determine the row-limiting SQL syntax required by the ODBC driver.
	 *
	 * Different database engines use different syntax for capping result-set size:
	 * - 'limit'  — Standard SQL: appends "LIMIT n" at the end.
	 *              Used by MySQL, MariaDB, PostgreSQL, SQLite, Oracle 12c+, SAP HANA, etc.
	 * - 'top'    — Inserts "TOP n" after SELECT: "SELECT TOP n col FROM tbl".
	 *              Used by SQL Server, MS Access (Jet/ACE), and Sybase ASE.
	 * - 'first'  — Inserts "FIRST n" after SELECT: "SELECT FIRST n col FROM tbl".
	 *              Used by Progress OpenEdge (all known ODBC driver name variants).
	 *
	 * For DSN-only configurations where no driver name is available the method
	 * defaults to 'limit', which is correct for the majority of modern databases.
	 *
	 * @param array $config The source configuration array.
	 * @return string One of 'limit', 'top', or 'first'.
	 */
	public static function getRowLimitStyle( array $config ): string {
		$driver = strtolower( $config['driver'] ?? '' );
		if ( $driver === '' ) {
			return 'limit'; // DSN-only: default to standard LIMIT syntax.
		}

		// SQL Server, MS Access, and Sybase use "SELECT TOP n".
		if ( strpos( $driver, 'sql server' ) !== false
			|| strpos( $driver, 'sqlserver' ) !== false
			|| strpos( $driver, 'access' ) !== false
			|| strpos( $driver, 'sybase' ) !== false
			|| strpos( $driver, 'adaptive server' ) !== false ) {
			return 'top';
		}

		// Progress OpenEdge uses "SELECT FIRST n".
		// Covers all known driver name variants:
		//   "Progress OpenEdge X.X Driver"
		//   "DataDirect X.X Progress OpenEdge Wire Protocol"
		if ( strpos( $driver, 'progress' ) !== false
			|| strpos( $driver, 'openedge' ) !== false ) {
			return 'first';
		}

		return 'limit';
	}

	/**
	 * Determine whether the ODBC driver uses TOP n syntax (SQL Server / Access / Sybase)
	 * rather than the standard LIMIT n syntax.
	 *
	 * @deprecated since 1.1.0 — Use getRowLimitStyle() which also handles Progress OpenEdge
	 *   FIRST n syntax. This method returns false for Progress drivers even though they do
	 *   not use LIMIT either.
	 * @param array $config The source configuration array.
	 * @return bool True if the driver uses TOP n; false otherwise.
	 */
	public static function requiresTopSyntax( array $config ): bool {
		wfDeprecated( __METHOD__, '1.1.0', 'ODBC' );
		return self::getRowLimitStyle( $config ) === 'top';
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
	 * Get column metadata from table schema information.
	 *
	 * Returns an array of column info arrays, each with keys:
	 *   'name'     — column name (string)
	 *   'type'     — SQL type name (string, e.g. 'VARCHAR', 'INT')
	 *   'size'     — column size/precision (int)
	 *   'nullable' — 'YES', 'NO', or '' if unknown
	 *
	 * @param string $tableName The table name.
	 * @return array[] Array of column info arrays, or empty on error (check logs).
	 */
	public function getTableColumns( string $tableName ): array {
		try {
			return ODBCConnectionManager::withOdbcWarnings( function () use ( $tableName ) {
				$columns = odbc_columns( $this->connection, '', '', $tableName );
				if ( !$columns ) {
					wfDebugLog( 'odbc', "Failed to get columns for table '$tableName' on source '{$this->sourceId}'" );
					return [];
				}
				$result = [];
				while ( $row = odbc_fetch_array( $columns ) ) {
					// Use a case-insensitive lookup — ODBC drivers return keys in varying cases.
					$rowLower = array_change_key_case( $row, CASE_LOWER );
					$name = (string)( $rowLower['column_name'] ?? '' );
					if ( $name === '' ) {
						continue;
					}
					$result[] = [
						'name'     => $name,
						'type'     => (string)( $rowLower['type_name'] ?? '' ),
						'size'     => (int)( $rowLower['column_size'] ?? 0 ),
						'nullable' => (string)( $rowLower['is_nullable'] ?? ( isset( $rowLower['nullable'] ) ? ( $rowLower['nullable'] ? 'YES' : 'NO' ) : '' ) ),
					];
				}
				odbc_free_result( $columns );
				return $result;
			} );
		} catch ( MWException $e ) {
			wfDebugLog( 'odbc', "Exception getting columns for table '$tableName': " . $e->getMessage() );
			return [];
		}
	}

	/**
	 * Get list of tables accessible through this connection.
	 *
	 * @return array Table name list, or empty array on error (check logs).
	 */
	public function getTables(): array {
		try {
			return ODBCConnectionManager::withOdbcWarnings( function () {
				$tables = odbc_tables( $this->connection );
				if ( !$tables ) {
					wfDebugLog( 'odbc', "Failed to get tables on source '{$this->sourceId}'" );
					return [];
				}
				$result = [];
				while ( $row = odbc_fetch_array( $tables ) ) {
					$rowLower = array_change_key_case( $row, CASE_LOWER );
					$type = (string)( $rowLower['table_type'] ?? '' );
					if ( $type === 'TABLE' || $type === 'VIEW' ) {
						$name = (string)( $rowLower['table_name'] ?? '' );
						if ( $name !== '' ) {
							$result[] = $name;
						}
					}
				}
				odbc_free_result( $tables );
				return $result;
			} );
		} catch ( MWException $e ) {
			wfDebugLog( 'odbc', "Exception getting tables on source '{$this->sourceId}': " . $e->getMessage() );
			return [];
		}
	}
}
