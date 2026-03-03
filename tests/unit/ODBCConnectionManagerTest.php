<?php
/**
 * Unit tests for ODBCConnectionManager.
 *
 * Tests the pure-logic static methods that do not require a live ODBC
 * connection or a full MediaWiki installation.
 *
 * @covers ODBCConnectionManager
 * @license GPL-2.0-or-later
 */

use PHPUnit\Framework\TestCase;

class ODBCConnectionManagerTest extends TestCase {

	// ── buildConnectionString() ─────────────────────────────────────────────

	/**
	 * @covers ODBCConnectionManager::buildConnectionString
	 */
	public function testBuildConnectionStringWithFullConnectionString(): void {
		$config = [
			'connection_string' => 'Driver={SQL Server};Server=localhost;Database=TestDB',
		];
		$this->assertSame(
			'Driver={SQL Server};Server=localhost;Database=TestDB',
			ODBCConnectionManager::buildConnectionString( $config )
		);
	}

	/**
	 * @covers ODBCConnectionManager::buildConnectionString
	 */
	public function testBuildConnectionStringWithSimpleDsn(): void {
		$config = [
			'dsn' => 'MySystemDSN',
		];
		$this->assertSame(
			'MySystemDSN',
			ODBCConnectionManager::buildConnectionString( $config )
		);
	}

	/**
	 * @covers ODBCConnectionManager::buildConnectionString
	 */
	public function testBuildConnectionStringWithDsnAndDriver(): void {
		// When both 'dsn' and 'driver' are set, it builds a driver-based string
		// (the 'dsn' key is ignored in favour of the driver-based builder).
		$config = [
			'dsn'    => 'ignoredValue',
			'driver' => 'ODBC Driver 17 for SQL Server',
			'server' => 'myserver',
		];
		$result = ODBCConnectionManager::buildConnectionString( $config );
		$this->assertStringContainsString( 'Driver={ODBC Driver 17 for SQL Server}', $result );
		$this->assertStringContainsString( 'Server=myserver', $result );
	}

	/**
	 * @covers ODBCConnectionManager::buildConnectionString
	 */
	public function testBuildConnectionStringDriverMode(): void {
		$config = [
			'driver'   => 'ODBC Driver 17 for SQL Server',
			'server'   => 'localhost,1433',
			'database' => 'MyDB',
		];
		$result = ODBCConnectionManager::buildConnectionString( $config );
		$this->assertSame(
			'Driver={ODBC Driver 17 for SQL Server};Server=localhost,1433;Database=MyDB',
			$result
		);
	}

	/**
	 * @covers ODBCConnectionManager::buildConnectionString
	 */
	public function testBuildConnectionStringProgressOpenEdge(): void {
		// Progress OpenEdge uses 'host' instead of 'server', 'db' instead of 'database'.
		$config = [
			'driver' => 'Progress OpenEdge 12.2 Driver',
			'host'   => 'oe-server',
			'db'     => 'sports2000',
			'port'   => '4322',
		];
		$result = ODBCConnectionManager::buildConnectionString( $config );
		$this->assertStringContainsString( 'Driver={Progress OpenEdge 12.2 Driver}', $result );
		$this->assertStringContainsString( 'Host=oe-server', $result );
		$this->assertStringContainsString( 'DB=sports2000', $result );
		$this->assertStringContainsString( 'Port=4322', $result );
	}

	/**
	 * @covers ODBCConnectionManager::buildConnectionString
	 */
	public function testBuildConnectionStringWithTrustCertificate(): void {
		$config = [
			'driver'            => 'ODBC Driver 18 for SQL Server',
			'server'            => 'localhost',
			'database'          => 'TestDB',
			'trust_certificate' => true,
		];
		$result = ODBCConnectionManager::buildConnectionString( $config );
		$this->assertStringContainsString( 'TrustServerCertificate=yes', $result );
	}

	/**
	 * @covers ODBCConnectionManager::buildConnectionString
	 */
	public function testBuildConnectionStringWithExternalDataTrustKey(): void {
		$config = [
			'driver'                   => 'ODBC Driver 18 for SQL Server',
			'server'                   => 'localhost',
			'trust server certificate' => true,
		];
		$result = ODBCConnectionManager::buildConnectionString( $config );
		$this->assertStringContainsString( 'TrustServerCertificate=yes', $result );
	}

	/**
	 * @covers ODBCConnectionManager::buildConnectionString
	 */
	public function testBuildConnectionStringWithDsnParams(): void {
		$config = [
			'driver'     => 'MySQL ODBC 8.0 Driver',
			'server'     => 'dbhost',
			'database'   => 'mydb',
			'dsn_params' => [
				'CHARSET' => 'utf8mb4',
				'OPTION'  => '3',
			],
		];
		$result = ODBCConnectionManager::buildConnectionString( $config );
		$this->assertStringContainsString( 'CHARSET=utf8mb4', $result );
		$this->assertStringContainsString( 'OPTION=3', $result );
	}

