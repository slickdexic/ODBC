<?php
/**
 * Unit tests for ODBCQueryRunner static utility methods.
 *
 * Tests sanitize(), validateIdentifier(), and getRowLimitStyle() — all
 * public static methods with no database or MediaWiki service dependencies.
 *
 * @covers ODBCQueryRunner
 * @license GPL-2.0-or-later
 */

use PHPUnit\Framework\TestCase;

class ODBCQueryRunnerTest extends TestCase {

	// ── sanitize() — safe inputs ────────────────────────────────────────────

	/**
	 * @covers ODBCQueryRunner::sanitize
	 */
	public function testSanitizeAllowsEmptyString(): void {
		// Empty input should not throw.
		ODBCQueryRunner::sanitize( '', 'test' );
		$this->assertTrue( true ); // No exception = pass.
	}

	/**
	 * @covers ODBCQueryRunner::sanitize
	 */
	public function testSanitizeAllowsSimpleTableName(): void {
		ODBCQueryRunner::sanitize( 'Customers', 'from' );
		$this->assertTrue( true );
	}

	/**
	 * @covers ODBCQueryRunner::sanitize
	 */
	public function testSanitizeAllowsWhereClause(): void {
		ODBCQueryRunner::sanitize( "Status = 'active' AND Region = 'EU'", 'where' );
		$this->assertTrue( true );
	}

	/**
	 * @covers ODBCQueryRunner::sanitize
	 */
	public function testSanitizeAllowsOrderByClause(): void {
		ODBCQueryRunner::sanitize( 'LastName ASC, FirstName DESC', 'order by' );
		$this->assertTrue( true );
	}

	/**
	 * @covers ODBCQueryRunner::sanitize
	 */
	public function testSanitizeAllowsGroupByClause(): void {
		ODBCQueryRunner::sanitize( 'Department, Region', 'group by' );
		$this->assertTrue( true );
	}

	/**
	 * @covers ODBCQueryRunner::sanitize
	 * @dataProvider provideAllowedIdentifiers
	 */
	public function testSanitizeDoesNotFalsePositiveOnSafeWords( string $input ): void {
		ODBCQueryRunner::sanitize( $input, 'where' );
		$this->assertTrue( true );
	}

	/**
	 * Identifiers that contain dangerous keywords as substrings but are safe.
	 */
	public static function provideAllowedIdentifiers(): array {
		return [
			'TRADE_UNION identity' => [ 'TRADE_UNION' ],
			'LABOUR_UNION column'  => [ 'LABOUR_UNION_ID' ],
			'REUNION city name'    => [ 'REUNION' ],
			'DECLARED_AT column'   => [ 'DECLARED_AT' ],
			'GRANTED_BY column'    => [ 'GRANTED_BY' ],
			'EXECUTIVE column'     => [ 'EXECUTIVE' ],
			'UPDATED_AT column'    => [ 'UPDATED_AT' ],
			'CREATED_BY column'    => [ 'CREATED_BY' ],
			'DROPSHIP column'      => [ 'DROPSHIP' ],
			'REPLACEABLE column'   => [ 'REPLACEABLE' ],
		];
	}

	// ── sanitize() — dangerous inputs ───────────────────────────────────────

	/**
	 * @covers ODBCQueryRunner::sanitize
	 * @dataProvider provideDangerousInputs
	 */
	public function testSanitizeBlocksDangerousInput( string $input, string $description ): void {
		$this->expectException( MWException::class );
		ODBCQueryRunner::sanitize( $input, 'from' );
	}

