<?php
/**
 * Parser functions for the MediaWiki ODBC Extension.
 *
 * Provides:
 *   {{#odbc_query:}}     — Fetch data from an ODBC source, store in page variables.
 *   {{#odbc_value:}}     — Display a single stored variable value.
 *   {{#for_odbc_table:}} — Loop over stored rows with inline template text.
 *   {{#display_odbc_table:}} — Loop over stored rows using a wiki template.
 *   {{#odbc_clear:}}     — Clear stored ODBC data.
 *
 * These mirror the External Data extension's patterns but work independently.
 *
 * @file
 * @ingroup Extensions
 * @license GPL-2.0-or-later
 */

use MediaWiki\MediaWikiServices;

class ODBCParserFunctions {

	/**
	 * Property key used to store ODBC data on the ParserOutput object.
	 * This ensures data is scoped per-page-parse and does not leak across
	 * different pages parsed in the same PHP request.
	 */
	private const PARSER_OUTPUT_KEY = 'ODBCData';

	/**
	 * Property key used to track the number of {{#odbc_query:}} calls on this page
	 * render, allowing enforcement of $wgODBCMaxQueriesPerPage (KI-018).
	 */
	private const PARSER_OUTPUT_QUERY_COUNT_KEY = 'ODBCQueryCount';

	/**
	 * Get stored ODBC data for the current parse.
	 *
	 * @param Parser $parser
	 * @return array
	 */
	private static function getStoredData( Parser $parser ): array {
		$output = $parser->getOutput();
		$data = $output->getExtensionData( self::PARSER_OUTPUT_KEY );
		if ( $data === null ) {
			$data = [];
		}
		return $data;
	}

	/**
	 * Save stored ODBC data back to the ParserOutput.
	 *
	 * @param Parser $parser
	 * @param array $data
	 */
	private static function setStoredData( Parser $parser, array $data ): void {
		$parser->getOutput()->setExtensionData( self::PARSER_OUTPUT_KEY, $data );
	}

	/**
	 * Get the number of {{#odbc_query:}} calls already made on this page render.
	 *
	 * @param Parser $parser
	 * @return int
	 */
	private static function getQueryCount( Parser $parser ): int {
		return (int)( $parser->getOutput()->getExtensionData( self::PARSER_OUTPUT_QUERY_COUNT_KEY ) ?? 0 );
	}

	/**
	 * Increment the per-page {{#odbc_query:}} call counter.
	 *
	 * @param Parser $parser
	 */
	private static function incrementQueryCount( Parser $parser ): void {
		$parser->getOutput()->setExtensionData(
			self::PARSER_OUTPUT_QUERY_COUNT_KEY,
			self::getQueryCount( $parser ) + 1
		);
	}

