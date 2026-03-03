<?php
/**
 * PHPUnit bootstrap for the MediaWiki ODBC Extension.
 *
 * Provides lightweight stubs for MediaWiki classes and global functions
 * so that unit tests can run without a full MediaWiki installation.
 *
 * Only the API surface actually used by the tested code is stubbed here.
 * Tests that require a full MediaWiki environment (integration tests)
 * should use MediaWiki's own test infrastructure instead.
 *
 * @internal — Test infrastructure only.
 * @license GPL-2.0-or-later
 */

// ── Prevent double-loading ──────────────────────────────────────────────────
if ( defined( 'ODBC_TEST_BOOTSTRAP_LOADED' ) ) {
	return;
}
define( 'ODBC_TEST_BOOTSTRAP_LOADED', true );

// ── Composer autoloader ─────────────────────────────────────────────────────
$autoloader = __DIR__ . '/../vendor/autoload.php';
if ( file_exists( $autoloader ) ) {
	require_once $autoloader;
}

// ── MediaWiki global function stubs ─────────────────────────────────────────
// These provide the minimal signatures that the extension code calls.

if ( !function_exists( 'wfMessage' ) ) {
	/**
	 * Stub for MediaWiki's wfMessage().
	 * Returns a Message object whose ->text() returns the key + params for assertion.
	 *
	 * @param string $key
	 * @param mixed ...$params
	 * @return Message
	 */
	function wfMessage( string $key, ...$params ): Message {
		return new Message( $key, $params );
	}
}

if ( !function_exists( 'wfDebugLog' ) ) {
	/**
	 * Stub for MediaWiki's wfDebugLog() — no-op in tests.
	 *
	 * @param string $logGroup
	 * @param string $message
	 * @param string $dest
	 * @param array $context
	 */
	function wfDebugLog( string $logGroup, string $message, string $dest = 'default', array $context = [] ): void {
		// No-op in unit tests.
	}
}

if ( !function_exists( 'wfDeprecated' ) ) {
	/**
	 * Stub for MediaWiki's wfDeprecated() — no-op in tests.
	 *
	 * @param string $function
	 * @param string $version
	 * @param string|false $component
	 * @param int $callerOffset
	 */
	function wfDeprecated( string $function, string $version = '', $component = false, int $callerOffset = 2 ): void {
		// No-op in unit tests.
	}
}

// ── MediaWiki class stubs ───────────────────────────────────────────────────

if ( !class_exists( 'Message' ) ) {
	/**
	 * Minimal stub for MediaWiki's Message class.
	 */
	class Message {
		/** @var string */
		private $key;
		/** @var array */
		private $params;

		public function __construct( string $key = '', array $params = [] ) {
			$this->key = $key;
			$this->params = $params;
		}

		public function text(): string {
			if ( empty( $this->params ) ) {
				return "{{$this->key}}";
			}
			return "{{$this->key}: " . implode( ', ', array_map( 'strval', $this->params ) ) . '}';
		}

		public function escaped(): string {
			return htmlspecialchars( $this->text() );
		}

		public function parse(): string {
			return $this->text();
		}

		public function plain(): string {
			return $this->text();
		}

		public function __toString(): string {
			return $this->text();
		}
	}
}

if ( !class_exists( 'MWException' ) ) {
	/**
	 * Minimal stub for MediaWiki's MWException.
	 */
	class MWException extends Exception {
	}
}

if ( !class_exists( 'ExtensionRegistry' ) ) {
	/**
	 * Minimal stub for ExtensionRegistry.
	 */
	class ExtensionRegistry {
		private static ?ExtensionRegistry $instance = null;

		public static function getInstance(): self {
			if ( self::$instance === null ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		public function isLoaded( string $name ): bool {
			return false;
		}
	}
}

// ── Load extension source files ─────────────────────────────────────────────
// Order matters: ODBCConnectionManager must be loaded before ODBCQueryRunner
// (which references it), and ODBCQueryRunner before ODBCParserFunctions.

$extensionRoot = dirname( __DIR__ );
require_once $extensionRoot . '/includes/ODBCConnectionManager.php';
require_once $extensionRoot . '/includes/ODBCQueryRunner.php';
require_once $extensionRoot . '/includes/ODBCParserFunctions.php';
require_once $extensionRoot . '/includes/ODBCHooks.php';