	/**
	 * Inputs that the sanitizer must block.
	 */
	public static function provideDangerousInputs(): array {
		return [
			'semicolon'              => [ 'users; DROP TABLE users', 'semicolon injection' ],
			'double dash comment'    => [ 'users -- comment', 'SQL line comment' ],
			'hash comment'           => [ 'users # comment', 'MySQL line comment' ],
			'block comment open'     => [ 'users /* hidden */', 'block comment' ],
			'DROP keyword'           => [ 'DROP TABLE users', 'DROP statement' ],
			'DELETE keyword'         => [ 'DELETE FROM users', 'DELETE statement' ],
			'INSERT keyword'         => [ 'INSERT INTO users', 'INSERT statement' ],
			'UPDATE keyword'         => [ 'UPDATE users SET x=1', 'UPDATE statement' ],
			'TRUNCATE keyword'       => [ 'TRUNCATE TABLE users', 'TRUNCATE statement' ],
			'EXEC keyword'           => [ 'EXEC sp_who', 'EXEC statement' ],
			'EXECUTE keyword'        => [ 'EXECUTE sp_who', 'EXECUTE statement' ],
			'UNION keyword'          => [ 'users UNION SELECT * FROM admins', 'UNION injection' ],
			'GRANT keyword'          => [ 'GRANT ALL ON users', 'privilege escalation' ],
			'CREATE keyword'         => [ 'CREATE TABLE t(id INT)', 'DDL injection' ],
			'ALTER keyword'          => [ 'ALTER TABLE users ADD col INT', 'DDL injection' ],
			'SHUTDOWN keyword'       => [ 'SHUTDOWN', 'server shutdown' ],
			'SLEEP function'         => [ "1 AND SLEEP(5)", 'time-delay injection' ],
			'BENCHMARK function'     => [ 'BENCHMARK(1000000, MD5(1))', 'time-delay injection' ],
			'WAITFOR keyword'        => [ "WAITFOR DELAY '0:0:5'", 'time-delay injection' ],
			'CHAR function'          => [ 'CHAR(83)', 'encoding evasion' ],
			'CONCAT function'        => [ "CONCAT('DR','OP')", 'encoding evasion' ],
			'CAST function'          => [ 'CAST(0x44524F50 AS CHAR)', 'hex evasion' ],
			'CONVERT function'       => [ 'CONVERT(0x41 USING utf8)', 'hex evasion' ],
			'INTO OUTFILE'           => [ "1 INTO OUTFILE '/tmp/x'", 'file write' ],
			'INTO DUMPFILE'          => [ "1 INTO DUMPFILE '/tmp/x'", 'file write' ],
			'LOAD DATA'             => [ 'LOAD DATA INFILE x', 'file read' ],
			'XP_ stored proc'       => [ 'xp_cmdshell', 'extended stored proc' ],
			'SP_ stored proc'       => [ 'sp_executesql', 'system stored proc' ],
			'DECLARE keyword'        => [ 'DECLARE @x INT', 'variable declaration' ],
			'INFORMATION_SCHEMA'     => [ 'INFORMATION_SCHEMA.TABLES', 'schema recon' ],
			'PHP open tag'           => [ '<?php echo 1; ?>', 'PHP injection' ],
			// Whitespace evasion: tabs and multi-space should still be caught.
			'tab evasion INTO OUTFILE' => [ "1 INTO\tOUTFILE '/tmp/x'", 'tab-based evasion' ],
			'multi-space evasion'      => [ "LOAD  DATA INFILE x", 'multi-space evasion' ],
			// Null byte evasion.
			'null byte evasion'        => [ "users\x00; DROP TABLE users", 'null byte evasion' ],
		];
	}

	// ── validateIdentifier() ────────────────────────────────────────────────

	/**
	 * @covers ODBCQueryRunner::validateIdentifier
	 * @dataProvider provideValidIdentifiers
	 */
	public function testValidateIdentifierAcceptsValid( string $identifier ): void {
		ODBCQueryRunner::validateIdentifier( $identifier, 'test' );
		$this->assertTrue( true );
	}

	public static function provideValidIdentifiers(): array {
		return [
			'empty'               => [ '' ],
			'wildcard'            => [ '*' ],
			'simple name'         => [ 'Users' ],
			'underscore prefix'   => [ '_internal' ],
			'with digits'         => [ 'col2' ],
			'two-part name'       => [ 'dbo.Users' ],
			'three-part name'     => [ 'catalog.dbo.Users' ],
			'all underscores'     => [ '_foo_bar_' ],
		];
	}

	/**
	 * @covers ODBCQueryRunner::validateIdentifier
	 * @dataProvider provideInvalidIdentifiers
	 */
	public function testValidateIdentifierRejectsInvalid( string $identifier ): void {
		$this->expectException( MWException::class );
		ODBCQueryRunner::validateIdentifier( $identifier, 'test' );
	}

	public static function provideInvalidIdentifiers(): array {
		return [
			'starts with digit'    => [ '1table' ],
			'contains space'       => [ 'my table' ],
			'trailing dot'         => [ 'schema.' ],
			'double dot'           => [ 'schema..table' ],
			'four-part name'       => [ 'a.b.c.d' ],
			'special chars'        => [ 'table$name' ],
			'semicolon'            => [ 'table;name' ],
			'hyphen'               => [ 'my-table' ],
		];
	}

