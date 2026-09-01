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
	 * Number of events captured during this request.
	 *
	 * @var int
	 */
	private int $events_sent = 0;

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
	 *
	 * @param null|callable $factory Factory returning a Sentry transport.
	 */
	public static function set_transport_factory_for_testing( ?callable $factory ): void {
		self::$transport_factory = $factory;
	}

	/**
	 * Override development-environment detection. Test seam only.
	 *
	 * @param null|bool $is_dev Forced answer, or null to use constants.
	 */
	public static function set_dev_override_for_testing( ?bool $is_dev ): void {
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
		try {
			$consent_allowed = 'allowed' === Settings::instance()->tracking_consent();
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

			$event->setServerName( '' );
			$event->setUser( UserDataBag::createFromUserIdentifier( Analytics::instance()->get_site_id() ) );
		} catch ( Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Return the best-effort scrubbed event; never disrupt Sentry internals.
		}

		return $event;
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

			if ( '' !== $context ) {
				$message .= ' | Context: ' . $context;
			}

			self::instance()->capture( $level, $message, array( 'source' => 'logger' ), array() );
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
						self::instance()->capture(
							'error',
							$message,
							array(
								'route'  => (string) $route,
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

			$fatal_types = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;
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
				array( 'wcpos-fatal', $relative, $line )
			);
		} catch ( Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Fatal reporting must not create another fatal.
		}
	}

	/**
	 * Build and send one event, subject to consent and the request cap.
	 *
	 * @param string $level       Error level.
	 * @param string $message     Event message.
	 * @param array  $tags        Event tags.
	 * @param array  $fingerprint Event fingerprint.
	 *
	 * @return bool Whether an event was handed to the client.
	 */
	private function capture( string $level, string $message, array $tags, array $fingerprint ): bool {
		if ( $this->reporting ) {
			return false;
		}

		$this->reporting = true;
		try {
			if ( 1 <= $this->events_sent || ! $this->is_enabled() ) {
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

			$client->captureEvent( $event );
			++$this->events_sent;

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
