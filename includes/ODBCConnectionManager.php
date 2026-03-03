<?php
/**
 * ODBC Connection Manager for MediaWiki ODBC Extension.
 *
 * Manages ODBC data source connections configured in $wgODBCSources.
 * Handles connection pooling, DSN construction, and connection lifecycle.
 *
 * @file
 * @ingroup Extensions
 * @license GPL-2.0-or-later
 */

use MediaWiki\MediaWikiServices;

class ODBCConnectionManager {

	/** @var array Cache of active ODBC connection resources keyed by source ID. */
	private static $connections = [];

	/** @var array Last-used timestamp (microtime) for each pooled connection, keyed by source ID.
	 *              Used by the LRU eviction policy to discard the least-recently-used connection
	 *              when the pool is full, rather than the oldest-opened connection (FIFO). */
	private static array $lastUsed = [];

	/** @var int Fallback maximum number of cached connections (overridden by $wgODBCMaxConnections). */
	private const MAX_CONNECTIONS_DEFAULT = 10;

	/**
	 * Get the full configuration array for all ODBC sources.
	 *
	 * @return array Associative array of source configurations.
	 */
	public static function getSources(): array {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		return $config->get( 'ODBCSources' );
	}

	/**
	 * Get the configuration for a specific ODBC source.
	 *
	 * @param string $sourceId The source identifier.
	 * @return array|null The source configuration or null if not found.
	 */
	public static function getSourceConfig( string $sourceId ): ?array {
		$sources = self::getSources();
		return $sources[$sourceId] ?? null;
	}

	/**
	 * Build the ODBC DSN (Data Source Name) connection string from configuration.
	 *
	 * Supports three modes:
	 * 1. Direct DSN string: 'dsn' => 'DSN=MyDataSource' or 'dsn' => 'MySystemDSN'
	 * 2. Driver-based connection string: 'driver' + 'server' + 'database' etc.
	 * 3. Full connection string: 'connection_string' => 'Driver={...};Server=...;...'
	 *
	 * Progress OpenEdge note: use 'host' instead of 'server', and 'db' instead of
	 * 'database'. Both are accepted and map to the correct ODBC key names (Host= / DB=).
	 *
	 * @param array $config The source configuration array.
	 * @return string The ODBC connection string.
	 */
	public static function buildConnectionString( array $config ): string {
		// If a full connection string is provided, use it directly.
		if ( !empty( $config['connection_string'] ) ) {
			return $config['connection_string'];
		}

		// If a simple DSN name is provided (System/User DSN configured in ODBC admin).
		if ( !empty( $config['dsn'] ) && empty( $config['driver'] ) ) {
			return $config['dsn'];
		}

		// Build a driver-based connection string.
		$parts = [];

		if ( !empty( $config['driver'] ) ) {
			// Wrap driver name in {} per ODBC spec; double any } inside the value.
			$parts[] = 'Driver={' . str_replace( '}', '}}', $config['driver'] ) . '}';
		}

		// 'server' key → "Server=" (SQL Server, MySQL, PostgreSQL, etc.)
		// 'host' key   → "Host=" (Progress OpenEdge convention)
		if ( !empty( $config['host'] ) ) {
			$parts[] = 'Host=' . self::escapeConnectionStringValue( $config['host'] );
		} elseif ( !empty( $config['server'] ) ) {
			$parts[] = 'Server=' . self::escapeConnectionStringValue( $config['server'] );
		}

		// 'database' key → "Database=" (most drivers)
		// 'db' key       → "DB=" (Progress OpenEdge convention)
		if ( !empty( $config['database'] ) ) {
			$parts[] = 'Database=' . self::escapeConnectionStringValue( $config['database'] );
		} elseif ( !empty( $config['db'] ) ) {
			$parts[] = 'DB=' . self::escapeConnectionStringValue( $config['db'] );
		}

		if ( !empty( $config['port'] ) ) {
			$parts[] = 'Port=' . self::escapeConnectionStringValue( (string)$config['port'] );
		}

		// Trust server certificate (useful for SQL Server with self-signed certs).
		// Accept both 'trust_certificate' (native config) and
		// 'trust server certificate' (External Data convention).
		if ( !empty( $config['trust_certificate'] ) || !empty( $config['trust server certificate'] ) ) {
			$parts[] = 'TrustServerCertificate=yes';
		}

		// Any extra DSN parameters — values are escaped to prevent injection.
		if ( !empty( $config['dsn_params'] ) && is_array( $config['dsn_params'] ) ) {
			foreach ( $config['dsn_params'] as $key => $value ) {
				$parts[] = $key . '=' . self::escapeConnectionStringValue( (string)$value );
			}
		}

		return implode( ';', $parts );
	}

