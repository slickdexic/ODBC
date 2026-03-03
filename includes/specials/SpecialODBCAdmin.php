<?php
/**
 * Special:ODBCAdmin — Administration page for the ODBC Extension.
 *
 * Provides a web interface for wiki administrators to:
 * - View configured ODBC data sources
 * - Test connectivity to each source
 * - Browse tables and columns (metadata)
 * - Execute test queries (for admins only)
 *
 * @file
 * @ingroup Extensions
 * @license GPL-2.0-or-later
 */

use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;

class SpecialODBCAdmin extends SpecialPage {

	/** Maximum rows returned by a test query from the admin interface. */
	private const ADMIN_QUERY_MAX_ROWS = 100;

	public function __construct() {
		parent::__construct( 'ODBCAdmin', 'odbc-admin' );
	}

	/**
	 * @param string|null $par Subpage parameter.
	 */
	public function execute( $par ) {
		$this->setHeaders();
		$this->checkPermissions();

		$out = $this->getOutput();
		$out->addModuleStyles( 'mediawiki.special' );

		$request = $this->getRequest();
		$action = $request->getVal( 'action', 'list' );
		$sourceId = $request->getVal( 'source', '' );

		// Only state-changing POST actions (runquery) require CSRF token validation.
		// Read-only GET actions (test, tables, columns) do not mutate state and
		// do not require a token — standard MediaWiki practice for read-only admin views.
		if ( $action === 'runquery' ) {
			if ( !$request->wasPosted() || !$this->getUser()->matchEditToken( $request->getVal( 'wpEditToken' ) ) ) {
				$out->addHTML( Html::errorBox(
					$this->msg( 'odbc-error-invalid-token' )->escaped()
				) );
				$this->showSourceList();
				return;
			}
		}

		switch ( $action ) {
			case 'test':
				$this->showTestResult( $sourceId );
				break;
			case 'tables':
				$this->showTables( $sourceId );
				break;
			case 'columns':
				$tableName = $request->getVal( 'table', '' );
				$this->showColumns( $sourceId, $tableName );
				break;
			case 'query':
				$this->showQueryForm( $sourceId );
				break;
			case 'runquery':
				$sql = $request->getVal( 'sql', '' );
				$this->runTestQuery( $sourceId, $sql );
				break;
			default:
				$this->showSourceList();
				break;
		}
	}

	/**
	 * Display the list of all configured ODBC sources.
	 */
	private function showSourceList(): void {
		$out = $this->getOutput();
		$out->setPageTitle( $this->msg( 'odbc-admin-title' ) );

		$sources = ODBCConnectionManager::getSources();

		if ( empty( $sources ) ) {
			$out->addWikiMsg( 'odbc-admin-no-sources' );
			return;
		}

		$out->addWikiMsg( 'odbc-admin-intro' );

		$html = Html::openElement( 'table', [ 'class' => 'wikitable sortable' ] );
		$html .= Html::openElement( 'tr' );
		$html .= Html::element( 'th', [], $this->msg( 'odbc-admin-col-id' )->text() );
		$html .= Html::element( 'th', [], $this->msg( 'odbc-admin-col-driver' )->text() );
		$html .= Html::element( 'th', [], $this->msg( 'odbc-admin-col-server' )->text() );
		$html .= Html::element( 'th', [], $this->msg( 'odbc-admin-col-database' )->text() );
		$html .= Html::element( 'th', [], $this->msg( 'odbc-admin-col-actions' )->text() );
		$html .= Html::closeElement( 'tr' );

		$specialTitle = $this->getPageTitle();
		foreach ( $sources as $id => $config ) {
			$testUrl   = $specialTitle->getLocalURL( [ 'action' => 'test', 'source' => $id ] );
			$tablesUrl = $specialTitle->getLocalURL( [ 'action' => 'tables', 'source' => $id ] );
			$queryUrl  = $specialTitle->getLocalURL( [ 'action' => 'query', 'source' => $id ] );

			$actions  = Html::element( 'a', [ 'href' => $testUrl ],
				$this->msg( 'odbc-admin-action-test' )->text() );
			$actions .= ' | ';
			$actions .= Html::element( 'a', [ 'href' => $tablesUrl ],
				$this->msg( 'odbc-admin-action-tables' )->text() );
			$actions .= ' | ';
			$actions .= Html::element( 'a', [ 'href' => $queryUrl ],
				$this->msg( 'odbc-admin-action-query' )->text() );

			$html .= Html::openElement( 'tr' );
			$html .= Html::rawElement( 'td', [], Html::element( 'strong', [], $id ) );
			$html .= Html::element( 'td', [], $config['driver'] ?? $config['dsn'] ?? 'N/A' );
			// Progress OpenEdge uses 'host' instead of 'server', and 'db' instead of 'database'.
			// Fall back through all known key variants so the admin table is never blank.
			$html .= Html::element( 'td', [], $config['server'] ?? $config['host'] ?? 'N/A' );
			$html .= Html::element( 'td', [], $config['database'] ?? $config['db'] ?? $config['name'] ?? 'N/A' );
			$html .= Html::rawElement( 'td', [], $actions );
			$html .= Html::closeElement( 'tr' );
		}

		$html .= Html::closeElement( 'table' );
		$out->addHTML( $html );
	}