	/**
	 * {{#odbc_query:}} — Retrieve data from an ODBC source.
	 *
	 * Usage:
	 *   {{#odbc_query: source=mydb | from=tableName | data=localVar=dbCol,... | where=... | order by=... | limit=... }}
	 *   {{#odbc_query: source=mydb | query=statementName | parameters=val1,val2 | data=localVar=dbCol,... }}
	 *
	 * Uses SFH_OBJECT_ARGS, so must return array.
	 *
	 * @param Parser $parser The parser object.
	 * @param PPFrame $frame The frame object.
	 * @param array $args The arguments (PPNode objects).
	 * @return array [ text, 'noparse' => true, 'isHTML' => true ] for SFH_OBJECT_ARGS.
 *   Success returns empty string; error returns are pre-rendered HTML spans.
	 */
	public static function odbcQuery( Parser $parser, PPFrame $frame, array $args ): array {
		// Check permission.
		$user = $parser->getUserIdentity();
		$permManager = MediaWikiServices::getInstance()->getPermissionManager();
		if ( !$permManager->userHasRight( $user, 'odbc-query' ) ) {
			return [ self::formatError( wfMessage( 'odbc-error-permission' )->text() ), 'noparse' => true, 'isHTML' => true ];
		}

		// Enforce per-page query limit ($wgODBCMaxQueriesPerPage). A limit of 0 means
		// no cap (default, backward-compatible). This prevents a single page from
		// issuing an unbounded number of database queries and exhausting server resources
		// (KI-018).
		$mainConfig = MediaWikiServices::getInstance()->getMainConfig();
		$maxQueriesPerPage = (int)$mainConfig->get( 'ODBCMaxQueriesPerPage' );
		if ( $maxQueriesPerPage > 0 && self::getQueryCount( $parser ) >= $maxQueriesPerPage ) {
			return [
				self::formatError( wfMessage( 'odbc-error-too-many-queries', $maxQueriesPerPage )->text() ),
				'noparse' => true,
				'isHTML' => true,
			];
		}
		self::incrementQueryCount( $parser );

		// Parse named arguments from PPNode objects.
		$params = self::parseArgs( $args, $frame );

		// Validate required parameter: source ID.
		// Accepted as named ('source=mydb') or as the first positional argument:
		// '{{#odbc_query: mydb | from=...}}' is equivalent to
		// '{{#odbc_query: source=mydb | from=...}}'.  This positional form is intentional
		// for brevity but is undocumented in templates to avoid confusion (§5.3 / P2-060).
		$sourceId = $params['source'] ?? ( $params[0] ?? '' );
		if ( $sourceId === '' ) {
			return [ self::formatError( wfMessage( 'odbc-error-no-source' )->text() ), 'noparse' => true, 'isHTML' => true ];
		}

		// Check if suppress error is set.
		$suppressError = isset( $params['suppress error'] ) || isset( $params['suppress_error'] );

		try {
			$runner = new ODBCQueryRunner( $sourceId );

			// Parse data mappings: localVar=dbCol,localVar2=dbCol2,...
			$dataMappings = self::parseDataMappings( $params['data'] ?? '' );

			// Determine columns to select.
			$columns = [];
			if ( !empty( $dataMappings ) ) {
				foreach ( $dataMappings as $local => $db ) {
					$columns[$local] = $db;
				}
			}

			// Route to prepared statement or composed query.
			$queryName = $params['query'] ?? '';
			$prepared = $params['prepared'] ?? '';

			if ( $queryName !== '' || $prepared !== '' ) {
				// Prepared statement mode.
				$stmtName = $queryName !== '' ? $queryName : $prepared;
				// Allow a custom separator for parameter values that contain commas.
				// Default separator is ',' — use separator= to override (e.g. separator=| for names like 'Smith, John').
				$separator = isset( $params['separator'] ) && $params['separator'] !== '' ? $params['separator'] : ',';
				$parameters = isset( $params['parameters'] )
					? array_map( 'trim', explode( $separator, $params['parameters'] ) )
					: [];
				$rows = $runner->executePrepared( $stmtName, $parameters );
			} else {
				// Composed query mode.
				$from = $params['from'] ?? '';
				if ( $from === '' ) {
					if ( $suppressError ) {
						return [ '', 'noparse' => true ];
					}
					return [ self::formatError( wfMessage( 'odbc-error-no-from' )->text() ), 'noparse' => true, 'isHTML' => true ];
				}

				$dbColumns = !empty( $columns ) ? $columns : [ '*' => '*' ];
				if ( empty( $columns ) ) {
					// KI-008: No data= parameter provided — SELECT * will be issued and ALL columns
					// returned from the database will be stored in parser variables. This can
					// unintentionally expose sensitive columns (e.g. passwords, PII) and consume
					// more memory than necessary. Always specify explicit data= mappings.
					wfDebugLog( 'odbc', "SELECT * issued for table '$from' on source '$sourceId'" .
						" — no data= mappings specified in {{#odbc_query:}} (KI-008)." );
				}
				$where = $params['where'] ?? '';
				$orderBy = $params['order by'] ?? $params['order_by'] ?? '';
				$groupBy = $params['group by'] ?? $params['group_by'] ?? '';
				$having = $params['having'] ?? '';
				$limit = isset( $params['limit'] ) ? (int)$params['limit'] : 0;

				$rows = $runner->executeComposed(
					$from, $dbColumns, $where, $orderBy, $groupBy, $having, $limit
				);
			}

			// Store the results in per-parse scoped variables.
			$storedData = self::getStoredData( $parser );
			self::mergeResults( $storedData, $rows, $dataMappings );
			self::setStoredData( $parser, $storedData );

		} catch ( MWException $e ) {
			if ( $suppressError ) {
				return [ '', 'noparse' => true ];
			}
			return [ self::formatError( $e->getMessage() ), 'noparse' => true, 'isHTML' => true ];
		}

		return [ '', 'noparse' => true ];
	}