	/**
	 * Escape a value for safe inclusion in an ODBC connection string attribute.
	 *
	 * Per ODBC specification: if a value contains a semicolon, left brace, or right brace
	 * it must be enclosed in curly braces. Any right brace within the braced value must
	 * be doubled (}} represents a literal }).
	 *
	 * @param string $value The raw attribute value.
	 * @return string The escaped value, ready for "Key=value" insertion.
	 */
	private static function escapeConnectionStringValue( string $value ): string {
		if ( strpbrk( $value, ';{}' ) !== false ) {
			return '{' . str_replace( '}', '}}', $value ) . '}';
		}
		return $value;
	}

	/**
	 * Open a connection to the specified ODBC source.
	 *
	 * @param string $sourceId The source identifier.
	 * @return resource|object The ODBC connection resource.
	 * @throws MWException If the source is not configured or connection fails.
	 */
	public static function connect( string $sourceId ) {
		// Retrieve and validate configuration upfront — before the pool check — so that:
		// (a) we can pass the driver name to pingConnection() for driver-aware liveness probes,
		// (b) mis-configured sources surface as a clear message rather than a cryptic ODBC error.
		$config = self::getSourceConfig( $sourceId );
		if ( $config === null ) {
			throw new MWException(
				wfMessage( 'odbc-error-unknown-source', $sourceId )->text()
			);
		}
		$configErrors = self::validateConfig( $config );
		if ( !empty( $configErrors ) ) {
			throw new MWException(
				wfMessage( 'odbc-error-config-invalid', $sourceId, implode( ', ', $configErrors ) )->text()
			);
		}

		// Return cached connection if still alive — use a driver-aware liveness probe
		// so MS Access gets the correct ping query instead of bare 'SELECT 1' (KI-023 fix).
		if ( isset( self::$connections[$sourceId] ) ) {
			if ( self::pingConnection( self::$connections[$sourceId], $config ) ) {
				// Update last-used timestamp so LRU eviction accounts for recent access.
				self::$lastUsed[$sourceId] = microtime( true );
				return self::$connections[$sourceId];
			}
			// Connection is dead; discard and open a fresh one.
			self::disconnect( $sourceId );
			wfDebugLog( 'odbc', "Stale connection detected for source '$sourceId'; reconnecting." );
		}

		// Enforce connection pool size limit.
		$globalConfig = MediaWikiServices::getInstance()->getMainConfig();
		$maxConns = $globalConfig->get( 'ODBCMaxConnections' );
		if ( count( self::$connections ) >= $maxConns ) {
			// Evict the least-recently-used connection (LRU) instead of the oldest-opened
			// connection (FIFO), so high-traffic sources are retained over idle ones (P2-024).
			asort( self::$lastUsed );
			$lruKey = array_key_first( self::$lastUsed );
			if ( $lruKey !== null ) {
				wfDebugLog( 'odbc', "Pool full — evicting LRU connection for source '$lruKey'." );
				self::disconnect( $lruKey );
			}
		}

		$dsn = self::buildConnectionString( $config );
		$user = $config['user'] ?? '';
		$password = $config['password'] ?? '';

		// Set up error handling: convert warnings to exceptions.
		try {
			// Use standard (non-persistent) connections for proper lifecycle management.
			// Persistent connections (odbc_pconnect) conflict with explicit disconnect()
			// calls and can cause stale connection issues.
			$conn = self::withOdbcWarnings(
				static fn() => odbc_connect( $dsn, $user, $password )
			);
		} catch ( MWException $e ) {
			// Sanitize error message to avoid exposing credentials.
			$sanitizedMsg = self::sanitizeErrorMessage( $e->getMessage() );
			throw new MWException(
				wfMessage( 'odbc-error-connect-failed', $sourceId, $sanitizedMsg )->text()
			);
		}

		if ( !$conn ) {
			$sanitizedMsg = self::sanitizeErrorMessage( odbc_errormsg() );
			throw new MWException(
				wfMessage( 'odbc-error-connect-failed', $sourceId, $sanitizedMsg )->text()
			);
		}

		// Note: query timeout is applied per-statement in ODBCQueryRunner::executeRawQuery()
		// using odbc_setoption() on the statement handle (SQL_HANDLE_STMT), which is the
		// ODBC-standard approach and better-supported by drivers than connection-level setting.

		self::$connections[$sourceId] = $conn;
		self::$lastUsed[$sourceId] = microtime( true );
		return $conn;
	}