	/**
	 * Test connectivity for a specific source.
	 *
	 * @param string $sourceId
	 */
	private function showTestResult( string $sourceId ): void {
		$out = $this->getOutput();
		$out->setPageTitle( $this->msg( 'odbc-admin-test-title', $sourceId ) );

		$result = ODBCConnectionManager::testConnection( $sourceId );

		if ( $result['success'] ) {
			$out->addHTML( Html::successBox( htmlspecialchars( $result['message'] ) ) );
		} else {
			$out->addHTML( Html::errorBox( htmlspecialchars( $result['message'] ) ) );
		}

		$this->addBackLink();
	}

	/**
	 * Show the list of tables for a data source.
	 *
	 * @param string $sourceId
	 */
	private function showTables( string $sourceId ): void {
		$out = $this->getOutput();
		$out->setPageTitle( $this->msg( 'odbc-admin-tables-title', $sourceId ) );

		try {
			$runner = new ODBCQueryRunner( $sourceId );
			$tables = $runner->getTables();

			if ( empty( $tables ) ) {
				$out->addWikiMsg( 'odbc-admin-no-tables' );
			} else {
				$html = Html::openElement( 'ul' );
				$specialTitle = $this->getPageTitle();
				foreach ( $tables as $table ) {
					$url = $specialTitle->getLocalURL( [
						'action' => 'columns',
						'source' => $sourceId,
						'table'  => $table,
					] );
					$html .= Html::rawElement( 'li', [],
						Html::element( 'a', [ 'href' => $url ], $table )
					);
				}
				$html .= Html::closeElement( 'ul' );
				$out->addHTML( $html );
			}
		} catch ( MWException $e ) {
			$out->addHTML( Html::errorBox( htmlspecialchars( $e->getMessage() ) ) );
		}

		$this->addBackLink();
	}

	/**
	 * Show the columns of a specific table.
	 *
	 * @param string $sourceId
	 * @param string $tableName
	 */
	private function showColumns( string $sourceId, string $tableName ): void {
		$out = $this->getOutput();
		$out->setPageTitle( $this->msg( 'odbc-admin-columns-title', $tableName, $sourceId ) );

		if ( $tableName === '' ) {
			$out->addHTML( Html::errorBox(
				$this->msg( 'odbc-error-no-from' )->escaped()
			) );
			$this->addBackLink();
			return;
		}

		try {
			$runner = new ODBCQueryRunner( $sourceId );
			$columns = $runner->getTableColumns( $tableName );

			if ( empty( $columns ) ) {
				$out->addWikiMsg( 'odbc-admin-no-columns' );
			} else {
				$html = Html::openElement( 'table', [ 'class' => 'wikitable sortable' ] );
				$html .= Html::openElement( 'tr' );
				$html .= Html::element( 'th', [], $this->msg( 'odbc-admin-col-name' )->text() );
				$html .= Html::element( 'th', [], $this->msg( 'odbc-admin-col-type' )->text() );
				$html .= Html::element( 'th', [], $this->msg( 'odbc-admin-col-size' )->text() );
				$html .= Html::element( 'th', [], $this->msg( 'odbc-admin-col-nullable' )->text() );
				$html .= Html::closeElement( 'tr' );
				foreach ( $columns as $col ) {
					$html .= Html::openElement( 'tr' );
					$html .= Html::rawElement( 'td', [],
						Html::element( 'strong', [], $col['name'] )
					);
					$html .= Html::element( 'td', [], $col['type'] );
					$html .= Html::element(
						'td',
						[ 'style' => 'text-align:right' ],
						$col['size'] > 0 ? (string)$col['size'] : ''
					);
					$html .= Html::element( 'td', [], $col['nullable'] );
					$html .= Html::closeElement( 'tr' );
				}
				$html .= Html::closeElement( 'table' );
				$out->addHTML( $html );
			}
		} catch ( MWException $e ) {
			$out->addHTML( Html::errorBox( htmlspecialchars( $e->getMessage() ) ) );
		}

		$this->addBackLink();
	}

	/**
	 * Show the test query form for a data source.
	 *
	 * @param string $sourceId
	 */
	private function showQueryForm( string $sourceId ): void {
		$out = $this->getOutput();
		$out->setPageTitle( $this->msg( 'odbc-admin-query-title', $sourceId ) );

		$specialTitle = $this->getPageTitle();
		$actionUrl = $specialTitle->getLocalURL( [
			'action' => 'runquery',
			'source' => $sourceId
		] );

		$html = Html::openElement( 'form', [ 'method' => 'post', 'action' => $actionUrl ] );
		$html .= Html::element( 'p', [], $this->msg( 'odbc-admin-query-intro' )->text() );
		$html .= Html::textarea( 'sql', '', [
			'rows'        => 6,
			'style'       => 'width: 100%; max-width: 60em; box-sizing: border-box;',
			'placeholder' => 'SELECT * FROM tableName LIMIT 10'
		] );
		$html .= Html::element( 'br' );
		$html .= Html::hidden( 'wpEditToken', $this->getUser()->getEditToken() );
		$html .= Html::element( 'br' );
		$html .= Html::submitButton( $this->msg( 'odbc-admin-query-run' )->text() );
		$html .= Html::closeElement( 'form' );

		$out->addHTML( $html );
		$this->addBackLink();
	}