	/**
	 * {{#odbc_value:}} — Display a single variable from the stored ODBC data.
	 *
	 * Usage: {{#odbc_value:variableName}} or {{#odbc_value:variableName|default}}
	 *
	 * Returns the first value in the variable's array, or the default.
	 *
	 * @param Parser $parser
	 * @param string $varName Variable name.
	 * @param string $default Default value if variable is not set.
	 * @return string The value.
	 */
	/**
	 * {{#odbc_value:}} — Retrieve a single stored value by variable name.
	 *
	 * Usage:
	 *   {{#odbc_value: varName | default | rowParam }}
	 *
	 * @param Parser $parser
	 * @param string $varName  Variable name set by data= mapping in {{#odbc_query:}}.
	 * @param string $default  Value returned when varName is absent or row is out of range.
	 * @param string $rowParam Row selector (KI-019). Accepts:
	 *                         - omitted / empty / "0" → first row (backward-compatible default)
	 *                         - positive integer, e.g. "2" → that row (1-indexed)
	 *                         - "last"                  → final row
	 *                         - "row=N" / "row=last"    → named-parameter alias
	 *                         Out-of-range integers silently return $default.
	 * @return string
	 */
	public static function odbcValue( Parser $parser, string $varName = '', string $default = '', string $rowParam = '' ): string {
		$varName = strtolower( trim( $varName ) );
		if ( $varName === '' ) {
			return '';
		}
		$storedData = self::getStoredData( $parser );
		$values = $storedData[$varName] ?? [];
		$count = count( $values );
		if ( $count === 0 ) {
			return $default;
		}

		// Determine which row to return. Support both positional ("2", "last") and
		// named ("row=2", "row=last") forms so wikitext reads naturally (KI-019).
		$rowParam = strtolower( trim( $rowParam ) );
		if ( substr( $rowParam, 0, 4 ) === 'row=' ) {
			$rowParam = substr( $rowParam, 4 );
		}

		if ( $rowParam === 'last' ) {
			$index = $count - 1;
		} elseif ( $rowParam === '' || $rowParam === '0' ) {
			$index = 0; // Default: first row (unchanged behaviour)
		} else {
			$n = (int)$rowParam;
			if ( $n < 1 || $n > $count ) {
				return $default; // Out-of-range → silent fallback (KI-019)
			}
			$index = $n - 1;
		}

		return (string)( $values[$index] ?? $default );
	}

	/**
	 * {{#for_odbc_table:}} — Loop over all rows, expanding template text for each.
	 *
	 * Usage:
	 *   {{#for_odbc_table:
	 *     {{{col1}}} - {{{col2}}}
	 *   }}
	 *
	 * The text between the function tags is repeated for each row, with
	 * {{{variableName}}} replaced by the row's value for that variable.
	 *
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @param array $args
	 * @return array [ processed wikitext, 'noparse' => false ]
	 */
	public static function forOdbcTable( Parser $parser, PPFrame $frame, array $args ): array {
		// Use NO_ARGS | NO_TEMPLATES so that {{{var}}} triple-brace placeholders
		// survive frame expansion and are available for our str_replace loop.
		// Without this, if the page is transcluded with matching parameter names,
		// the frame would resolve them before we get a chance to substitute.
		$templateText = isset( $args[0] )
			? trim( $frame->expand( $args[0], PPFrame::NO_ARGS | PPFrame::NO_TEMPLATES ) )
			: '';

		$storedData = self::getStoredData( $parser );

		if ( $templateText === '' || empty( $storedData ) ) {
			return [ '', 'noparse' => false ];
		}

		// Determine the number of rows from the stored data.
		$rowCount = 0;
		foreach ( $storedData as $values ) {
			$rowCount = max( $rowCount, count( $values ) );
		}

		$output = '';
		for ( $i = 0; $i < $rowCount; $i++ ) {
			$rowText = $templateText;
			foreach ( $storedData as $varName => $values ) {
				$value = (string)( $values[$i] ?? '' );
				// Prevent fake {{{varName}}} placeholders in db values from being
				// resolved as further substitutions in the next str_replace call.
				$escapedValue = str_replace( '{{{', '&#123;&#123;&#123;', $value );
				$rowText = str_replace( '{{{' . $varName . '}}}', $escapedValue, $rowText );
			}
			$output .= $rowText;
		}

		return [ $output, 'noparse' => false ];
	}

