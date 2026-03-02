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

	/** @var int Maximum number of cached connections. */
	private const MAX_CONNECTIONS = 10;

	/** @var int SQL_HANDLE_DBC handle type for odbc_setoption() — specifies a connection handle. */
	private const SQL_HANDLE_DBC = 2;

	/** @var int SQL_ATTR_QUERY_TIMEOUT ODBC connection attribute for query timeout (option 0 = SQL_ATTR_QUERY_TIMEOUT). */
	private const SQL_ATTR_QUERY_TIMEOUT = 0;

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
			$parts[] = 'Driver={' . $config['driver'] . '}';
		}

		if ( !empty( $config['server'] ) ) {
			$parts[] = 'Server=' . $config['server'];
		}

		if ( !empty( $config['database'] ) ) {
			$parts[] = 'Database=' . $config['database'];
		}

		if ( !empty( $config['port'] ) ) {
			$parts[] = 'Port=' . $config['port'];
		}

		// Trust server certificate (useful for SQL Server with self-signed certs).
		if ( !empty( $config['trust_certificate'] ) ) {
			$parts[] = 'TrustServerCertificate=yes';
		}

		// Any extra DSN parameters.
		if ( !empty( $config['dsn_params'] ) && is_array( $config['dsn_params'] ) ) {
			foreach ( $config['dsn_params'] as $key => $value ) {
				$parts[] = $key . '=' . $value;
			}
		}

		return implode( ';', $parts );
	}

	/**
	 * Open a connection to the specified ODBC source.
	 *
	 * @param string $sourceId The source identifier.
	 * @return resource|object The ODBC connection resource.
	 * @throws MWException If the source is not configured or connection fails.
	 */
	public static function connect( string $sourceId ) {
		// Return cached connection if still valid.
		if ( isset( self::$connections[$sourceId] ) ) {
			// Check if connection is still alive.
			if ( @odbc_error( self::$connections[$sourceId] ) === '' ) {
				return self::$connections[$sourceId];
			}
			// Connection is dead, remove it.
			unset( self::$connections[$sourceId] );
		}

		// Enforce connection pool size limit.
		if ( count( self::$connections ) >= self::MAX_CONNECTIONS ) {
			// Close the oldest connection.
			$firstKey = array_key_first( self::$connections );
			if ( $firstKey !== null ) {
				self::disconnect( $firstKey );
			}
		}

		$config = self::getSourceConfig( $sourceId );
		if ( $config === null ) {
			throw new MWException(
				wfMessage( 'odbc-error-unknown-source', $sourceId )->text()
			);
		}

		$dsn = self::buildConnectionString( $config );
		$user = $config['user'] ?? '';
		$password = $config['password'] ?? '';

		// Set up error handling: convert warnings to exceptions.
		set_error_handler( static function ( $errno, $errstr ) {
			throw new MWException( $errstr );
		}, E_WARNING );

		try {
			// Use standard (non-persistent) connections for proper lifecycle management.
			// Persistent connections (odbc_pconnect) conflict with explicit disconnect()
			// calls and can cause stale connection issues.
			$conn = odbc_connect( $dsn, $user, $password );
		} catch ( MWException $e ) {
			// Sanitize error message to avoid exposing credentials.
			$sanitizedMsg = self::sanitizeErrorMessage( $e->getMessage() );
			throw new MWException(
				wfMessage( 'odbc-error-connect-failed', $sourceId, $sanitizedMsg )->text()
			);
		} finally {
			restore_error_handler();
		}

		if ( !$conn ) {
			$sanitizedMsg = self::sanitizeErrorMessage( odbc_errormsg() );
			throw new MWException(
				wfMessage( 'odbc-error-connect-failed', $sourceId, $sanitizedMsg )->text()
			);
		}

		// Apply query timeout if configured.
		$globalConfig = MediaWikiServices::getInstance()->getMainConfig();
		$timeout = $config['timeout'] ?? $globalConfig->get( 'ODBCQueryTimeout' );
		if ( $timeout > 0 ) {
			// Set SQL_ATTR_QUERY_TIMEOUT on the connection.
			// This is driver-dependent; not all ODBC drivers support it.
			$result = @odbc_setoption( $conn, self::SQL_HANDLE_DBC,
				self::SQL_ATTR_QUERY_TIMEOUT, (int)$timeout );
			if ( !$result ) {
				// Log warning but don't fail — timeout is best-effort.
				wfDebugLog( 'odbc', "Failed to set query timeout for source '$sourceId'. Driver may not support timeouts." );
			}
		}

		self::$connections[$sourceId] = $conn;
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
			unset( self::$connections[$sourceId] );
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

			set_error_handler( static function ( $errno, $errstr ) {
				throw new MWException( $errstr );
			}, E_WARNING );

			try {
				$testConn = odbc_connect( $dsn, $user, $password );
			} finally {
				restore_error_handler();
			}

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

		// If using driver mode, server is typically needed.
		if ( $hasDriver && empty( $config['server'] ) && empty( $config['dsn'] ) ) {
			$errors[] = 'server (required when using driver mode)';
		}

		return $errors;
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
