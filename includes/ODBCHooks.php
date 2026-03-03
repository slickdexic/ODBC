<?php
/**
 * Hooks for the MediaWiki ODBC Extension.
 *
 * Registers parser functions and integrates with External Data if available.
 *
 * @file
 * @ingroup Extensions
 * @license GPL-2.0-or-later
 */

use MediaWiki\MediaWikiServices;

class ODBCHooks {

	/**
	 * Extension registration callback.
	 *
	 * Called once when the extension is loaded via the 'ExtensionRegistration' hook
	 * registered in extension.json. Used for one-time setup like External Data connector
	 * registration.
	 */
	public static function onRegistration(): void {
		self::registerExternalDataConnector();
	}

	/**
	 * Register parser functions.
	 *
	 * @param Parser $parser The MediaWiki parser.
	 */
	public static function onParserFirstCallInit( Parser $parser ): void {
		$parser->setFunctionHook(
			'odbc_query',
			[ 'ODBCParserFunctions', 'odbcQuery' ],
			Parser::SFH_OBJECT_ARGS
		);
		$parser->setFunctionHook(
			'odbc_value',
			[ 'ODBCParserFunctions', 'odbcValue' ]
		);
		$parser->setFunctionHook(
			'for_odbc_table',
			[ 'ODBCParserFunctions', 'forOdbcTable' ],
			Parser::SFH_OBJECT_ARGS
		);
		$parser->setFunctionHook(
			'display_odbc_table',
			[ 'ODBCParserFunctions', 'displayOdbcTable' ]
		);
		$parser->setFunctionHook(
			'odbc_clear',
			[ 'ODBCParserFunctions', 'odbcClear' ]
		);
	}

	/**
	 * If External Data extension is installed and integration is enabled,
	 * register our generic ODBC connector as an additional connector type.
	 *
	 * This runs once at extension registration time, NOT per-parse.
	 */
	private static function registerExternalDataConnector(): void {
		// At registration time, MainConfig is not available yet and
		// extension.json defaults have not been merged into globals.
		// We check the global: if the user has explicitly set it to a falsy value
		// (false, 0, '', null) in LocalSettings.php, respect that and skip registration.
		// Otherwise, default to enabled (matching extension.json default).
		global $wgODBCExternalDataIntegration;
		if ( !$wgODBCExternalDataIntegration ) {
			return;
		}

		// Check if External Data extension is loaded.
		if ( !\ExtensionRegistry::getInstance()->isLoaded( 'External Data' ) ) {
			return;
		}

		// Inject our connector into $wgExternalDataConnectors.
		// This allows External Data's #get_db_data with type=odbc_generic
		// to route through our connector.
		global $wgExternalDataConnectors;
		if ( !isset( $wgExternalDataConnectors ) ) {
			$wgExternalDataConnectors = [];
		}

		$wgExternalDataConnectors[] = [
			'__class' => 'EDConnectorOdbcGeneric',
			'__pf' => [ 'get_db_data', 'get_external_data' ],
			'type' => 'odbc_generic'
		];
	}
}