	/**
	 * Disconnect a specific ODBC source.
	 *
	 * @param string $sourceId The source identifier.
	 */
	public static function disconnect( string $sourceId ): void {
		if ( isset( self::$connections[$sourceId] ) ) {
			odbc_close( self::$connections[$sourceId] );
			unset( self::$connections[$sourceId], self::$lastUsed[$sourceId] );
		}
	}

	/**
	 * Disconnect all active ODBC connections.
	 */
	public static function disconnectAll(): void {
		foreach ( self::$connections as $sourceId => $conn ) {
			odbc_close( $conn );
		}
		self::$connections = [];
		self::$lastUsed = [];
	}

	/**
	 * Test connectivity for a given source.
	 *
	 * Opens a separate test connection without disturbing any cached connection.
	 *
	 * @param string $sourceId The source identifier.
	 * @return array [ 'success' => bool, 'message' => string ]
	 */
	public static function testConnection( string $sourceId ): array {
		try {
			$config = self::getSourceConfig( $sourceId );
			if ( $config === null ) {
				throw new MWException(
					wfMessage( 'odbc-error-unknown-source', $sourceId )->text()
				);
			}

			$dsn = self::buildConnectionString( $config );
			$user = $config['user'] ?? '';
			$password = $config['password'] ?? '';

			$testConn = self::withOdbcWarnings(
				static fn() => odbc_connect( $dsn, $user, $password )
			);

			if ( !$testConn ) {
				throw new MWException(
					wfMessage( 'odbc-error-connect-failed', $sourceId,
						self::sanitizeErrorMessage( odbc_errormsg() ) )->text()
				);
			}

			odbc_close( $testConn );

			return [
				'success' => true,
				'message' => wfMessage( 'odbc-test-success', $sourceId )->text()
			];
		} catch ( MWException $e ) {
			return [
				'success' => false,
				'message' => $e->getMessage()
			];
		}
	}

	/**
	 * Verify that a connection handle is alive by executing a minimal probe query.
	 *
	 * Checking `odbc_error()` is not a reliable liveness test — it reflects the
	 * last recorded error, not the current connection state. A proper ping is the
	 * only way to confirm the connection still works.
	 *
	 * The probe query is driver-aware:
	 * - MS Access (Jet/ACE) does not support bare 'SELECT 1' without a FROM clause;
	 *   a system table that is always present in Access databases is used instead.
	 * - All other drivers use the standard 'SELECT 1'.
	 *
	 * @param resource|object $conn The ODBC connection handle.
	 * @param array $config The source configuration array (used to detect the driver).
	 * @return bool True if the connection is alive.
	 */
	private static function pingConnection( $conn, array $config = [] ): bool {
		$driver = strtolower( $config['driver'] ?? '' );

		// MS Access (Jet/ACE) does not support 'SELECT 1' without a FROM clause.
		// MSysObjects is an internal system table present in every Access database.
		if ( strpos( $driver, 'access' ) !== false ) {
			$probe = 'SELECT 1 FROM MSysObjects WHERE 1=0';
		} else {
			$probe = 'SELECT 1';
		}

		// Use withOdbcWarnings() for consistency with other ODBC calls (P2-046).
		// Any PHP E_WARNING from the driver is converted to MWException; we catch it
		// and return false rather than letting the exception propagate.
		try {
			$result = self::withOdbcWarnings( static fn() => odbc_exec( $conn, $probe ) );
			if ( $result !== false ) {
				odbc_free_result( $result );
				return true;
			}
			return false;
		} catch ( MWException $e ) {
			return false;
		}
	}

	/**
	 * Get list of configured source IDs.
	 *
	 * @return string[]
	 */
	public static function getSourceIds(): array {
		return array_keys( self::getSources() );
	}

