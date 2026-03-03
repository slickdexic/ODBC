<?php
/**
 * MediaWiki stub declarations for PHPStan static analysis.
 *
 * These are type-skeleton stubs only — they provide class/method/function
 * signatures and return types so PHPStan can analyse MediaWiki extension
 * code without requiring a full MediaWiki installation in the development
 * environment.  Method bodies are minimal no-ops; no real behaviour is
 * implemented here.
 *
 * Loaded as a PHPStan bootstrap file (see phpstan.neon).
 *
 * Coverage reflects the exact MediaWiki API surface used by this extension
 * (ODBCConnectionManager, ODBCQueryRunner, ODBCParserFunctions,
 *  ODBCHooks, SpecialODBCAdmin, EDConnectorOdbcGeneric).
 *
 * @see https://phpstan.org/user-guide/extension-library#bootstrapFiles
 * @internal — Development tooling only; not part of the extension runtime.
 * @license GPL-2.0-or-later
 */

declare( strict_types=0 );

// ── Global functions ──────────────────────────────────────────────────────

/**
 * Look up a MediaWiki i18n message.
 *
 * @param string $key      The message key.
 * @param mixed  ...$params Variable substitution parameters ($1, $2, …).
 */
function wfMessage( string $key, ...$params ): Message {
	return new Message();
}

/**
 * Write an entry to a named log group (routed by $wgDebugLogGroups).
 *
 * @param string $logGroup   Log channel name.
 * @param string $message    The message to write.
 * @param string $dest       Destination ('default', 'all', or 'none').
 * @param array  $context    PSR-3 context array.
 */
function wfDebugLog( string $logGroup, string $message, string $dest = 'default', array $context = [] ): void {
}

/**
 * Mark a function/method as deprecated and emit a runtime warning.
 *
 * @param string       $function     The deprecated function name (use __METHOD__).
 * @param string|false $version      Version when first deprecated.
 * @param string|false $component    Component name (e.g. 'ODBC').
 * @param int          $callerOffset Stack-frame offset for the caller location.
 */
function wfDeprecated( string $function, $version = false, $component = false, int $callerOffset = 2 ): void {
}

// ── Global classes ────────────────────────────────────────────────────────

/**
 * MediaWiki exception class — extends RuntimeException so it is always
 * a Throwable and can be caught with catch ( MWException $e ).
 */
class MWException extends RuntimeException {
}

/**
 * MediaWiki i18n message object returned by wfMessage() and msg().
 */
class Message {
	/** Output the message as plain, unescaped text. */
	public function text(): string {
		return '';
	}

	/** Output the message as HTML-escaped text. */
	public function escaped(): string {
		return '';
	}

	/** Output the message as plain text without parsing. */
	public function plain(): string {
		return '';
	}

	/** Output the message as a parsed HTML block. */
	public function parseAsBlock(): string {
		return '';
	}

	/** Output the message as parsed inline HTML. */
	public function parse(): string {
		return '';
	}

	/**
	 * Substitute positional parameters.
	 * @param mixed ...$args
	 */
	public function params( ...$args ): self {
		return $this;
	}

	/**
	 * Substitute positional numeric parameters (formatted as numbers).
	 * @param int ...$args
	 */
	public function numParams( ...$args ): self {
		return $this;
	}

	/** Use content-language rather than interface language for output. */
	public function inContentLanguage(): self {
		return $this;
	}
}

/**
 * MediaWiki object cache (key–value store).
 */
class BagOStuff {
	/**
	 * Generate a cache key scoped to this wiki.
	 *
	 * @param string $class   Base name component.
	 * @param string ...$components Additional components.
	 */
	public function makeKey( string $class, ...$components ): string {
		return '';
	}

	/**
	 * Fetch a cached value.
	 *
	 * @param string $key
	 * @return mixed The stored value, or false on cache miss.
	 */
	public function get( string $key ) {
		return false;
	}

	/**
	 * Store a value in the cache.
	 *
	 * @param string $key
	 * @param mixed  $value
	 * @param int    $exptime TTL in seconds (0 = no expiry).
	 */
	public function set( string $key, $value, int $exptime = 0 ): bool {
		return false;
	}
}

/**
 * Factory for well-known cache instances.
 */
class ObjectCache {
	/** Get the local-cluster cache (best for per-page or per-request results). */
	public static function getLocalClusterInstance(): BagOStuff {
		return new BagOStuff();
	}
}

/**
 * Metadata about a parsed page, scoped to the current parse.
 */
class ParserOutput {
	/**
	 * Retrieve arbitrary extension data stored under $key.
	 *
	 * @param string $key
	 * @return mixed The stored value, or null if not set.
	 */
	public function getExtensionData( string $key ) {
		return null;
	}

	/**
	 * Store arbitrary extension data.
	 *
	 * @param string $key
	 * @param mixed  $value
	 */
	public function setExtensionData( string $key, $value ): void {
	}
}

/**
 * A node in the preprocessed parse tree (used with SFH_OBJECT_ARGS).
 */
class PPNode {
}

