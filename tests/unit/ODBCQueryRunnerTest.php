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
			// ── Structural character patterns ───────────────────────────────
			'semicolon'              => [ 'users; DROP TABLE users', 'semicolon injection' ],
			'double dash comment'    => [ 'users -- comment', 'SQL line comment' ],
			'hash comment'           => [ 'users # comment', 'MySQL line comment' ],
			'block comment open'     => [ 'users /* hidden */', 'block comment' ],
			'block comment close'    => [ 'users */ hidden', 'block comment close' ],
			'PHP open tag'           => [ '<?php echo 1; ?>', 'PHP injection' ],

			// ── Encoding evasion functions ───────────────────────────────────
			'CHAR function'          => [ 'CHAR(83)', 'encoding evasion' ],
			'CONCAT function'        => [ "CONCAT('DR','OP')", 'encoding evasion' ],
			'CAST function'          => [ 'CAST(0x44524F50 AS CHAR)', 'hex evasion' ],
			'CONVERT function'       => [ 'CONVERT(0x41 USING utf8)', 'hex evasion' ],

			// ── DDL keywords ────────────────────────────────────────────────
			'DROP keyword'           => [ 'DROP TABLE users', 'DROP statement' ],
			'CREATE keyword'         => [ 'CREATE TABLE t(id INT)', 'DDL injection' ],
			'ALTER keyword'          => [ 'ALTER TABLE users ADD col INT', 'DDL injection' ],
			'TRUNCATE keyword'       => [ 'TRUNCATE TABLE users', 'TRUNCATE statement' ],

			// ── DML keywords ────────────────────────────────────────────────
			'DELETE keyword'         => [ 'DELETE FROM users', 'DELETE statement' ],
			'INSERT keyword'         => [ 'INSERT INTO users', 'INSERT statement' ],
			'UPDATE keyword'         => [ 'UPDATE users SET x=1', 'UPDATE statement' ],
			'MERGE keyword'          => [ 'MERGE INTO users USING src', 'MERGE statement' ],
			'REPLACE keyword'        => [ 'REPLACE INTO users VALUES(1)', 'REPLACE statement' ],

			// ── Execution / procedure keywords ──────────────────────────────
			'EXEC keyword'           => [ 'EXEC sp_who', 'EXEC statement' ],
			'EXECUTE keyword'        => [ 'EXECUTE sp_who', 'EXECUTE statement' ],
			'CALL keyword'           => [ 'CALL myproc()', 'CALL statement' ],

			// ── Privilege escalation ────────────────────────────────────────
			'GRANT keyword'          => [ 'GRANT ALL ON users', 'privilege escalation' ],
			'REVOKE keyword'         => [ 'REVOKE ALL ON users FROM guest', 'privilege revocation' ],

			// ── Set operations ──────────────────────────────────────────────
			'UNION keyword'          => [ 'users UNION SELECT * FROM admins', 'UNION injection' ],

			// ── Server admin keywords ───────────────────────────────────────
			'SHUTDOWN keyword'       => [ 'SHUTDOWN', 'server shutdown' ],
			'BACKUP keyword'         => [ 'BACKUP DATABASE mydb TO disk', 'backup command' ],
			'RESTORE keyword'        => [ 'RESTORE DATABASE mydb FROM disk', 'restore command' ],

			// ── Time-delay / blind injection ────────────────────────────────
			'SLEEP function'         => [ "1 AND SLEEP(5)", 'time-delay injection' ],
			'PG_SLEEP function'      => [ "1 AND PG_SLEEP(5)", 'PostgreSQL time-delay' ],
			'BENCHMARK function'     => [ 'BENCHMARK(1000000, MD5(1))', 'time-delay injection' ],
			'WAITFOR keyword'        => [ "WAITFOR DELAY '0:0:5'", 'time-delay injection' ],

			// ── File I/O ────────────────────────────────────────────────────
			'INTO OUTFILE'           => [ "1 INTO OUTFILE '/tmp/x'", 'file write' ],
			'INTO DUMPFILE'          => [ "1 INTO DUMPFILE '/tmp/x'", 'file write' ],
			'LOAD DATA'             => [ 'LOAD DATA INFILE x', 'file read' ],
			'LOAD_FILE function'     => [ "LOAD_FILE('/etc/passwd')", 'file read function' ],

			// ── Stored procedures / extended procs ──────────────────────────
			'XP_ stored proc'       => [ 'xp_cmdshell', 'extended stored proc' ],
			'SP_ stored proc'       => [ 'sp_executesql', 'system stored proc' ],

			// ── SQL Server linked-server functions ──────────────────────────
			'OPENROWSET'             => [ "OPENROWSET('SQLNCLI','srv')", 'linked server' ],
			'OPENDATASOURCE'         => [ "OPENDATASOURCE('SQLNCLI','srv')", 'linked server' ],
			'OPENQUERY'              => [ "OPENQUERY(linkedsrv, 'SELECT 1')", 'linked server' ],

			// ── SQL Server debug commands ────────────────────────────────────
			'DBCC keyword'           => [ 'DBCC CHECKDB', 'SQL Server debug' ],

			// ── Variable declaration ────────────────────────────────────────
			'DECLARE keyword'        => [ 'DECLARE @x INT', 'variable declaration' ],

			// ── Schema reconnaissance ───────────────────────────────────────
			'INFORMATION_SCHEMA'     => [ 'INFORMATION_SCHEMA.TABLES', 'schema recon' ],
			'SYS. schema'            => [ 'SYS.TABLES', 'schema recon via SYS' ],

			// ── Oracle I/O packages ─────────────────────────────────────────
			'UTL_FILE package'       => [ 'UTL_FILE.FOPEN', 'Oracle file I/O' ],
			'UTL_HTTP package'       => [ 'UTL_HTTP.REQUEST', 'Oracle network I/O' ],

			// ── Whitespace evasion ──────────────────────────────────────────
			'tab evasion INTO OUTFILE' => [ "1 INTO\tOUTFILE '/tmp/x'", 'tab-based evasion' ],
			'multi-space evasion'      => [ "LOAD  DATA INFILE x", 'multi-space evasion' ],
			'newline evasion DROP'     => [ "x\nDROP TABLE users", 'newline evasion' ],

			// ── Null byte evasion ───────────────────────────────────────────
			'null byte evasion'        => [ "users\x00; DROP TABLE users", 'null byte evasion' ],

			// ── Case-variation evasion ───────────────────────────────────────
			'lowercase drop'           => [ 'drop table users', 'lowercase keyword' ],
			'mixed case dRoP'          => [ 'dRoP TABLE users', 'mixed case keyword' ],
			'lowercase insert'         => [ 'insert into users', 'lowercase DML' ],
			'lowercase union'          => [ 'users union select 1', 'lowercase set op' ],
			'lowercase shutdown'       => [ 'shutdown', 'lowercase admin command' ],
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

	// ── sanitize() — additional safe-input false-positive guard tests ────────

	/**
	 * @covers ODBCQueryRunner::sanitize
	 * @dataProvider provideAdditionalSafeIdentifiers
	 */
	public function testSanitizeDoesNotFalsePositiveOnAdditionalSafeWords( string $input ): void {
		ODBCQueryRunner::sanitize( $input, 'where' );
		$this->assertTrue( true );
	}

	/**
	 * Additional identifiers that contain dangerous keywords as substrings but are safe.
	 */
	public static function provideAdditionalSafeIdentifiers(): array {
		return [
			'EXECUTOR column'       => [ 'EXECUTOR' ],
			'EXECUTABLE column'     => [ 'EXECUTABLE_PATH' ],
			'CALLSIGN column'       => [ 'CALLSIGN' ],
			'RECALLED column'       => [ 'RECALLED_AT' ],
			'BACKUPS_COUNT column'  => [ 'BACKUPS_COUNT' ],
			'RESTORED_BY column'    => [ 'RESTORED_BY' ],
			'INSERTING flag'        => [ 'INSERTING' ],
			'UPDATABLE column'      => [ 'UPDATABLE' ],
			'DELETEABLE column'     => [ 'DELETEABLE' ],
			'SLEEPER column'        => [ 'SLEEPER' ],
			'GRANTEE column'        => [ 'GRANTEE' ],
			'MERGED_AT column'      => [ 'MERGED_AT' ],
			'simple WHERE clause'   => [ "status = 'active'" ],
			'WHERE with numbers'    => [ 'id > 100 AND id < 200' ],
			'WHERE with LIKE'       => [ "name LIKE '%test%'" ],
			'WHERE with IN list'    => [ 'id IN (1,2,3)' ],
			'WHERE with BETWEEN'    => [ 'created BETWEEN 1 AND 10' ],
			'WHERE with IS NULL'    => [ 'deleted_at IS NULL' ],
			'WHERE with IS NOT NULL' => [ 'name IS NOT NULL' ],
			'WHERE OR condition'    => [ "status = 'A' OR status = 'B'" ],
			'WHERE NOT condition'   => [ "NOT status = 'deleted'" ],
			'complex WHERE'         => [ "dept = 'Sales' AND (age > 25 OR tenure > 5)" ],
		];
	}

	// ── sanitize() — context parameter tests ────────────────────────────────

	/**
	 * @covers ODBCQueryRunner::sanitize
	 */
	public function testSanitizeErrorMessageContainsContext(): void {
		try {
			ODBCQueryRunner::sanitize( 'DROP TABLE users', 'from' );
			$this->fail( 'Expected MWException was not thrown' );
		} catch ( MWException $e ) {
			// The error message should mention the context and the blocked pattern.
			$this->assertStringContainsString( 'from', $e->getMessage() );
		}
	}

	/**
	 * @covers ODBCQueryRunner::sanitize
	 */
	public function testSanitizeErrorMessageContainsPattern(): void {
		try {
			ODBCQueryRunner::sanitize( 'users UNION SELECT 1', 'where' );
			$this->fail( 'Expected MWException was not thrown' );
		} catch ( MWException $e ) {
			$this->assertStringContainsString( 'UNION', $e->getMessage() );
		}
	}

	// ── validateIdentifier() — additional edge cases ────────────────────────

	/**
	 * @covers ODBCQueryRunner::validateIdentifier
	 */
	public function testValidateIdentifierAcceptsThreePartName(): void {
		ODBCQueryRunner::validateIdentifier( 'catalog.schema.table', 'test' );
		$this->assertTrue( true );
	}

	/**
	 * @covers ODBCQueryRunner::validateIdentifier
	 */
	public function testValidateIdentifierRejectsLeadingDot(): void {
		$this->expectException( MWException::class );
		ODBCQueryRunner::validateIdentifier( '.table', 'test' );
	}

	/**
	 * @covers ODBCQueryRunner::validateIdentifier
	 */
	public function testValidateIdentifierRejectsOnlyDots(): void {
		$this->expectException( MWException::class );
		ODBCQueryRunner::validateIdentifier( '...', 'test' );
	}

	/**
	 * @covers ODBCQueryRunner::validateIdentifier
	 */
	public function testValidateIdentifierRejectsParens(): void {
		$this->expectException( MWException::class );
		ODBCQueryRunner::validateIdentifier( 'func()', 'test' );
	}

	/**
	 * @covers ODBCQueryRunner::validateIdentifier
	 */
	public function testValidateIdentifierRejectsQuotes(): void {
		$this->expectException( MWException::class );
		ODBCQueryRunner::validateIdentifier( '"quoted"', 'test' );
	}

	/**
	 * @covers ODBCQueryRunner::validateIdentifier
	 */
	public function testValidateIdentifierRejectsBrackets(): void {
		$this->expectException( MWException::class );
		ODBCQueryRunner::validateIdentifier( '[bracketed]', 'test' );
	}

	/**
	 * @covers ODBCQueryRunner::validateIdentifier
	 */
	public function testValidateIdentifierErrorMessageContainsContext(): void {
		try {
			ODBCQueryRunner::validateIdentifier( '1bad', 'column name' );
			$this->fail( 'Expected MWException was not thrown' );
		} catch ( MWException $e ) {
			$this->assertStringContainsString( 'column name', $e->getMessage() );
		}
	}

	/**
	 * @covers ODBCQueryRunner::validateIdentifier
	 */
	public function testValidateIdentifierAcceptsSingleCharName(): void {
		ODBCQueryRunner::validateIdentifier( 'x', 'test' );
		$this->assertTrue( true );
	}

	/**
	 * @covers ODBCQueryRunner::validateIdentifier
	 */
	public function testValidateIdentifierAcceptsUnderscoreOnly(): void {
		ODBCQueryRunner::validateIdentifier( '_', 'test' );
		$this->assertTrue( true );
	}

	/**
	 * @covers ODBCQueryRunner::validateIdentifier
	 */
	public function testValidateIdentifierRejectsAt128Boundary(): void {
		// Exactly 128 characters should be accepted (tested in testValidateIdentifierAcceptsMaxLength).
		// 129 characters should be rejected.
		$this->expectException( MWException::class );
		ODBCQueryRunner::validateIdentifier( str_repeat( 'a', 129 ), 'test' );
	}

	// ── getRowLimitStyle() — additional driver patterns ─────────────────────

	/**
	 * @covers ODBCQueryRunner::getRowLimitStyle
	 */
	public function testGetRowLimitStyleFreeTDS(): void {
		// FreeTDS driver name doesn't contain 'sql server', so it defaults to 'limit'.
		// Admins using FreeTDS to connect to SQL Server should use a driver name like
		// "ODBC Driver 17 for SQL Server" for correct TOP N syntax detection.
		$this->assertSame( 'limit',
			ODBCQueryRunner::getRowLimitStyle( [ 'driver' => 'FreeTDS' ] )
		);
		// IBM DB2 should also default to LIMIT.
		$this->assertSame( 'limit',
			ODBCQueryRunner::getRowLimitStyle( [ 'driver' => 'IBM DB2 ODBC DRIVER' ] )
		);
	}

	/**
	 * @covers ODBCQueryRunner::getRowLimitStyle
	 */
	public function testGetRowLimitStyleMariaDB(): void {
		$this->assertSame( 'limit',
			ODBCQueryRunner::getRowLimitStyle( [ 'driver' => 'MariaDB ODBC 3.1 Driver' ] )
		);
	}

	/**
	 * @covers ODBCQueryRunner::getRowLimitStyle
	 */
	public function testGetRowLimitStyleOracleInstantClient(): void {
		$this->assertSame( 'limit',
			ODBCQueryRunner::getRowLimitStyle( [ 'driver' => 'Oracle 19 ODBC driver' ] )
		);
	}
}