	/**
	 * Validate that a source configuration has required fields.
	 *
	 * @param array $config The source configuration.
	 * @return array List of missing/invalid field names. Empty if valid.
	 */
	public static function validateConfig( array $config ): array {
		$errors = [];

		// Must have at least one way to connect.
		$hasDsn = !empty( $config['dsn'] );
		$hasDriver = !empty( $config['driver'] );
		$hasConnectionString = !empty( $config['connection_string'] );

		if ( !$hasDsn && !$hasDriver && !$hasConnectionString ) {
			$errors[] = 'dsn, driver, or connection_string';
		}

		// If using driver mode, a host/server name or a DSN is required.
		// Progress OpenEdge configs use 'host' (→ Host=) instead of 'server' (→ Server=),
		// so accept either key (KI-040).
		if ( $hasDriver && empty( $config['server'] ) && empty( $config['host'] ) && empty( $config['dsn'] ) ) {
			$errors[] = 'server or host (required when using driver mode)';
		}

		return $errors;
	}

	/**
	 * Execute a callable with ODBC-originated PHP E_WARNING errors converted to MWException.
	 *
	 * Centralises the repeated set_error_handler / restore_error_handler pattern used
	 * around ODBC calls that emit PHP warnings on failure (P2-008).
	 * The original error handler is always restored, even if an exception is thrown.
	 *
	 * Only warnings whose message contains an ODBC driver signature are intercepted.
	 * Non-ODBC warnings (e.g. from filesystem or network calls made inside the callback)
	 * are passed through to the next registered handler, preventing misleading
	 * MWException messages from unrelated PHP warnings (KI-066 / P2-066).
	 *
	 * Public to allow ODBCQueryRunner and EDConnectorOdbcGeneric to share this helper
	 * instead of duplicating the set_error_handler pattern (P2-051).
	 *
	 * @param callable $callback The ODBC call to wrap.
	 * @return mixed The return value of the callable.
	 * @throws MWException If the callable triggers an ODBC-related E_WARNING.
	 */
	public static function withOdbcWarnings( callable $callback ) {
		set_error_handler( static function ( int $errno, string $errstr ): bool {
			// Only intercept warnings that originate from the ODBC driver or the PHP
			// odbc_* extension. Non-ODBC warnings are passed to the next handler by
			// returning false, preventing them from becoming misleading MWExceptions.
			//
			// Vendor-prefix strings covered (KI-089 / P2-090):
			//   'odbc'          — PHP odbc_* function messages, generic driver text
			//   '[unixODBC]'    — unixODBC driver manager (Linux)
			//   '[Microsoft]'   — Microsoft ODBC Driver for SQL Server
			//   '[IBM]'         — IBM DB2 ODBC driver
			//   '[Oracle]'      — Oracle Instant Client ODBC driver
			//   '[Progress]'    — Progress OpenEdge ODBC driver (short prefix)
			//   '[OpenEdge]'    — Progress OpenEdge ODBC driver (long prefix)
			//   '[DataDirect]'  — Progress DataDirect ODBC drivers
			//   '[Easysoft]'    — Easysoft ODBC Bridge / ODBC-ODBC Bridge
			$odbcVendorPrefixes = [
				'odbc', '[unixODBC]', '[Microsoft]', '[IBM]', '[Oracle]',
				'[Progress]', '[OpenEdge]', '[DataDirect]', '[Easysoft]',
			];
			$isOdbcWarning = false;
			foreach ( $odbcVendorPrefixes as $prefix ) {
				if ( stripos( $errstr, $prefix ) !== false ) {
					$isOdbcWarning = true;
					break;
				}
			}
			if ( !$isOdbcWarning ) {
				return false; // Defer to next registered error handler.
			}
			throw new MWException( $errstr );
		}, E_WARNING );
		try {
			return $callback();
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Sanitize error messages to prevent credential exposure.
	 *
	 * @param string $message The error message.
	 * @return string Sanitized message.
	 */
	private static function sanitizeErrorMessage( string $message ): string {
		// Remove potential password patterns from connection strings.
		$message = preg_replace( '/(?:PWD|Password)\s*=\s*[^;]+/i', 'PWD=***', $message );
		// Remove UID patterns that might contain sensitive usernames in some contexts.
		$message = preg_replace( '/(?:UID)\s*=\s*([^;]+)/i', 'UID=***', $message );
		return $message;
	}
}