/**
 * A template expansion frame (used with SFH_OBJECT_ARGS).
 */
class PPFrame {
	/**
	 * Expand a parse-tree node or string.
	 *
	 * @param mixed $root  The node or string to expand.
	 * @param int   $flags Expansion flags.
	 */
	public function expand( $root, int $flags = 0 ): string {
		return '';
	}
}

/**
 * The MediaWiki parser — passed to SFH_OBJECT_ARGS and standard hook callbacks.
 */
class Parser {
	/** Flag for setFunctionHook(): pass arguments as PPNode objects, not pre-expanded strings. */
	public const SFH_OBJECT_ARGS = 16;

	/** Get the UserIdentity of the user triggering this parse. */
	public function getUserIdentity(): \MediaWiki\User\UserIdentity {
		return new \MediaWiki\User\UserIdentity();
	}

	/** Get the ParserOutput for the current parse. */
	public function getOutput(): ParserOutput {
		return new ParserOutput();
	}

	/**
	 * Register a parser function hook.
	 *
	 * @param string   $id       Function name (without the '#' prefix).
	 * @param callable $callback The handler.
	 * @param int      $flags    SFH_* flags.
	 */
	public function setFunctionHook( string $id, callable $callback, int $flags = 0 ): void {
	}
}

/**
 * A logged-in or anonymous wiki user.
 */
class User {
	/** Check whether the user has a specific right. */
	public function isAllowed( string $right ): bool {
		return false;
	}

	/**
	 * Validate a CSRF edit token.
	 *
	 * @param mixed $val    The token value to validate (string or null).
	 * @param mixed $salt   Optional salt.
	 * @param mixed $request Optional WebRequest (uses global if null).
	 * @param mixed $maxage Optional max age in seconds.
	 */
	public function matchEditToken( $val = null, $salt = '', $request = null, $maxage = null ): bool {
		return false;
	}

	/**
	 * Get a CSRF edit token string.
	 *
	 * @param string $salt Optional salt.
	 */
	public function getEditToken( string $salt = '' ): string {
		return '';
	}
}

/**
 * The HTML output buffer for a MediaWiki page response.
 */
class OutputPage {
	/** Set the page title (accepts string or Message). */
	public function setPageTitle( $name ): void {
	}

	/** Append raw HTML to the output. */
	public function addHTML( string $text ): void {
	}

	/** Queue ResourceLoader module styles. */
	public function addModuleStyles( $modules ): void {
	}

	/** Queue ResourceLoader modules (JS + CSS). */
	public function addModules( $modules ): void {
	}

	/** Add a wiki-text message (looked up from the i18n system). */
	public function addWikiMsg( string $name, ...$args ): void {
	}

	/** Add a wrapper wiki-text message with substitution. */
	public function wrapWikiMsg( string $wrap, ...$msgSpecs ): void {
	}
}

/**
 * Encapsulates the incoming HTTP request.
 */
class WebRequest {
	/**
	 * Get a string query/POST value.
	 *
	 * @param string $name    Parameter name.
	 * @param mixed  $default Default if not present.
	 * @return mixed
	 */
	public function getVal( string $name, $default = null ) {
		return $default;
	}

	/** Get a string query/POST value (never returns null). */
	public function getText( string $name, string $default = '' ): string {
		return $default;
	}

	/** Get an integer query/POST value. */
	public function getInt( string $name, int $default = 0 ): int {
		return $default;
	}

	/** Return true if the request was an HTTP POST. */
	public function wasPosted(): bool {
		return false;
	}
}

/**
 * Base class for Special pages.
 */
class SpecialPage {
	public function __construct( string $name = '', string $restriction = '' ) {
	}

	/** Set page title and other standard headers. */
	public function setHeaders(): void {
	}

	/** Check that the current user has the required right; throw PermissionsError otherwise. */
	public function checkPermissions(): void {
	}

	/** Get the OutputPage for this request. */
	public function getOutput(): OutputPage {
		return new OutputPage();
	}

	/** Get the WebRequest for this request. */
	public function getRequest(): WebRequest {
		return new WebRequest();
	}

	/** Get the User object for the current visitor. */
	public function getUser(): User {
		return new User();
	}

	/**
	 * Look up an i18n message in context.
	 *
	 * @param string $key
	 * @param mixed  ...$params
	 */
	public function msg( string $key, ...$params ): Message {
		return new Message();
	}

	/** Get the Title of this special page, optionally with a subpage. */
	public function getPageTitle( ?string $subpage = null ): Title {
		return new Title();
	}

	/** Add a help link to the page. */
	public function addHelpLink( string $to, bool $overrideBaseUrl = false ): void {
	}
}

/**
 * Represents a wiki page title.
 */
class Title {
	/**
	 * Build a local URL for this title.
	 *
	 * @param array|string $query Query parameters (array or query string).
	 */
	public function getLocalURL( $query = '' ): string {
		return '';
	}

