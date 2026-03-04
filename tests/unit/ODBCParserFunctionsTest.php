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

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testEscapeTemplateParamMultiplePipes(): void {
		$result = self::callPrivateStatic( 'escapeTemplateParam', [ 'A|B|C' ] );
		$this->assertSame( 'A{{!}}B{{!}}C', $result );
	}

	/**
	 * Verifies that all three dangerous sequences are escaped in a single value.
	 * This is the same escaping now applied in forOdbcTable() (KI-112).
	 *
	 * @covers ODBCParserFunctions
	 */
	public function testEscapeTemplateParamCombinedDangerousSequences(): void {
		$input = 'val|with}}pipes{{{and}}}braces';
		$result = self::callPrivateStatic( 'escapeTemplateParam', [ $input ] );
		// '|' → '{{!}}', '}}' → '&#125;&#125;', '{{{' → '&#123;&#123;&#123;'
		$this->assertSame(
			'val{{!}}with&#125;&#125;pipes&#123;&#123;&#123;and&#125;&#125;}braces',
			$result
		);
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

	// ── mergeResults() — additional edge cases ──────────────────────────────

	/**
	 * Verifies that mergeResults with empty rows does not modify storedData.
	 *
	 * @covers ODBCParserFunctions
	 */
	public function testMergeResultsEmptyRows(): void {
		$storedData = [ 'existing' => [ 'val' ] ];
		self::callPrivateStatic( 'mergeResults', [ &$storedData, [], [ 'name' => 'Name' ], '' ] );
		$this->assertSame( [ 'existing' => [ 'val' ] ], $storedData );
	}

	/**
	 * Verifies that columns without mappings get lowercased variable names.
	 * This is the root cause behavior behind KI-110.
	 *
	 * @covers ODBCParserFunctions
	 */
	public function testMergeResultsUnmappedColumnsAreLowercased(): void {
		$storedData = [];
		$rows = [
			[ 'FirstName' => 'Alice', 'LAST_NAME' => 'Smith', 'age' => '30' ],
		];
		self::callPrivateStatic( 'mergeResults', [ &$storedData, $rows, [], '' ] );

		// All keys should be lowercase.
		$this->assertArrayHasKey( 'firstname', $storedData );
		$this->assertArrayHasKey( 'last_name', $storedData );
		$this->assertArrayHasKey( 'age', $storedData );
		// Original casing should NOT exist.
		$this->assertArrayNotHasKey( 'FirstName', $storedData );
		$this->assertArrayNotHasKey( 'LAST_NAME', $storedData );
	}

	/**
	 * Verifies that mapping keys are always lowercased.
	 *
	 * @covers ODBCParserFunctions
	 */
	public function testMergeResultsMappingKeysAreLowercased(): void {
		$storedData = [];
		$rows = [ [ 'Name' => 'Alice' ] ];
		$mappings = [ 'MyName' => 'Name' ];

		self::callPrivateStatic( 'mergeResults', [ &$storedData, $rows, $mappings, '' ] );

		$this->assertArrayHasKey( 'myname', $storedData );
		$this->assertArrayNotHasKey( 'MyName', $storedData );
	}

	/**
	 * Verifies that null vs missing column distinction works correctly.
	 * - Column present with NULL value → uses $nullValue replacement.
	 * - Column entirely absent from row → uses '' (empty string).
	 *
	 * @covers ODBCParserFunctions
	 */
	public function testMergeResultsNullVsMissingColumnDistinction(): void {
		$storedData = [];
		$rows = [
			[ 'Name' => 'Alice', 'Notes' => null ],
			// 'Missing' is not in the row at all.
		];
		$mappings = [ 'name' => 'Name', 'notes' => 'Notes', 'missing' => 'Missing' ];

		self::callPrivateStatic( 'mergeResults', [ &$storedData, $rows, $mappings, 'NULL' ] );

		$this->assertSame( [ 'Alice' ], $storedData['name'] );
		$this->assertSame( [ 'NULL' ], $storedData['notes'] );   // NULL value → 'NULL'
		$this->assertSame( [ '' ], $storedData['missing'] );      // Missing column → ''
	}

	/**
	 * Verifies default null value is empty string when not specified.
	 *
	 * @covers ODBCParserFunctions
	 */
	public function testMergeResultsDefaultNullValueIsEmpty(): void {
		$storedData = [];
		$rows = [ [ 'Name' => null ] ];
		$mappings = [ 'name' => 'Name' ];

		self::callPrivateStatic( 'mergeResults', [ &$storedData, $rows, $mappings, '' ] );

		$this->assertSame( [ '' ], $storedData['name'] );
	}

	/**
	 * Verifies that mergeResults handles multiple rows from multiple calls,
	 * appending across separate query results.
	 *
	 * @covers ODBCParserFunctions
	 */
	public function testMergeResultsMultipleCallsAccumulate(): void {
		$storedData = [];
		$mappings = [ 'name' => 'Name' ];

		// First query result.
		$rows1 = [ [ 'Name' => 'Alice' ], [ 'Name' => 'Bob' ] ];
		self::callPrivateStatic( 'mergeResults', [ &$storedData, $rows1, $mappings, '' ] );

		// Second query result.
		$rows2 = [ [ 'Name' => 'Charlie' ] ];
		self::callPrivateStatic( 'mergeResults', [ &$storedData, $rows2, $mappings, '' ] );

		$this->assertSame( [ 'Alice', 'Bob', 'Charlie' ], $storedData['name'] );
	}

	/**
	 * Verifies that mergeResults with unmapped columns initializes new variables.
	 *
	 * @covers ODBCParserFunctions
	 */
	public function testMergeResultsUnmappedInitializesVariables(): void {
		$storedData = [];
		$rows = [
			[ 'A' => '1', 'B' => '2' ],
			[ 'A' => '3', 'B' => '4' ],
		];
		self::callPrivateStatic( 'mergeResults', [ &$storedData, $rows, [], '' ] );

		$this->assertSame( [ '1', '3' ], $storedData['a'] );
		$this->assertSame( [ '2', '4' ], $storedData['b'] );
	}

	/**
	 * Verifies that numeric values in rows are converted to strings.
	 *
	 * @covers ODBCParserFunctions
	 */
	public function testMergeResultsConvertsNumericToString(): void {
		$storedData = [];
		$rows = [ [ 'count' => 42, 'price' => 19.99 ] ];
		self::callPrivateStatic( 'mergeResults', [ &$storedData, $rows, [], '' ] );

		$this->assertSame( '42', $storedData['count'][0] );
		$this->assertSame( '19.99', $storedData['price'][0] );
	}

	// ── parseDataMappings() — additional edge cases ─────────────────────────

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testParseDataMappingsTrimsWhitespace(): void {
		$result = self::callPrivateStatic( 'parseDataMappings', [ '  name  =  Name  , age = Age ' ] );
		$this->assertSame( [
			'name' => 'Name',
			'age'  => 'Age',
		], $result );
	}

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testParseDataMappingsOverlongColumnName(): void {
		// db-side column name > 64 chars should be dropped.
		$longCol = str_repeat( 'c', 65 );
		$result = self::callPrivateStatic( 'parseDataMappings', [ "name=$longCol,age=Age" ] );
		$this->assertSame( [ 'age' => 'Age' ], $result );
	}

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testParseDataMappingsEmptyVariableName(): void {
		// "=col" has empty local name → should be dropped.
		$result = self::callPrivateStatic( 'parseDataMappings', [ '=col,name=Name' ] );
		$this->assertSame( [ 'name' => 'Name' ], $result );
	}

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testParseDataMappingsEmptyColumnName(): void {
		// "name=" has empty db col → should be dropped.
		$result = self::callPrivateStatic( 'parseDataMappings', [ 'name=,age=Age' ] );
		$this->assertSame( [ 'age' => 'Age' ], $result );
	}

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testParseDataMappingsExactly64CharVariable(): void {
		// Exactly 64 chars should be ACCEPTED.
		$var64 = str_repeat( 'v', 64 );
		$result = self::callPrivateStatic( 'parseDataMappings', [ "$var64=col" ] );
		$this->assertSame( [ $var64 => 'col' ], $result );
	}

	/**
	 * Verifies the pair-length boundary: pairs > 256 chars are dropped entirely,
	 * while shorter pairs proceed to individual-name-length validation.
	 *
	 * @covers ODBCParserFunctions
	 */
	public function testParseDataMappings257CharPairDropped(): void {
		// A pair of 257 chars is dropped at the pair-length gate (> 256).
		$longPair = str_repeat( 'a', 257 );
		$result = self::callPrivateStatic( 'parseDataMappings', [ "$longPair,name=Name" ] );
		$this->assertSame( [ 'name' => 'Name' ], $result );
	}

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testParseDataMappingsSingleUnmappedColumn(): void {
		$result = self::callPrivateStatic( 'parseDataMappings', [ 'name' ] );
		$this->assertSame( [ 'name' => 'name' ], $result );
	}

	// ── parseSimpleArgs() — additional edge cases ───────────────────────────

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testParseSimpleArgsValueContainingEquals(): void {
		// "where=x=1 AND y=2" → key is 'where', value is 'x=1 AND y=2'.
		$result = self::callPrivateStatic( 'parseSimpleArgs', [
			[ 'where=x=1 AND y=2' ],
		] );
		$this->assertSame( [ 'where' => 'x=1 AND y=2' ], $result );
	}

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testParseSimpleArgsEmptyValue(): void {
		$result = self::callPrivateStatic( 'parseSimpleArgs', [
			[ 'template=' ],
		] );
		$this->assertSame( [ 'template' => '' ], $result );
	}

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testParseSimpleArgsMultipleParams(): void {
		$result = self::callPrivateStatic( 'parseSimpleArgs', [
			[ 'template=MyTpl', 'limit=10', 'where=x > 5' ],
		] );
		$this->assertSame( [
			'template' => 'MyTpl',
			'limit'    => '10',
			'where'    => 'x > 5',
		], $result );
	}

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testParseSimpleArgsAllPositional(): void {
		// All arguments without '=' should produce empty result.
		$result = self::callPrivateStatic( 'parseSimpleArgs', [
			[ 'value1', 'value2', 'value3' ],
		] );
		$this->assertSame( [], $result );
	}

	// ── escapeTemplateParam() — additional edge cases ───────────────────────

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testEscapeTemplateParamEmptyString(): void {
		$result = self::callPrivateStatic( 'escapeTemplateParam', [ '' ] );
		$this->assertSame( '', $result );
	}

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testEscapeTemplateParamDoubleBraceOnly(): void {
		$result = self::callPrivateStatic( 'escapeTemplateParam', [ '}}' ] );
		$this->assertSame( '&#125;&#125;', $result );
	}

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testEscapeTemplateParamSingleBraceNotEscaped(): void {
		// A single '}' should NOT be escaped — only '}}' is dangerous.
		$result = self::callPrivateStatic( 'escapeTemplateParam', [ 'val}more' ] );
		$this->assertSame( 'val}more', $result );
	}

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testEscapeTemplateParamDoubleBraceOpen(): void {
		// '{{' is not dangerous for template param values — only '{{{' is.
		$result = self::callPrivateStatic( 'escapeTemplateParam', [ '{{not_dangerous}}' ] );
		// '{{' is left alone, '}}' is escaped.
		$this->assertSame( '{{not_dangerous&#125;&#125;', $result );
	}

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testEscapeTemplateParamPreservesWikiMarkup(): void {
		// Wiki links in database values should be preserved (intentional design).
		$result = self::callPrivateStatic( 'escapeTemplateParam', [ '[[Some Page]]' ] );
		$this->assertSame( '[[Some Page]]', $result );
	}

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testEscapeTemplateParamMultipleCloseBraces(): void {
		// '}}}}' → two consecutive '}}' sequences → both escaped.
		$result = self::callPrivateStatic( 'escapeTemplateParam', [ '}}}}' ] );
		$this->assertSame( '&#125;&#125;&#125;&#125;', $result );
	}

	// ── formatError() — additional edge cases ───────────────────────────────

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testFormatErrorEmptyMessage(): void {
		$result = self::callPrivateStatic( 'formatError', [ '' ] );
		$this->assertStringContainsString( '<span class="error odbc-error">', $result );
		$this->assertStringContainsString( '</span>', $result );
	}

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testFormatErrorEscapesAmpersand(): void {
		$result = self::callPrivateStatic( 'formatError', [ 'A & B' ] );
		$this->assertStringContainsString( 'A &amp; B', $result );
	}

	/**
	 * @covers ODBCParserFunctions
	 */
	public function testFormatErrorEscapesQuotes(): void {
		$result = self::callPrivateStatic( 'formatError', [ 'Error: "bad" input' ] );
		$this->assertStringContainsString( '&quot;bad&quot;', $result );
	}
}