	/**
	 * {{#display_odbc_table:}} — Display rows using a wiki template.
	 *
	 * Usage:
	 *   {{#display_odbc_table: template=MyTemplate }}
	 *
	 * Each row is rendered as {{MyTemplate|col1=val1|col2=val2|...}}.
	 *
	 * @param Parser $parser
	 * @param string ...$params Named parameters (template=..., etc.)
	 * @return string Expanded wikitext.
	 */
	public static function displayOdbcTable( Parser $parser, ...$params ): string {
		$namedParams = self::parseSimpleArgs( $params );

		$templateName = $namedParams['template'] ?? '';
		if ( $templateName === '' ) {
			return self::formatError( wfMessage( 'odbc-error-no-template' )->text() );
		}

		$storedData = self::getStoredData( $parser );

		if ( empty( $storedData ) ) {
			return '';
		}

		// Determine row count.
		$rowCount = 0;
		foreach ( $storedData as $values ) {
			$rowCount = max( $rowCount, count( $values ) );
		}

		$output = '';
		for ( $i = 0; $i < $rowCount; $i++ ) {
			$templateCall = '{{' . $templateName;
			foreach ( $storedData as $varName => $values ) {
				$value = (string)( $values[$i] ?? '' );
				// Escape the value so that pipe chars and template-close sequences
				// in database values cannot inject extra template parameters or
				// prematurely close the template call.
				$templateCall .= '|' . $varName . '=' . self::escapeTemplateParam( $value );
			}
			$templateCall .= '}}';
			$output .= $templateCall . "\n";
		}

		return $output;
	}

	/**
	 * {{#odbc_clear:}} — Clear stored ODBC data, optionally for specific variables.
	 *
	 * Usage:
	 *   {{#odbc_clear:}}          — Clear all data.
	 *   {{#odbc_clear:var1,var2}} — Clear specific variables.
	 *
	 * @param Parser $parser
	 * @param string $vars Comma-separated list of variables to clear, or empty for all.
	 * @return string Empty string.
	 */
	public static function odbcClear( Parser $parser, string $vars = '' ): string {
		if ( $vars === '' ) {
			self::setStoredData( $parser, [] );
		} else {
			$storedData = self::getStoredData( $parser );
			$varList = array_map( 'trim', explode( ',', $vars ) );
			foreach ( $varList as $var ) {
				$var = strtolower( $var );
				unset( $storedData[$var] );
			}
			self::setStoredData( $parser, $storedData );
		}
		return '';
	}

	/**
	 * Merge query results into the stored data array.
	 *
	 * @param array &$storedData Reference to the stored data array.
	 * @param array $rows The query result rows.
	 * @param array $mappings Variable-to-column mappings [ 'localVar' => 'dbCol' ].
	 *                        If empty, all columns are stored using lowercase names.
	 */
	private static function mergeResults( array &$storedData, array $rows, array $mappings ): void {
		foreach ( $rows as $row ) {
			// Build a case-insensitive lookup map for this row once (O(cols) per row).
			// This avoids the O(cols) inner scan per mapping that the previous code used.
			$rowLower = [];
			foreach ( $row as $key => $val ) {
				$rowLower[ strtolower( $key ) ] = $val;
			}

			if ( !empty( $mappings ) ) {
				foreach ( $mappings as $localVar => $dbCol ) {
					$localVar = strtolower( $localVar );
					if ( !isset( $storedData[$localVar] ) ) {
						$storedData[$localVar] = [];
					}
					$value = $rowLower[ strtolower( $dbCol ) ] ?? '';
					$storedData[$localVar][] = (string)$value;
				}
			} else {
				// No explicit mapping — store all columns using their lowercase names.
				foreach ( $rowLower as $varName => $value ) {
					if ( !isset( $storedData[$varName] ) ) {
						$storedData[$varName] = [];
					}
					$storedData[$varName][] = (string)( $value ?? '' );
				}
			}
		}
	}