	/**
	 * Create a Title from a text string.
	 *
	 * @param string $text
	 * @param int    $defaultNamespace
	 * @return static|null
	 */
	public static function newFromText( string $text, int $defaultNamespace = 0 ): ?self {
		return null;
	}
}

/**
 * Registry of loaded MediaWiki extensions.
 */
class ExtensionRegistry {
	/** Get the global ExtensionRegistry singleton. */
	public static function getInstance(): self {
		return new self();
	}

	/** Check whether the named extension is loaded. */
	public function isLoaded( string $name, string $constraint = '*' ): bool {
		return false;
	}
}

// ── Namespaced classes ─────────────────────────────────────────────────────

namespace MediaWiki {
	/**
	 * MediaWiki service locator.
	 *
	 * Provides access to singletons for config, permissions, etc.
	 */
	class MediaWikiServices {
		/** Get the global MediaWikiServices instance. */
		public static function getInstance(): self {
			return new self();
		}

		/** Get the main site configuration object. */
		public function getMainConfig(): \MediaWiki\Config\Config {
			return new \MediaWiki\Config\Config();
		}

		/** Get the permission manager service. */
		public function getPermissionManager(): \MediaWiki\Permissions\PermissionManager {
			return new \MediaWiki\Permissions\PermissionManager();
		}
	}
}

namespace MediaWiki\Config {
	/**
	 * Site configuration accessor (stub — concrete class used for simplicity).
	 */
	class Config {
		/**
		 * Retrieve a configuration value.
		 *
		 * @param string $name The config key (without the leading $wg prefix).
		 * @return mixed
		 */
		public function get( string $name ) {
			return null;
		}
	}
}

namespace MediaWiki\Html {
	/**
	 * HTML generation utility — static helper methods for producing valid, escaped HTML.
	 */
	class Html {
		/**
		 * Generate a complete HTML element with escaped text content.
		 *
		 * @param string $element  Tag name.
		 * @param array  $attribs  HTML attributes.
		 * @param string $contents Text content (will be HTML-escaped).
		 */
		public static function element( string $element, array $attribs = [], string $contents = '' ): string {
			return '';
		}

		/** Generate an opening tag. */
		public static function openElement( string $element, array $attribs = [] ): string {
			return '';
		}

		/** Generate a closing tag. */
		public static function closeElement( string $element ): string {
			return '';
		}

		/**
		 * Generate a complete HTML element with raw (pre-escaped) HTML content.
		 *
		 * @param string $element  Tag name.
		 * @param array  $attribs  HTML attributes.
		 * @param string $contents Raw HTML content.
		 */
		public static function rawElement( string $element, array $attribs = [], string $contents = '' ): string {
			return '';
		}

		/** Generate a styled error notice box. */
		public static function errorBox( string $html, string $heading = '', string $className = '' ): string {
			return '';
		}

		/** Generate a styled success notice box. */
		public static function successBox( string $html, string $className = '' ): string {
			return '';
		}

		/**
		 * Generate an HTML <textarea> element.
		 *
		 * @param string $name    The textarea name attribute.
		 * @param string $content Initial content.
		 * @param array  $attribs Additional HTML attributes.
		 */
		public static function textarea( string $name, string $content = '', array $attribs = [] ): string {
			return '';
		}

		/**
		 * Generate an <input type="hidden"> element.
		 *
		 * @param string $name    The input name attribute.
		 * @param string $value   The hidden value.
		 * @param array  $attribs Additional HTML attributes.
		 */
		public static function hidden( string $name, string $value, array $attribs = [] ): string {
			return '';
		}

		/**
		 * Generate an <input type="submit"> button.
		 *
		 * @param string $contents Button label.
		 * @param array  $attribs  Additional HTML attributes.
		 */
		public static function submitButton( string $contents, array $attribs = [] ): string {
			return '';
		}

		/**
		 * JSON-encode a value as a JavaScript literal (safe for inline <script>).
		 *
		 * @param mixed $value
		 * @param bool  $pretty Pretty-print with indentation.
		 */
		public static function encodeJsVar( $value, bool $pretty = false ): string {
			return '';
		}
	}
}

namespace MediaWiki\Permissions {
	/**
	 * Service for checking user permissions.
	 */
	class PermissionManager {
		/**
		 * Check whether a user has a specific right.
		 *
		 * @param \MediaWiki\User\UserIdentity $user   The user to check.
		 * @param string                       $action The right name.
		 */
		public function userHasRight( \MediaWiki\User\UserIdentity $user, string $action ): bool {
			return false;
		}
	}
}

namespace MediaWiki\User {
	/**
	 * Minimal identity information for a wiki user.
	 * Stub class (concrete) — in real MW this is an interface.
	 */
	class UserIdentity {
		/**
		 * Get the user's page ID.
		 *
		 * @param string $wikiId Wiki ID (empty for the local wiki).
		 */
		public function getId( string $wikiId = '' ): int {
			return 0;
		}

		/** Get the user's canonical name (or IP for anonymous users). */
		public function getName(): string {
			return '';
		}

		/** Return true if this user has a registered account. */
		public function isRegistered(): bool {
			return false;
		}
	}
}
