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

	// ── buildConnectionString() — additional edge cases ─────────────────────

	/**
	 * @covers ODBCConnectionManager::buildConnectionString
	 */
	public function testBuildConnectionStringWithPortOnly(): void {
		$config = [
			'driver' => 'MySQL ODBC 8.0 Driver',
			'server' => 'localhost',
			'port'   => 3307,
		];
		$result = ODBCConnectionManager::buildConnectionString( $config );
		$this->assertStringContainsString( 'Port=3307', $result );
	}

	/**
	 * @covers ODBCConnectionManager::buildConnectionString
	 */
	public function testBuildConnectionStringDoesNotIncludeUserPassword(): void {
		// buildConnectionString() should NOT embed credentials — those are passed separately.
		$config = [
			'driver'   => 'SQL Server',
			'server'   => 'localhost',
			'user'     => 'sa',
			'password' => 'secret',
		];
		$result = ODBCConnectionManager::buildConnectionString( $config );
		$this->assertStringNotContainsString( 'secret', $result );
		$this->assertStringNotContainsString( 'sa', $result );
	}

	/**
	 * @covers ODBCConnectionManager::buildConnectionString
	 */
	public function testBuildConnectionStringBraceWrapsValuesWithBraces(): void {
		$config = [
			'driver' => 'SQL Server',
			'server' => 'host{weird}name',
		];
		$result = ODBCConnectionManager::buildConnectionString( $config );
		// Braces in server value should be wrapped.
		$this->assertStringContainsString( 'Server={host{weird}}name}', $result );
	}

	/**
	 * @covers ODBCConnectionManager::buildConnectionString
	 */
	public function testBuildConnectionStringConnectionStringPreferred(): void {
		// When both connection_string and driver are set, connection_string wins.
		$config = [
			'connection_string' => 'Driver={X};Server=direct',
			'driver'            => 'OtherDriver',
			'server'            => 'other-host',
		];
		$this->assertSame( 'Driver={X};Server=direct',
			ODBCConnectionManager::buildConnectionString( $config ) );
	}

	/**
	 * @covers ODBCConnectionManager::buildConnectionString
	 */
	public function testBuildConnectionStringDsnParamsOverwrite(): void {
		// dsn_params can add arbitrary key-value pairs.
		$config = [
			'driver'     => 'PostgreSQL Unicode',
			'server'     => 'pghost',
			'database'   => 'mydb',
			'dsn_params' => [
				'SSLMode' => 'require',
			],
		];
		$result = ODBCConnectionManager::buildConnectionString( $config );
		$this->assertStringContainsString( 'SSLMode=require', $result );
	}

	/**
	 * @covers ODBCConnectionManager::buildConnectionString
	 */
	public function testBuildConnectionStringTrustCertificateFalseNotIncluded(): void {
		$config = [
			'driver'            => 'ODBC Driver 18 for SQL Server',
			'server'            => 'localhost',
			'trust_certificate' => false,
		];
		$result = ODBCConnectionManager::buildConnectionString( $config );
		$this->assertStringNotContainsString( 'TrustServerCertificate', $result );
	}

	/**
	 * @covers ODBCConnectionManager::buildConnectionString
	 */
	public function testBuildConnectionStringOnlyDriverNoServer(): void {
		// A driver-only config (no server/host/database) should produce only the Driver= part.
		$config = [ 'driver' => 'MyDriver' ];
		$this->assertSame( 'Driver={MyDriver}', ODBCConnectionManager::buildConnectionString( $config ) );
	}

	/**
	 * @covers ODBCConnectionManager::buildConnectionString
	 */
	public function testBuildConnectionStringPortAsString(): void {
		// Port can be provided as string or int — both should work.
		$config = [
			'driver' => 'MySQL ODBC 8.0 Driver',
			'server' => 'localhost',
			'port'   => '3306',
		];
		$result = ODBCConnectionManager::buildConnectionString( $config );
		$this->assertStringContainsString( 'Port=3306', $result );
	}

	// ── validateConfig() — additional edge cases ────────────────────────────

	/**
	 * @covers ODBCConnectionManager::validateConfig
	 */
	public function testValidateConfigConnectionStringIsValid(): void {
		$errors = ODBCConnectionManager::validateConfig( [
			'connection_string' => 'Driver={X};Server=y',
		] );
		$this->assertSame( [], $errors );
	}

	/**
	 * @covers ODBCConnectionManager::validateConfig
	 */
	public function testValidateConfigDriverWithHostAndDatabase(): void {
		$errors = ODBCConnectionManager::validateConfig( [
			'driver'   => 'PostgreSQL',
			'host'     => 'pghost',
			'database' => 'mydb',
		] );
		$this->assertSame( [], $errors );
	}

	/**
	 * @covers ODBCConnectionManager::validateConfig
	 */
	public function testValidateConfigOnlyServerNoDriverReturnsError(): void {
		// server without driver or dsn is invalid.
		$errors = ODBCConnectionManager::validateConfig( [
			'server' => 'localhost',
		] );
		$this->assertNotEmpty( $errors );
	}

	/**
	 * @covers ODBCConnectionManager::validateConfig
	 */
	public function testValidateConfigReturnsMultipleErrors(): void {
		// driver without server should return at least one error about server/host.
		$errors = ODBCConnectionManager::validateConfig( [
			'driver' => 'SomeDriver',
		] );
		$this->assertNotEmpty( $errors );
		$hasServerError = false;
		foreach ( $errors as $error ) {
			if ( stripos( $error, 'server' ) !== false || stripos( $error, 'host' ) !== false ) {
				$hasServerError = true;
			}
		}
		$this->assertTrue( $hasServerError, 'Expected an error about missing server/host' );
	}

	// ── withOdbcWarnings() ──────────────────────────────────────────────────

	/**
	 * @covers ODBCConnectionManager::withOdbcWarnings
	 */
	public function testWithOdbcWarningsReturnsCallableResult(): void {
		$result = ODBCConnectionManager::withOdbcWarnings( static fn () => 42 );
		$this->assertSame( 42, $result );
	}

	/**
	 * @covers ODBCConnectionManager::withOdbcWarnings
	 */
	public function testWithOdbcWarningsReturnsArray(): void {
		$result = ODBCConnectionManager::withOdbcWarnings( static fn () => [ 'a' => 1 ] );
		$this->assertSame( [ 'a' => 1 ], $result );
	}

	/**
	 * @covers ODBCConnectionManager::withOdbcWarnings
	 */
	public function testWithOdbcWarningsReturnsNull(): void {
		$result = ODBCConnectionManager::withOdbcWarnings( static function () {
			// Void-like callable.
		} );
		$this->assertNull( $result );
	}

	/**
	 * @covers ODBCConnectionManager::withOdbcWarnings
	 */
	public function testWithOdbcWarningsPropagatesMWException(): void {
		// An MWException thrown inside the callable should propagate outward.
		$this->expectException( MWException::class );
		$this->expectExceptionMessage( 'inner error' );
		ODBCConnectionManager::withOdbcWarnings( static function () {
			throw new MWException( 'inner error' );
		} );
	}

	/**
	 * @covers ODBCConnectionManager::withOdbcWarnings
	 */
	public function testWithOdbcWarningsRestoresHandlerAfterSuccess(): void {
		$before = set_error_handler( static fn () => true );
		restore_error_handler();

		ODBCConnectionManager::withOdbcWarnings( static fn () => 'ok' );

		$after = set_error_handler( static fn () => true );
		restore_error_handler();

		$this->assertSame( $before, $after, 'Error handler should be restored after success' );
	}

	/**
	 * @covers ODBCConnectionManager::withOdbcWarnings
	 */
	public function testWithOdbcWarningsRestoresHandlerAfterException(): void {
		$before = set_error_handler( static fn () => true );
		restore_error_handler();

		try {
			ODBCConnectionManager::withOdbcWarnings( static function () {
				throw new MWException( 'test' );
			} );
		} catch ( MWException $e ) {
			// Expected.
		}

		$after = set_error_handler( static fn () => true );
		restore_error_handler();

		$this->assertSame( $before, $after, 'Error handler should be restored after exception' );
	}

	// ── sanitizeErrorMessage() via reflection ───────────────────────────────

	/**
	 * @covers ODBCConnectionManager
	 */
	public function testSanitizeErrorMessageRemovesPassword(): void {
		$result = self::callPrivateStatic( 'sanitizeErrorMessage', [
			'Connection failed: PWD=MyS3cretP@ss;Server=host',
		] );
		$this->assertStringNotContainsString( 'MyS3cretP@ss', $result );
		$this->assertStringContainsString( 'PWD=***', $result );
	}

	/**
	 * @covers ODBCConnectionManager
	 */
	public function testSanitizeErrorMessageRemovesUid(): void {
		$result = self::callPrivateStatic( 'sanitizeErrorMessage', [
			'Connection failed: UID=admin;PWD=secret;Server=host',
		] );
		$this->assertStringNotContainsString( 'admin', $result );
		$this->assertStringContainsString( 'UID=***', $result );
	}

	/**
	 * @covers ODBCConnectionManager
	 */
	public function testSanitizeErrorMessagePreservesNonSensitiveContent(): void {
		$result = self::callPrivateStatic( 'sanitizeErrorMessage', [
			'Connection timeout: Server=myhost;Database=mydb',
		] );
		$this->assertStringContainsString( 'myhost', $result );
		$this->assertStringContainsString( 'mydb', $result );
	}

	/**
	 * @covers ODBCConnectionManager
	 */
	public function testSanitizeErrorMessageCaseInsensitivePassword(): void {
		$result = self::callPrivateStatic( 'sanitizeErrorMessage', [
			'Error: password=hunter2;server=x',
		] );
		$this->assertStringNotContainsString( 'hunter2', $result );
	}

	// ── Helper: invoke private static method via reflection ─────────────────

	/**
	 * Call a private/protected static method on ODBCConnectionManager.
	 *
	 * @param string $method Method name.
	 * @param array $args Arguments to pass.
	 * @return mixed Return value.
	 */
	private static function callPrivateStatic( string $method, array $args ) {
		$ref = new ReflectionMethod( ODBCConnectionManager::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( null, $args );
	}
}
