<?php
/**
 * Consent-gated Sentry error reporting.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use Throwable;
use WCPOS\Vendor\Sentry\ClientBuilder;
use WCPOS\Vendor\Sentry\ClientInterface;
use WCPOS\Vendor\Sentry\Event;
use WCPOS\Vendor\Sentry\Severity;
use WCPOS\Vendor\Sentry\UserDataBag;
use WP_REST_Request;
use WP_REST_Response;
use const WCPOS\WooCommercePOS\PLUGIN_PATH;
use const WCPOS\WooCommercePOS\VERSION as PLUGIN_VERSION;

/**
 * Reports WCPOS errors without ever disrupting the request being reported.
 */
class Error_Reporter {
	/**
	 * Sentry ingest DSN.
	 *
	 * Client-side DSNs are designed to be public: they authorize event
	 * ingestion into one project only. This is the same Sentry project as the
	 * app-side reporters (release namespaces WCPOS@ and wcpos-app@); this
	 * reporter tags release wcpos-php@<VERSION>.
	 *
	 * Override with the WCPOS_SENTRY_DSN constant.
	 *
	 * @var string
	 */
	const DEFAULT_DSN = 'https://39233e9d1e5046cbb67dae52f807de5f@o159038.ingest.sentry.io/1220733';

	/**
	 * Transient latched after a failed send.
	 *
	 * @var string
	 */
	const BACKOFF_TRANSIENT = 'wcpos_sentry_backoff';

	/**
	 * How long a failed send suppresses delivery, in seconds.
	 *
	 * Long enough that a Sentry outage costs a store one slow request per
	 * window rather than one per error; short enough that a transient blip
	 * does not hide a real incident for the rest of the day.
	 *
	 * @var int
	 */
	const BACKOFF_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Singleton instance.
	 *
	 * @var null|self
	 */
	private static $instance = null;

	/**
	 * Factory for an injected transport. Test seam only.
	 *
	 * @var null|callable
	 */
	private static $transport_factory = null;

	/**
	 * Forced development-environment answer. Test seam only.
	 *
	 * @var null|bool
	 */
	private static ?bool $dev_override = null;

	/**
	 * Cached client, built only after the consent gate passes.
	 *
	 * @var null|ClientInterface
	 */
	private ?ClientInterface $client = null; // @phpstan-ignore-line -- Scoped SDK is excluded from analysis.

	/**
	 * Number of non-fatal events captured during this request.
	 *
	 * @var int
	 */
	private int $events_sent = 0;

	/**
	 * Whether a fatal has already been captured during this request.
	 *
	 * Fatals get their own slot. They arrive last (shutdown), so sharing one
	 * counter with the other two paths meant any earlier logged error or REST
	 * 500 permanently consumed the budget and silently dropped the fatal —
	 * the highest-value of the three signals.
	 *
	 * @var bool
	 */
	private bool $fatal_sent = false;

	/**
	 * Cached consent + environment answer for this request.
	 *
	 * Memoised because Logger forwards every error-level write, and Logger's
	 * class contract forbids repeated option lookups from that path.
	 *
	 * @var null|bool
	 */
	private ?bool $enabled_cache = null;

	/**
	 * Whether this reporter is already handling an event.
	 *
	 * @var bool
	 */
	private bool $reporting = false;

	/** Get the singleton instance. */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/** Reset the singleton. Intended for tests only. */
	public static function reset_instance(): void {
		self::$instance = null;
	}

	/**
	 * Install a transport factory. Test seam only.
	 *
	 * Resetting the singleton deliberately does not clear this factory.
	 * No-op outside the PHPUnit environment so shipped code cannot use it
	 * to intercept event payloads.
	 *
	 * @param null|callable $factory Factory returning a Sentry transport.
	 */
	public static function set_transport_factory_for_testing( ?callable $factory ): void {
		if ( ! \defined( 'WP_TESTS_DOMAIN' ) ) {
			return;
		}
		self::$transport_factory = $factory;
	}

	/**
	 * Override development-environment detection. Test seam only.
	 *
	 * No-op outside the PHPUnit environment: a production site must not be
	 * able to lift the WP_DEBUG / WCPOS_DEV gate through this seam.
	 *
	 * @param null|bool $is_dev Forced answer, or null to use constants.
	 */
	public static function set_dev_override_for_testing( ?bool $is_dev ): void {
		if ( ! \defined( 'WP_TESTS_DOMAIN' ) ) {
			return;
		}
		self::$dev_override = $is_dev;
	}