	/**
	 * @covers ODBCConnectionManager::buildConnectionString
	 */
	public function testBuildConnectionStringEscapesSemicolonsInValues(): void {
		$config = [
			'driver'   => 'SQL Server',
			'server'   => 'host;with;semis',
			'database' => 'TestDB',
		];
		$result = ODBCConnectionManager::buildConnectionString( $config );
		// Values with semicolons should be brace-wrapped.
		$this->assertStringContainsString( 'Server={host;with;semis}', $result );
	}

	/**
	 * @covers ODBCConnectionManager::buildConnectionString
	 */
	public function testBuildConnectionStringEscapesBracesInDriver(): void {
		// A '}' inside the driver name should be doubled per ODBC spec.
		$config = [
			'driver' => 'Test}Driver',
			'server' => 'localhost',
		];
		$result = ODBCConnectionManager::buildConnectionString( $config );
		$this->assertStringContainsString( 'Driver={Test}}Driver}', $result );
	}

	/**
	 * @covers ODBCConnectionManager::buildConnectionString
	 */
	public function testBuildConnectionStringEmptyConfig(): void {
		$this->assertSame( '', ODBCConnectionManager::buildConnectionString( [] ) );
	}

	/**
	 * @covers ODBCConnectionManager::buildConnectionString
	 */
	public function testBuildConnectionStringPreferHostOverServer(): void {
		// When both 'host' and 'server' are set, 'host' wins (checked first).
		$config = [
			'driver' => 'SomeDriver',
			'host'   => 'priority-host',
			'server' => 'fallback-server',
		];
		$result = ODBCConnectionManager::buildConnectionString( $config );
		$this->assertStringContainsString( 'Host=priority-host', $result );
		$this->assertStringNotContainsString( 'Server=', $result );
	}

	/**
	 * @covers ODBCConnectionManager::buildConnectionString
	 */
	public function testBuildConnectionStringPreferDatabaseOverDb(): void {
		// When both 'database' and 'db' are set, 'database' wins (checked first).
		$config = [
			'driver'   => 'SomeDriver',
			'server'   => 'localhost',
			'database' => 'priority-db',
			'db'       => 'fallback-db',
		];
		$result = ODBCConnectionManager::buildConnectionString( $config );
		$this->assertStringContainsString( 'Database=priority-db', $result );
		$this->assertStringNotContainsString( 'DB=', $result );
	}

	// ── validateConfig() ────────────────────────────────────────────────────

	/**
	 * @covers ODBCConnectionManager::validateConfig
	 */
	public function testValidateConfigWithDsn(): void {
		$errors = ODBCConnectionManager::validateConfig( [ 'dsn' => 'MyDSN' ] );
		$this->assertSame( [], $errors );
	}

	/**
	 * @covers ODBCConnectionManager::validateConfig
	 */
	public function testValidateConfigWithConnectionString(): void {
		$errors = ODBCConnectionManager::validateConfig( [
			'connection_string' => 'Driver={SQL Server};Server=x',
		] );
		$this->assertSame( [], $errors );
	}

	/**
	 * @covers ODBCConnectionManager::validateConfig
	 */
	public function testValidateConfigDriverWithServer(): void {
		$errors = ODBCConnectionManager::validateConfig( [
			'driver' => 'ODBC Driver 17 for SQL Server',
			'server' => 'localhost',
		] );
		$this->assertSame( [], $errors );
	}

	/**
	 * @covers ODBCConnectionManager::validateConfig
	 */
	public function testValidateConfigDriverWithHost(): void {
		$errors = ODBCConnectionManager::validateConfig( [
			'driver' => 'Progress OpenEdge',
			'host'   => 'oe-server',
		] );
		$this->assertSame( [], $errors );
	}

	/**
	 * @covers ODBCConnectionManager::validateConfig
	 */
	public function testValidateConfigEmptyConfigReturnsErrors(): void {
		$errors = ODBCConnectionManager::validateConfig( [] );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'dsn', $errors[0] );
	}

	/**
	 * @covers ODBCConnectionManager::validateConfig
	 */
	public function testValidateConfigDriverWithoutServerReturnsError(): void {
		$errors = ODBCConnectionManager::validateConfig( [
			'driver' => 'SQL Server',
		] );
		$this->assertNotEmpty( $errors );
		// Should have both a "server or host" error.
		$foundServerError = false;
		foreach ( $errors as $error ) {
			if ( stripos( $error, 'server' ) !== false ) {
				$foundServerError = true;
			}
		}
		$this->assertTrue( $foundServerError, 'Expected an error about missing server/host' );
	}

	/**
	 * @covers ODBCConnectionManager::validateConfig
	 */
	public function testValidateConfigDriverWithDsnNoServerAccepted(): void {
		// driver + dsn (but no server) is valid — the driver receives the DSN directly.
		$errors = ODBCConnectionManager::validateConfig( [
			'driver' => 'SQL Server',
			'dsn'    => 'MyDSN',
		] );
		$this->assertSame( [], $errors );
	}
}