	/**
	 * Parse arguments from PPNode array into named parameters.
	 *
	 * @param array $args Array of PPNode objects.
	 * @param PPFrame $frame The parser frame.
	 * @return array Parsed parameter array.
	 */
	private static function parseArgs( array $args, PPFrame $frame ): array {
		$params = [];
		$positional = 0;
		foreach ( $args as $arg ) {
			$expanded = trim( $frame->expand( $arg ) );
			$eqPos = strpos( $expanded, '=' );
			if ( $eqPos !== false ) {
				$key = strtolower( trim( substr( $expanded, 0, $eqPos ) ) );
				$value = trim( substr( $expanded, $eqPos + 1 ) );
				$params[$key] = $value;
			} else {
				$params[$positional] = $expanded;
				$positional++;
			}
		}
		return $params;
	}

	/**
	 * Parse simple string arguments (from variadic parser function args).
	 *
	 * @param array $args String arguments.
	 * @return array Parsed parameter array.
	 */
	private static function parseSimpleArgs( array $args ): array {
		$params = [];
		foreach ( $args as $arg ) {
			$eqPos = strpos( $arg, '=' );
			if ( $eqPos !== false ) {
				$key = strtolower( trim( substr( $arg, 0, $eqPos ) ) );
				$value = trim( substr( $arg, $eqPos + 1 ) );
				$params[$key] = $value;
			}
		}
		return $params;
	}

	/**
	 * Parse the data= parameter into a mapping array.
	 *
	 * Format: "localVar1=dbCol1,localVar2=dbCol2,..."
	 * If a mapping has no '=', the variable name equals the column name (lowercased).
	 *
	 * @param string $data The data parameter string.
	 * @return array [ 'localVar' => 'dbCol', ... ]
	 */
	private static function parseDataMappings( string $data ): array {
		$mappings = [];
		if ( $data === '' ) {
			return $mappings;
		}

		$pairs = array_map( 'trim', explode( ',', $data ) );
		foreach ( $pairs as $pair ) {
			if ( $pair === '' ) {
				continue;
			}
			// Limit individual mapping length to prevent abuse. Log when a mapping is dropped
			// so editors can diagnose unexplained missing variables (§5.6).
			if ( strlen( $pair ) > 256 ) {
				wfDebugLog( 'odbc', 'parseDataMappings: dropping oversized mapping pair (' .
					strlen( $pair ) . ' chars > 256 limit) in data= parameter; check your template: \'' .
					substr( $pair, 0, 80 ) . '\'' );
				continue;
			}
			$eqPos = strpos( $pair, '=' );
			if ( $eqPos !== false ) {
				$local = trim( substr( $pair, 0, $eqPos ) );
				$db = trim( substr( $pair, $eqPos + 1 ) );
				// Validate lengths.
				if ( strlen( $local ) > 0 && strlen( $local ) <= 64 && 
				     strlen( $db ) > 0 && strlen( $db ) <= 64 ) {
					$mappings[$local] = $db;
				}
			} else {
				if ( strlen( $pair ) > 0 && strlen( $pair ) <= 64 ) {
					$mappings[$pair] = $pair;
				}
			}
		}
		return $mappings;
	}

	/**
	 * Escape a database value for safe inclusion as a template parameter value in wikitext.
	 *
	 * Protects against:
	 * - '|' injecting additional template parameters (replaced with {{!}} magic word, MW 1.24+)
	 * - '}}' prematurely closing the template call (replaced with HTML entities)
	 * - '{{{' fake triple-brace parameters (replaced with HTML entities)
	 *
	 * Note: wiki markup such as [[links]] in values will still be rendered, which is
	 * intentional — admins control the data source and may want formatted output.
	 *
	 * @param string $value The raw database value.
	 * @return string The escaped value, safe to embed inside a {{Template|param=VALUE}} call.
	 */
	private static function escapeTemplateParam( string $value ): string {
		return str_replace(
			[ '|',     '}}',         '{{{' ],
			[ '{{!}}', '&#125;&#125;', '&#123;&#123;&#123;' ],
			$value
		);
	}

	/**
	 * Format an error message in MediaWiki style.
	 *
	 * @param string $msg The error message.
	 * @return string HTML error string.
	 */
	private static function formatError( string $msg ): string {
		return '<span class="error odbc-error">' . htmlspecialchars( $msg ) . '</span>';
	}
}
