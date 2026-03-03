<?php
/**
 * Magic word definitions for the MediaWiki ODBC Extension.
 *
 * @file
 * @ingroup Extensions
 * @license GPL-2.0-or-later
 */

$magicWords = [];

/**
 * English (en)
 *
 * The first element (0) means case-insensitive: {{#odbc_query:}}, {{#ODBC_QUERY:}},
 * and {{#Odbc_Query:}} all resolve to the same parser function.
 */
$magicWords['en'] = [
	'odbc_query'         => [ 0, 'odbc_query' ],
	'odbc_value'         => [ 0, 'odbc_value' ],
	'for_odbc_table'     => [ 0, 'for_odbc_table' ],
	'display_odbc_table' => [ 0, 'display_odbc_table' ],
	'odbc_clear'         => [ 0, 'odbc_clear' ],
];