	/**
	 * Run a test query and display results.
	 *
	 * @param string $sourceId
	 * @param string $sql
	 */
	private function runTestQuery( string $sourceId, string $sql ): void {
		$out = $this->getOutput();
		$out->setPageTitle( $this->msg( 'odbc-admin-query-results-title', $sourceId ) );

		if ( $sql === '' ) {
			$out->addHTML( Html::errorBox(
				$this->msg( 'odbc-error-empty-query' )->escaped()
			) );
			$this->addBackLink();
			return;
		}

		// Only SELECT queries are allowed from the admin interface.
		$trimmedUpper = strtoupper( ltrim( $sql ) );
		if ( strpos( $trimmedUpper, 'SELECT' ) !== 0 ) {
			$out->addHTML( Html::errorBox(
				$this->msg( 'odbc-error-admin-select-only' )->escaped()
			) );
			$this->addBackLink();
			return;
		}

		// Apply the shared SQL sanitizer even for admins as defense-in-depth.
		try {
			ODBCQueryRunner::sanitize( $sql, 'query' );
		} catch ( MWException $e ) {
			$out->addHTML( Html::errorBox( htmlspecialchars( $e->getMessage() ) ) );
			$this->addBackLink();
			return;
		}

		// Respect $wgODBCAllowArbitraryQueries: the odbc-admin permission grants access to this page
		// but does NOT override the arbitrary-query policy. If both the global flag and the
		// per-source allow_queries flag are disabled, the test query is blocked — keeping admin
		// interface behaviour consistent with ODBCQueryRunner::executeComposed() (§2.2).
		$arbitraryConfig = MediaWikiServices::getInstance()->getMainConfig();
		$sourceConf = ODBCConnectionManager::getSourceConfig( $sourceId );
		if ( !$arbitraryConfig->get( 'ODBCAllowArbitraryQueries' ) && empty( $sourceConf['allow_queries'] ) ) {
			$out->addHTML( Html::errorBox(
				$this->msg( 'odbc-error-arbitrary-not-allowed' )->escaped()
			) );
			$this->addBackLink();
			return;
		}

		try {
			$runner = new ODBCQueryRunner( $sourceId );
			$config = $arbitraryConfig;
			$maxRows = min( self::ADMIN_QUERY_MAX_ROWS, $config->get( 'ODBCMaxRows' ) ); // Cap test queries.
			$rows = $runner->executeRawQuery( $sql, [], $maxRows );

			// Show the executed SQL (text-escaped) and row count.
			$out->addHTML( Html::element( 'p', [],
				$this->msg( 'odbc-admin-query-sql' )->text() . ': ' . $sql
			) );
			$out->addHTML( Html::element( 'p', [],
				$this->msg( 'odbc-admin-query-row-count', count( $rows ) )->text()
			) );

			if ( !empty( $rows ) ) {
				$html = Html::openElement( 'table', [ 'class' => 'wikitable' ] );

				// Header row.
				$html .= Html::openElement( 'tr' );
				foreach ( array_keys( $rows[0] ) as $col ) {
					$html .= Html::element( 'th', [], $col );
				}
				$html .= Html::closeElement( 'tr' );

				// Data rows — always use Html::element (auto-escapes) to prevent XSS
				// from database values that may contain HTML or script content.
				foreach ( $rows as $row ) {
					$html .= Html::openElement( 'tr' );
					foreach ( $row as $value ) {
						if ( $value === null ) {
							$html .= Html::rawElement( 'td', [ 'class' => 'odbc-null' ],
								Html::element( 'em', [], 'NULL' )
							);
						} else {
							$html .= Html::element( 'td', [], (string)$value );
						}
					}
					$html .= Html::closeElement( 'tr' );
				}

				$html .= Html::closeElement( 'table' );
				$out->addHTML( $html );
			}

		} catch ( MWException $e ) {
			$out->addHTML( Html::errorBox( htmlspecialchars( $e->getMessage() ) ) );
		}

		$this->addBackLink();
	}

	/**
	 * Add a back-to-list link.
	 */
	private function addBackLink(): void {
		$specialTitle = $this->getPageTitle();
		$this->getOutput()->addHTML(
			Html::rawElement( 'p', [],
				Html::element( 'a', [ 'href' => $specialTitle->getLocalURL() ],
					$this->msg( 'odbc-admin-back' )->text()
				)
			)
		);
	}
}