	/**
	 * @covers ODBCQueryRunner::validateIdentifier
	 */
	public function testValidateIdentifierRejectsTooLong(): void {
		$this->expectException( MWException::class );
		$longIdent = str_repeat( 'a', 129 );
		ODBCQueryRunner::validateIdentifier( $longIdent, 'test' );
	}

	/**
	 * @covers ODBCQueryRunner::validateIdentifier
	 */
	public function testValidateIdentifierAcceptsMaxLength(): void {
		$maxIdent = str_repeat( 'a', 128 );
		ODBCQueryRunner::validateIdentifier( $maxIdent, 'test' );
		$this->assertTrue( true );
	}

	// ── getRowLimitStyle() ──────────────────────────────────────────────────

	/**
	 * @covers ODBCQueryRunner::getRowLimitStyle
	 * @dataProvider provideRowLimitStyleDrivers
	 */
	public function testGetRowLimitStyle( array $config, string $expected ): void {
		$this->assertSame( $expected, ODBCQueryRunner::getRowLimitStyle( $config ) );
	}

	public static function provideRowLimitStyleDrivers(): array {
		return [
			'empty driver'         => [ [], 'limit' ],
			'no driver key'        => [ [ 'dsn' => 'MyDSN' ], 'limit' ],
			'empty driver string'  => [ [ 'driver' => '' ], 'limit' ],
			'MySQL'                => [ [ 'driver' => 'MySQL ODBC 8.0 Unicode Driver' ], 'limit' ],
			'PostgreSQL'           => [ [ 'driver' => 'PostgreSQL Unicode' ], 'limit' ],
			'SQLite'               => [ [ 'driver' => 'SQLite3 ODBC Driver' ], 'limit' ],
			'SQL Server 17'        => [ [ 'driver' => 'ODBC Driver 17 for SQL Server' ], 'top' ],
			'SQL Server 18'        => [ [ 'driver' => 'ODBC Driver 18 for SQL Server' ], 'top' ],
			'SQL Server native'    => [ [ 'driver' => 'SQL Server Native Client 11.0' ], 'top' ],
			'SQLServer no space'   => [ [ 'driver' => 'SQLServer' ], 'top' ],
			'MS Access'            => [ [ 'driver' => 'Microsoft Access Driver (*.mdb, *.accdb)' ], 'top' ],
			'Sybase ASE'           => [ [ 'driver' => 'Sybase ASE ODBC Driver' ], 'top' ],
			'Adaptive Server'      => [ [ 'driver' => 'Adaptive Server Enterprise' ], 'top' ],
			'Progress OpenEdge'    => [ [ 'driver' => 'Progress OpenEdge 12.2 Driver' ], 'first' ],
			'DataDirect Progress'  => [ [ 'driver' => 'DataDirect 8.0 Progress OpenEdge Wire Protocol' ], 'first' ],
			'OpenEdge bare'        => [ [ 'driver' => 'OpenEdge Wire Protocol' ], 'first' ],
			'case insensitive'     => [ [ 'driver' => 'ODBC DRIVER 17 FOR SQL SERVER' ], 'top' ],
		];
	}

	// ── requiresTopSyntax() (deprecated wrapper) ────────────────────────────

	/**
	 * @covers ODBCQueryRunner::requiresTopSyntax
	 */
	public function testRequiresTopSyntaxReturnsTrueForSqlServer(): void {
		$this->assertTrue(
			ODBCQueryRunner::requiresTopSyntax( [ 'driver' => 'ODBC Driver 17 for SQL Server' ] )
		);
	}

	/**
	 * @covers ODBCQueryRunner::requiresTopSyntax
	 */
	public function testRequiresTopSyntaxReturnsFalseForMySQL(): void {
		$this->assertFalse(
			ODBCQueryRunner::requiresTopSyntax( [ 'driver' => 'MySQL ODBC 8.0 Driver' ] )
		);
	}

	/**
	 * @covers ODBCQueryRunner::requiresTopSyntax
	 */
	public function testRequiresTopSyntaxReturnsFalseForProgress(): void {
		// Progress uses FIRST, not TOP — requiresTopSyntax should return false.
		$this->assertFalse(
			ODBCQueryRunner::requiresTopSyntax( [ 'driver' => 'Progress OpenEdge 12.2 Driver' ] )
		);
	}
}