	/** Whether the Sentry client has been built. */
	public function is_initialized(): bool {
		return null !== $this->client;
	}

	/** Register error-reporting hooks. */
	public function register_hooks(): void {
		try {
			add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'filter_rest_request_after_callbacks' ), 999, 3 );
			register_shutdown_function( array( __CLASS__, 'handle_shutdown' ) );
		} catch ( Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Reporting must never break plugin initialization.
		}
	}

	/** Whether reporting is allowed by consent and environment. */
	public function is_enabled(): bool {
		if ( null !== $this->enabled_cache ) {
			return $this->enabled_cache;
		}

		$this->enabled_cache = $this->compute_enabled();

		return $this->enabled_cache;
	}

	/** Resolve consent, environment and the host kill switch. */
	private function compute_enabled(): bool {
		try {
			// The PERSISTED value, not the filtered read view: a settings filter
			// must not be able to manufacture consent the merchant never gave.
			$consent_allowed = 'allowed' === Settings::instance()->raw_tracking_consent();
			$enabled         = $consent_allowed && ! $this->is_dev_environment();

			/**
			 * Filters whether WCPOS error reporting is active. The host's kill
			 * switch. Default: tracking consent, minus development sites.
			 *
			 * @since 1.10.6
			 *
			 * @param bool $enabled         Computed default.
			 * @param bool $consent_allowed Raw consent check.
			 */
			return $consent_allowed && (bool) apply_filters( 'woocommerce_pos_error_reporting_enabled', $enabled, $consent_allowed );
		} catch ( Throwable $throwable ) {
			return false;
		}
	}

	/**
	 * Remove request and user data that could identify the merchant.
	 *
	 * @param Event $event Event about to be sent.
	 *
	 * @return Event Scrubbed event.
	 */
	public static function scrub_event( $event ) { // phpcs:ignore Squiz.Functions.MultiLineFunctionDeclaration.ContentAfterBrace -- Scoped SDK is excluded from analysis. @phpstan-ignore-line
		try {
			$request = $event->getRequest();
			if ( ! empty( $request ) ) {
				$url  = isset( $request['url'] ) && \is_string( $request['url'] ) ? $request['url'] : '';
				$path = wp_parse_url( $url, PHP_URL_PATH );
				$event->setRequest(
					array(
						'method' => isset( $request['method'] ) ? (string) $request['method'] : '',
						'url'    => \is_string( $path ) ? $path : '',
					)
				);
			}

			// Message text is the one place merchant identity still leaks: a PHP
			// fatal embeds absolute paths and a textual stack trace, and shared
			// hosting bakes the store's domain into the docroot
			// (/home/<domain>/public_html, /var/www/vhosts/<domain>/httpdocs).
			// The `prefixes` option only rewrites stacktrace FRAMES, which are
			// switched off here, so it never sees these strings.
			$message = $event->getMessage();
			if ( \is_string( $message ) && '' !== $message ) {
				$event->setMessage( self::redact_paths( $message ) );
			}

			$event->setServerName( '' );
			$event->setUser( UserDataBag::createFromUserIdentifier( Analytics::instance()->get_site_id() ) );
		} catch ( Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Return the best-effort scrubbed event; never disrupt Sentry internals.
		}

		return $event;
	}

	/**
	 * Replace local filesystem paths in free text with stable placeholders.
	 *
	 * Longest path first: WP_CONTENT_DIR usually sits inside ABSPATH, and the
	 * parent of ABSPATH is the segment that carries the account or domain name
	 * on cPanel-style hosting. A root-level path ('/' or '') is skipped — it
	 * would match every slash in the string.
	 *
	 * @param string $text Text to redact.
	 *
	 * @return string Redacted text.
	 */
	private static function redact_paths( string $text ): string {
		$normalized = str_replace( '\\', '/', $text );

		$replacements = array();
		if ( \defined( 'WP_CONTENT_DIR' ) ) {
			$replacements[ untrailingslashit( str_replace( '\\', '/', WP_CONTENT_DIR ) ) ] = '<wp-content>';
		}
		if ( \defined( 'ABSPATH' ) ) {
			$abspath                              = untrailingslashit( str_replace( '\\', '/', ABSPATH ) );
			$replacements[ $abspath ]             = '<abspath>';
			$replacements[ \dirname( $abspath ) ] = '<root>';
		}

		// Longest needle first so a nested path is not partially replaced by its
		// own parent, which would leave the identifying segment behind.
		uksort(
			$replacements,
			static function ( string $a, string $b ): int {
				return \strlen( $b ) <=> \strlen( $a );
			}
		);

		foreach ( $replacements as $path => $placeholder ) {
			if ( \strlen( $path ) > 1 && '/' !== $path ) {
				$normalized = str_replace( $path, $placeholder, $normalized );
			}
		}

		return $normalized;
	}

	/**
	 * Forward an error-level Logger write.
	 *
	 * @param string $level   Logger level.
	 * @param string $message Log message.
	 * @param string $context Formatted log context.
	 */
	public static function report_log_error( string $level, string $message, string $context ): void {
		try {
			if ( 'error' !== $level && 'critical' !== $level ) {
				return;
			}

			// Group on the bare message: the context routinely carries order ids,
			// timestamps and paths, so folding it into the grouping key would make
			// every occurrence its own Sentry issue.
			$fingerprint = array( 'wcpos-log', $level, self::redact_paths( $message ) );

			if ( '' !== $context ) {
				$message .= ' | Context: ' . $context;
			}

			self::instance()->capture( $level, $message, array( 'source' => 'logger' ), $fingerprint );
		} catch ( Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Reporting must never break logging.
		}
	}

	/**
	 * Report server errors returned by WCPOS REST routes.
	 *
	 * @param mixed $response REST response.
	 * @param mixed $handler  Matched REST handler.
	 * @param mixed $request  REST request.
	 *
	 * @return mixed The original response, always unchanged.
	 */
	public static function filter_rest_request_after_callbacks( $response, $handler, $request ) {
		try {
			if ( $request instanceof WP_REST_Request ) {
				$route       = $request->get_route();
				$lower_route = strtolower( $route );
				$is_wcpos    = 0 === strpos( $lower_route, '/wcpos/v1/' ) || 0 === strpos( $lower_route, '/wcpos/v2/' );
				if ( $is_wcpos ) {
					$status  = 0;
					$code    = '';
					$message = '';
					if ( is_wp_error( $response ) ) {
						$statuses = array();
						foreach ( $response->get_error_codes() as $error_code ) {
							$data = $response->get_error_data( $error_code );
							if ( \is_array( $data ) && isset( $data['status'] ) && \is_numeric( $data['status'] ) ) {
								$statuses[] = (int) $data['status'];
							}
						}
						$status  = empty( $statuses ) ? 500 : max( $statuses );
						$code    = (string) $response->get_error_code();
						$message = $code . ': ' . $response->get_error_message();
					} elseif ( $response instanceof WP_REST_Response ) {
						$status = $response->get_status();
						$data   = $response->get_data();
						$code   = \is_array( $data ) && isset( $data['code'] ) && \is_string( $data['code'] )
							? $data['code']
							: 'http_' . $status;
						$message = $code;
					}

					if ( 500 <= $status ) {
						// $code can originate in a third-party response body (a
						// relay or upstream error forwarded by a controller), so it
						// is allow-listed before it becomes a grouping key or tag.
						$code = self::safe_code( $code );

						self::instance()->capture(
							'error',
							$message,
							array(
								'route'  => self::generalize_route( (string) $route, $request->get_url_params() ),
								'method' => (string) $request->get_method(),
								'status' => (string) $status,
								'source' => 'rest',
							),
							array( 'wcpos-rest', $code )
						);
					}
				}
			}
		} catch ( Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// REST responses must pass through even if reporting fails.
		}

		return $response;
	}

	/**
	 * Reduce an error code to a safe, low-cardinality identifier.
	 *
	 * @param string $code Raw error code.
	 *
	 * @return string Allow-listed code.
	 */
	private static function safe_code( string $code ): string {
		return preg_match( '/^[A-Za-z0-9_.-]{1,64}$/', $code ) ? $code : 'unrecognized_code';
	}

	/**
	 * Collapse resource ids in a route so the tag stays low-cardinality.
	 *
	 * Matched route parameters are removed first because printer-token routes
	 * contain non-numeric credentials. Remaining numeric ids are then collapsed.
	 * Without this, each resource id opens its own tag value and exposes store data.
	 *
	 * @param string $route      Concrete requested route.
	 * @param array  $parameters Matched URL parameters.
	 *
	 * @return string Generalized route.
	 */
	private static function generalize_route( string $route, array $parameters ): string {
		foreach ( $parameters as $value ) {
			if ( ! \is_scalar( $value ) || '' === (string) $value ) {
				continue;
			}

			$redacted = preg_replace( '#/' . preg_quote( (string) $value, '#' ) . '(?=/|$)#', '/{param}', $route );
			$route    = \is_string( $redacted ) ? $redacted : $route;
		}

		$generalized = preg_replace( '#/\d+#', '/{id}', $route );

		return \is_string( $generalized ) ? $generalized : $route;
	}

	/** Handle the last PHP error at shutdown. */
	public static function handle_shutdown(): void {
		try {
			$error = error_get_last();
			if ( null !== $error ) {
				self::instance()->report_fatal( $error );
			}
		} catch ( Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Shutdown reporting is best-effort only.
		}
	}

	/**
	 * Report a crafted fatal error array. Public so tests can drive this path.
	 *
	 * @param array $error PHP error details.
	 */
	public function report_fatal( array $error ): void {
		try {
			if ( ! isset( $error['type'], $error['message'], $error['file'] ) ) {
				return;
			}

			$fatal_types = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
			if ( 0 === ( (int) $error['type'] & $fatal_types ) ) {
				return;
			}

			$file        = str_replace( '\\', '/', (string) $error['file'] );
			$plugin_path = str_replace( '\\', '/', PLUGIN_PATH );
			$is_ours     = 0 === strpos( $file, $plugin_path );
			if ( ! $is_ours && \defined( '\WCPOS\WooCommercePOSPro\PLUGIN_PATH' ) ) {
				$pro_path = str_replace( '\\', '/', (string) \constant( '\WCPOS\WooCommercePOSPro\PLUGIN_PATH' ) );
				$is_ours  = 0 === strpos( $file, $pro_path );
			}
			if ( ! $is_ours ) {
				return;
			}

			$content_path = rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' ) . '/';
			$relative     = 0 === strpos( $file, $content_path ) ? substr( $file, strlen( $content_path ) ) : basename( $file );
			$line         = isset( $error['line'] ) ? (string) $error['line'] : '0';

			$this->capture(
				'critical',
				substr( (string) $error['message'], 0, 8 * 1024 ),
				array( 'source' => 'fatal' ),
				array( 'wcpos-fatal', $relative, $line ),
				true
			);
		} catch ( Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Fatal reporting must not create another fatal.
		}
	}

	/**
	 * Build and send one event, subject to consent and the request cap.
	 *
	 * @param string $level           Error level.
	 * @param string $message         Event message.
	 * @param array  $tags            Event tags.
	 * @param array  $fingerprint     Event fingerprint.
	 * @param bool   $from_fatal_path Whether this came from the shutdown fatal
	 *                                handler, which owns the reserved slot.
	 *
	 * @return bool Whether an event was handed to the client.
	 */
	private function capture( string $level, string $message, array $tags, array $fingerprint, bool $from_fatal_path = false ): bool {
		if ( $this->reporting ) {
			return false;
		}

		// The reserved slot belongs to the shutdown fatal PATH, not to a
		// severity. `Logger::$log_level` is public, so anything may log at
		// `critical`; letting that consume the fatal budget would silently drop
		// the real fatal arriving later in the same request — the exact
		// starvation the second slot exists to prevent.
		$is_fatal = $from_fatal_path;

		$this->reporting = true;
		try {
			// One non-fatal event per request, plus at most one fatal.
			if ( $is_fatal ? $this->fatal_sent : 1 <= $this->events_sent ) {
				return false;
			}

			if ( ! $this->is_enabled() || $this->is_backing_off() ) {
				return false;
			}

			$client = $this->get_client();
			if ( null === $client ) {
				return false;
			}

			$event = Event::createEvent();
			$event->setLevel( 'critical' === $level ? Severity::fatal() : Severity::error() );
			$event->setMessage( $message );
			if ( ! empty( $fingerprint ) ) {
				$event->setFingerprint( $fingerprint );
			}
			$event->setTags( array_merge( $this->get_default_tags(), $tags ) );
			$event->setUser( UserDataBag::createFromUserIdentifier( Analytics::instance()->get_site_id() ) );

			$sent = $client->captureEvent( $event );

			if ( $is_fatal ) {
				$this->fatal_sent = true;
			} else {
				++$this->events_sent;
			}

			// captureEvent() returns null when the transport failed. The SDK's own
			// rate limiter is built per transport, i.e. per request under PHP-FPM,
			// so it forgets a 429 immediately and every erroring request would keep
			// paying the full timeout. Latch a short backoff across requests
			// instead: an outage costs one slow request per window, not all of them.
			if ( null === $sent ) {
				$this->start_backoff();

				return false;
			}

			return true;
		} catch ( Throwable $throwable ) {
			return false;
		} finally {
			$this->reporting = false;
		}
	}

	/** Lazily build the scoped Sentry client. */
	private function get_client(): ?ClientInterface { // phpcs:ignore Squiz.Functions.MultiLineFunctionDeclaration.ContentAfterBrace -- Scoped SDK is excluded from analysis. @phpstan-ignore-line
		if ( null !== $this->client ) {
			return $this->client;
		}

		try {
			if ( ! \class_exists( '\WCPOS\Vendor\Sentry\ClientBuilder' ) ) {
				return null;
			}
			foreach ( array( 'mb_detect_encoding', 'mb_convert_encoding', 'mb_strlen', 'mb_substr' ) as $function ) {
				if ( ! \function_exists( $function ) ) {
					return null;
				}
			}
			if ( null === self::$transport_factory && ! \extension_loaded( 'curl' ) ) {
				return null;
			}

			$dsn     = \defined( 'WCPOS_SENTRY_DSN' ) ? (string) \WCPOS_SENTRY_DSN : self::DEFAULT_DSN;
			$options = array(
				'dsn'                  => $dsn,
				'release'              => 'wcpos-php@' . PLUGIN_VERSION,
				'environment'          => 'production',
				'default_integrations' => false,
				'integrations'         => array(),
				'send_default_pii'     => false,
				'attach_stacktrace'    => false,
				'max_breadcrumbs'      => 0,
				// The SDK stamps gethostname() onto every event before before_send
				// runs. Blanking it here means the hostname is never placed on the
				// event at all, so a throw inside scrub_event cannot ship it.
				'server_name'          => '',
				'http_connect_timeout' => 1,
				'http_timeout'         => 2,
				'prefixes'             => array( ABSPATH ),
				'before_send'          => array( __CLASS__, 'scrub_event' ),
			);

			$builder = ClientBuilder::create( $options );
			if ( null !== self::$transport_factory ) {
				$builder->setTransport( \call_user_func( self::$transport_factory ) );
			}

			$this->client = $builder->getClient();

			return $this->client;
		} catch ( Throwable $throwable ) {
			return null;
		}
	}

	/** Whether a recent send failure is still suppressing delivery. */
	private function is_backing_off(): bool {
		return false !== get_transient( self::BACKOFF_TRANSIENT );
	}

	/** Suppress delivery for a short window after a failed send. */
	private function start_backoff(): void {
		set_transient( self::BACKOFF_TRANSIENT, 1, self::BACKOFF_TTL );
	}

	/** Get tags attached to every event. */
	private function get_default_tags(): array {
		return array(
			'wp_version'  => (string) get_bloginfo( 'version' ),
			'wc_version'  => \defined( 'WC_VERSION' ) ? (string) WC_VERSION : 'unknown',
			'php_version' => (string) PHP_VERSION,
			'pro_version' => \defined( '\WCPOS\WooCommercePOSPro\VERSION' ) ? (string) \constant( '\WCPOS\WooCommercePOSPro\VERSION' ) : 'none',
			'multisite'   => is_multisite() ? 'yes' : 'no',
		);
	}

	/** Whether this is a development site where reporting stays disabled. */
	private function is_dev_environment(): bool {
		if ( null !== self::$dev_override ) {
			return self::$dev_override;
		}

		return ( \defined( 'WP_DEBUG' ) && WP_DEBUG ) || \defined( 'WCPOS_DEV' );
	}
}
