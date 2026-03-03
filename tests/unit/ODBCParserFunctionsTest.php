<?php
/**
 * Unit tests for ODBCParserFunctions.
 *
 * Tests private helper methods via reflection. These are pure-logic methods
 * with no MediaWiki or database dependencies.
 *
 * @covers ODBCParserFunctions
 * @license GPL-2.0-or-later
 */

use PHPUnit\Framework\TestCase;

class ODBCParserFunctionsTest extends TestCase {

	// ── Helper: invoke private static method via reflection ─────────────────

	/**
	 * Call a private/protected static method on ODBCParserFunctions.
	 *
	 * @param string $method Method name.
	 * @param array $args Arguments to pass.
	 * @return mixed Return value.
	 */
	private static function callPrivateStatic( string $method, array $args ) {
		$ref = new ReflectionMethod( ODBCParserFunctions::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( null, $args );
	}

	// ── parseDataMappings() ─────────────────────────────────────────────────

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testParseDataMappingsEmpty(): void {
		$result = self::callPrivateStatic( 'parseDataMappings', [ '' ] );
		$this->assertSame( [], $result );
	}

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testParseDataMappingsSingleMapping(): void {
		$result = self::callPrivateStatic( 'parseDataMappings', [ 'localVar=dbCol' ] );
		$this->assertSame( [ 'localVar' => 'dbCol' ], $result );
	}

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testParseDataMappingsMultipleMappings(): void {
		$result = self::callPrivateStatic( 'parseDataMappings', [ 'name=Name, age=Age, city=City' ] );
		$this->assertSame( [
			'name' => 'Name',
			'age'  => 'Age',
			'city' => 'City',
		], $result );
	}

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testParseDataMappingsWithoutEquals(): void {
		// When no '=' is present, the variable name equals the column name.
		$result = self::callPrivateStatic( 'parseDataMappings', [ 'col1,col2,col3' ] );
		$this->assertSame( [
			'col1' => 'col1',
			'col2' => 'col2',
			'col3' => 'col3',
		], $result );
	}

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testParseDataMappingsMixed(): void {
		$result = self::callPrivateStatic( 'parseDataMappings', [ 'name=FullName,age,city=CityName' ] );
		$this->assertSame( [
			'name' => 'FullName',
			'age'  => 'age',
			'city' => 'CityName',
		], $result );
	}

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testParseDataMappingsSkipsEmptyPairs(): void {
		$result = self::callPrivateStatic( 'parseDataMappings', [ 'name=Name,,age=Age,' ] );
		$this->assertSame( [
			'name' => 'Name',
			'age'  => 'Age',
		], $result );
	}

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testParseDataMappingsDropsOversizedPairs(): void {
		// Pairs longer than 256 chars should be silently dropped.
		$longPair = str_repeat( 'a', 257 );
		$result = self::callPrivateStatic( 'parseDataMappings', [ "name=Name,$longPair,age=Age" ] );
		$this->assertSame( [
			'name' => 'Name',
			'age'  => 'Age',
		], $result );
	}

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testParseDataMappingsDropsOverlongVariables(): void {
		// Variables longer than 64 chars should be dropped.
		$longVar = str_repeat( 'x', 65 );
		$result = self::callPrivateStatic( 'parseDataMappings', [ "$longVar=col,name=Name" ] );
		$this->assertSame( [ 'name' => 'Name' ], $result );
	}

	// ── mergeResults() ──────────────────────────────────────────────────────

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testMergeResultsWithMappings(): void {
		$storedData = [];
		$rows = [
			[ 'Name' => 'Alice', 'Age' => '30' ],
			[ 'Name' => 'Bob', 'Age' => '25' ],
		];
		$mappings = [ 'name' => 'Name', 'age' => 'Age' ];

		self::callPrivateStatic( 'mergeResults', [ &$storedData, $rows, $mappings, '' ] );

		$this->assertSame( [ 'Alice', 'Bob' ], $storedData['name'] );
		$this->assertSame( [ '30', '25' ], $storedData['age'] );
	}

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testMergeResultsWithoutMappings(): void {
		$storedData = [];
		$rows = [
			[ 'Name' => 'Alice', 'City' => 'London' ],
		];

		self::callPrivateStatic( 'mergeResults', [ &$storedData, $rows, [], '' ] );

		// Column names should be lowercased.
		$this->assertSame( [ 'Alice' ], $storedData['name'] );
		$this->assertSame( [ 'London' ], $storedData['city'] );
	}

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testMergeResultsNullHandling(): void {
		$storedData = [];
		$rows = [
			[ 'Name' => 'Alice', 'Notes' => null ],
		];
		$mappings = [ 'name' => 'Name', 'notes' => 'Notes' ];

		self::callPrivateStatic( 'mergeResults', [ &$storedData, $rows, $mappings, 'NULL' ] );

		$this->assertSame( [ 'Alice' ], $storedData['name'] );
		$this->assertSame( [ 'NULL' ], $storedData['notes'] );
	}

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testMergeResultsMissingColumn(): void {
		$storedData = [];
		$rows = [
			[ 'Name' => 'Alice' ], // No 'Age' column.
		];
		$mappings = [ 'name' => 'Name', 'age' => 'Age' ];

		self::callPrivateStatic( 'mergeResults', [ &$storedData, $rows, $mappings, 'N/A' ] );

		$this->assertSame( [ 'Alice' ], $storedData['name'] );
		// Missing column → '' (not $nullValue).
		$this->assertSame( [ '' ], $storedData['age'] );
	}

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testMergeResultsCaseInsensitive(): void {
		$storedData = [];
		$rows = [
			[ 'NAME' => 'Alice', 'AGE' => '30' ],
		];
		$mappings = [ 'name' => 'name', 'age' => 'age' ];

		self::callPrivateStatic( 'mergeResults', [ &$storedData, $rows, $mappings, '' ] );

		$this->assertSame( [ 'Alice' ], $storedData['name'] );
		$this->assertSame( [ '30' ], $storedData['age'] );
	}

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testMergeResultsAppendsToExistingData(): void {
		$storedData = [
			'name' => [ 'Existing' ],
		];
		$rows = [
			[ 'Name' => 'Alice' ],
		];
		$mappings = [ 'name' => 'Name' ];

		self::callPrivateStatic( 'mergeResults', [ &$storedData, $rows, $mappings, '' ] );

		$this->assertSame( [ 'Existing', 'Alice' ], $storedData['name'] );
	}

	// ── parseSimpleArgs() ───────────────────────────────────────────────────

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testParseSimpleArgsEmpty(): void {
		$result = self::callPrivateStatic( 'parseSimpleArgs', [ [] ] );
		$this->assertSame( [], $result );
	}

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testParseSimpleArgsNamedParams(): void {
		$result = self::callPrivateStatic( 'parseSimpleArgs', [
			[ 'template=MyTemplate', 'limit=10' ],
		] );
		$this->assertSame( [
			'template' => 'MyTemplate',
			'limit'    => '10',
		], $result );
	}

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testParseSimpleArgsIgnoresNonNamed(): void {
		// Arguments without '=' are silently ignored.
		$result = self::callPrivateStatic( 'parseSimpleArgs', [
			[ 'positionalValue', 'template=MyTemplate' ],
		] );
		$this->assertSame( [ 'template' => 'MyTemplate' ], $result );
	}

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testParseSimpleArgsTrimsAndLowercasesKeys(): void {
		$result = self::callPrivateStatic( 'parseSimpleArgs', [
			[ '  Template  =  MyTemplate  ' ],
		] );
		$this->assertSame( [ 'template' => 'MyTemplate' ], $result );
	}

	// ── escapeTemplateParam() ───────────────────────────────────────────────

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testEscapeTemplateParamSafeString(): void {
		$result = self::callPrivateStatic( 'escapeTemplateParam', [ 'Hello World' ] );
		$this->assertSame( 'Hello World', $result );
	}

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testEscapeTemplateParamPipe(): void {
		$result = self::callPrivateStatic( 'escapeTemplateParam', [ 'A|B' ] );
		// strtr() applies all replacements simultaneously — no interaction.
		$this->assertSame( 'A{{!}}B', $result );
	}

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testEscapeTemplateParamTemplateClose(): void {
		$result = self::callPrivateStatic( 'escapeTemplateParam', [ 'val}}more' ] );
		$this->assertSame( 'val&#125;&#125;more', $result );
	}

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testEscapeTemplateParamTripleBrace(): void {
		$result = self::callPrivateStatic( 'escapeTemplateParam', [ 'val{{{param}}}end' ] );
		// strtr() matches longest keys first: '{{{' and '}}' are replaced simultaneously.
		$this->assertSame( 'val&#123;&#123;&#123;param&#125;&#125;}end', $result );
	}

	// ── formatError() ───────────────────────────────────────────────────────

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testFormatErrorWrapsInSpan(): void {
		$result = self::callPrivateStatic( 'formatError', [ 'Something broke' ] );
		$this->assertStringContainsString( '<span class="error odbc-error">', $result );
		$this->assertStringContainsString( 'Something broke', $result );
		$this->assertStringContainsString( '</span>', $result );
	}

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testFormatErrorEscapesHtml(): void {
		$result = self::callPrivateStatic( 'formatError', [ '<script>alert("XSS")</script>' ] );
		$this->assertStringNotContainsString( '<script>', $result );
		$this->assertStringContainsString( '&lt;script&gt;', $result );
	}
}
