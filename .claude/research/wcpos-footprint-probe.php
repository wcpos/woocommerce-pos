<?php
/**
 * TEMPORARY (2026-09-03): WCPOS footprint probe. dev-next only, header-gated.
 *
 * Answers "how much WCPOS code runs on an ONLINE-STORE request?" Wraps every
 * hook callback whose code lives inside a WCPOS plugin directory and records
 * per-callback invocations, exclusive/inclusive wall time and the SQL it ran.
 * One JSON line per request is appended to /tmp/wcpos-footprint.jsonl inside
 * the PHP container. Remove after the investigation.
 *
 * Headers:
 *   X-WCPOS-Footprint: <token>        arm the probe for this request
 *   X-WCPOS-Footprint-Mode: plain     plugin active, nothing wrapped (clean A/B)
 *   X-WCPOS-Footprint-Mode: off       ALSO unload both WCPOS plugins for this
 *                                     request (A/B baseline; nothing is wrapped)
 */
defined( 'ABSPATH' ) || exit;

if ( 'dev-next.wcpos.com' !== ( $_SERVER['HTTP_HOST'] ?? '' ) ) {
	return;
}
if ( ! hash_equals( 'REPLACE-WITH-A-FRESH-TOKEN', (string) ( $_SERVER['HTTP_X_WCPOS_FOOTPRINT'] ?? '' ) ) ) {
	return;
}

if ( ! defined( 'SAVEQUERIES' ) ) {
	define( 'SAVEQUERIES', true );
}

final class WCPOS_Footprint_Probe {
	const LOG_FILE = '/tmp/wcpos-footprint.jsonl';
	const POS_PLUGINS = array( 'woocommerce-pos-pro/woocommerce-pos-pro.php', 'woocommerce-pos/woocommerce-pos.php' );

	public static $mode = 'on';
	private static $dirs = array();
	private static $wrapped = array();  // hook => priority => idx => true
	private static $stats = array();    // key => stats
	private static $stack = array();    // running callbacks: [key, child_ns, child_q]
	private static $plugin_loaded_ts = array();
	private static $last_plugin_ts = 0.0;

	public static function boot() {
		$m = strtolower( (string) ( $_SERVER['HTTP_X_WCPOS_FOOTPRINT_MODE'] ?? '' ) );
		// on = wrap + attribute; plain = plugin active, nothing wrapped (clean A/B vs off); off = plugin unloaded.
		self::$mode = in_array( $m, array( 'off', 'plain' ), true ) ? $m : 'on';
		self::$dirs = array(
			rtrim( WP_PLUGIN_DIR, '/' ) . '/woocommerce-pos-pro/',
			rtrim( WP_PLUGIN_DIR, '/' ) . '/woocommerce-pos/',
		);

		if ( 'off' === self::$mode ) {
			add_filter(
				'option_active_plugins',
				static function ( $plugins ) {
					return is_array( $plugins ) ? array_values( array_diff( $plugins, self::POS_PLUGINS ) ) : $plugins;
				},
				PHP_INT_MAX
			);
		}

		self::$last_plugin_ts = hrtime( true );
		add_action(
			'plugin_loaded',
			static function ( $plugin ) {
				$now = hrtime( true );
				self::$plugin_loaded_ts[ basename( dirname( $plugin ) ) ] = round( ( $now - self::$last_plugin_ts ) / 1e6, 2 );
				self::$last_plugin_ts = $now;
			},
			PHP_INT_MAX
		);

		// Wrap passes. The negative-priority plugins_loaded pass runs BEFORE
		// Activator::init at 10, so the Init constructor is measured too.
		$passes = array(
			'plugins_loaded' => -PHP_INT_MAX,
			'init'           => -PHP_INT_MAX,
			'wp_loaded'      => PHP_INT_MAX,
			'parse_request'  => -PHP_INT_MAX,
			'wp'             => PHP_INT_MAX,
			'template_redirect' => -PHP_INT_MAX,
			'woocommerce_init' => -PHP_INT_MAX,
			'rest_api_init'  => PHP_INT_MAX,
			'rest_pre_dispatch' => -PHP_INT_MAX,
			'woocommerce_before_checkout_process' => -PHP_INT_MAX,
			'woocommerce_store_api_checkout_update_order_from_request' => -PHP_INT_MAX,
			'woocommerce_checkout_create_order' => -PHP_INT_MAX,
			'woocommerce_before_order_object_save' => -PHP_INT_MAX,
			'woocommerce_new_order' => -PHP_INT_MAX,
			'woocommerce_order_status_changed' => -PHP_INT_MAX,
			'woocommerce_payment_complete' => -PHP_INT_MAX,
			'woocommerce_reduce_order_stock' => -PHP_INT_MAX,
			'wp_footer'      => -PHP_INT_MAX,
		);
		foreach ( $passes as $hook => $prio ) {
			add_action( $hook, array( self::class, 'wrap_all' ), $prio, 0 );
		}
		// Also on init at max so callbacks registered during init (init_common) are wrapped.
		add_action( 'init', array( self::class, 'wrap_all' ), PHP_INT_MAX, 0 );
		add_action( 'plugins_loaded', array( self::class, 'wrap_all' ), PHP_INT_MAX, 0 );
		add_action( 'shutdown', array( self::class, 'report' ), PHP_INT_MAX, 0 );
	}

	private static function callback_file( $fn ) {
		try {
			if ( $fn instanceof Closure ) {
				return ( new ReflectionFunction( $fn ) )->getFileName();
			}
			if ( is_array( $fn ) && 2 === count( $fn ) ) {
				return ( new ReflectionMethod( $fn[0], $fn[1] ) )->getFileName();
			}
			if ( is_string( $fn ) ) {
				if ( false !== strpos( $fn, '::' ) ) {
					list( $c, $m ) = explode( '::', $fn, 2 );
					return ( new ReflectionMethod( $c, $m ) )->getFileName();
				}
				return ( new ReflectionFunction( $fn ) )->getFileName();
			}
			if ( is_object( $fn ) && method_exists( $fn, '__invoke' ) ) {
				return ( new ReflectionMethod( $fn, '__invoke' ) )->getFileName();
			}
		} catch ( Throwable $e ) {
			return false;
		}
		return false;
	}

	private static function is_pos_file( $file ) {
		if ( ! $file ) {
			return false;
		}
		foreach ( self::$dirs as $d ) {
			if ( 0 === strpos( $file, $d ) ) {
				return true;
			}
		}
		return false;
	}

	private static function callback_name( $fn, $file ) {
		if ( $fn instanceof Closure ) {
			$r = new ReflectionFunction( $fn );
			$scope = $r->getClosureScopeClass();
			return ( $scope ? $scope->getName() : '' ) . '{closure}@' . basename( $file ) . ':' . $r->getStartLine();
		}
		if ( is_array( $fn ) ) {
			return ( is_object( $fn[0] ) ? get_class( $fn[0] ) : $fn[0] ) . '::' . $fn[1];
		}
		if ( is_string( $fn ) ) {
			return $fn;
		}
		return get_class( $fn ) . '::__invoke';
	}

	public static function wrap_all() {
		if ( 'on' !== self::$mode ) {
			return;
		}
		global $wp_filter;
		foreach ( $wp_filter as $hook => $wp_hook ) {
			if ( ! ( $wp_hook instanceof WP_Hook ) ) {
				continue;
			}
			foreach ( $wp_hook->callbacks as $priority => $cbs ) {
				foreach ( $cbs as $idx => $cb ) {
					if ( isset( self::$wrapped[ $hook ][ $priority ][ $idx ] ) ) {
						continue;
					}
					$fn   = $cb['function'];
					$file = self::callback_file( $fn );
					if ( ! self::is_pos_file( $file ) ) {
						continue;
					}
					$key = $hook . ' → ' . self::callback_name( $fn, $file );
					$rel = str_replace( rtrim( WP_PLUGIN_DIR, '/' ) . '/', '', $file );
					self::$stats[ $key ] = array(
						'hook'    => $hook,
						'cb'      => self::callback_name( $fn, $file ),
						'file'    => $rel,
						'prio'    => $priority,
						'calls'   => 0,
						'excl_ns' => 0,
						'incl_ns' => 0,
						'queries' => 0,
						'sql'     => array(),
					);
					$wp_filter[ $hook ]->callbacks[ $priority ][ $idx ]['function'] = static function ( ...$args ) use ( $fn, $key ) {
						return WCPOS_Footprint_Probe::invoke( $fn, $key, $args );
					};
					self::$wrapped[ $hook ][ $priority ][ $idx ] = true;
				}
			}
		}
	}

	public static function invoke( $fn, $key, $args ) {
		global $wpdb;
		$q0 = $wpdb->num_queries;
		$i0 = is_array( $wpdb->queries ) ? count( $wpdb->queries ) : 0;
		$t0 = hrtime( true );
		// child_ranges: [start, end) index ranges of $wpdb->queries consumed by
		// nested wrapped callbacks, so the SQL list stays exclusive like the counts.
		self::$stack[] = array( 'key' => $key, 'child_ns' => 0, 'child_q' => 0, 'child_ranges' => array() );
		try {
			return $fn( ...$args );
		} finally {
			$dt    = hrtime( true ) - $t0;
			$dq    = $wpdb->num_queries - $q0;
			$i1    = is_array( $wpdb->queries ) ? count( $wpdb->queries ) : 0;
			$frame = array_pop( self::$stack );
			$s     = &self::$stats[ $key ];
			$s['calls']++;
			$s['incl_ns'] += $dt;
			$s['excl_ns'] += $dt - $frame['child_ns'];
			$s['queries'] += $dq - $frame['child_q'];
			if ( $i1 > $i0 && is_array( $wpdb->queries ) && count( $s['sql'] ) < 40 ) {
				for ( $i = $i0; $i < $i1; $i++ ) {
					foreach ( $frame['child_ranges'] as $range ) {
						if ( $i >= $range[0] && $i < $range[1] ) {
							continue 2;
						}
					}
					$q          = $wpdb->queries[ $i ];
					$s['sql'][] = array( 'q' => substr( preg_replace( '/\s+/', ' ', $q[0] ), 0, 300 ), 'ms' => round( $q[1] * 1000, 2 ) );
				}
			}
			unset( $s );
			if ( self::$stack ) {
				$p = count( self::$stack ) - 1;
				self::$stack[ $p ]['child_ns'] += $dt;
				self::$stack[ $p ]['child_q']  += $dq;
				self::$stack[ $p ]['child_ranges'][] = array( $i0, $i1 );
			}
		}
	}

	public static function report() {
		global $wpdb, $wp_filter;

		// Coverage gap: POS callbacks registered after the last wrap pass.
		$unwrapped = array();
		$registered = 0;
		if ( 'off' !== self::$mode ) {
			foreach ( $wp_filter as $hook => $wp_hook ) {
				if ( ! ( $wp_hook instanceof WP_Hook ) ) {
					continue;
				}
				foreach ( $wp_hook->callbacks as $priority => $cbs ) {
					foreach ( $cbs as $idx => $cb ) {
						if ( isset( self::$wrapped[ $hook ][ $priority ][ $idx ] ) ) {
							$registered++;
							continue;
						}
						$file = self::callback_file( $cb['function'] );
						if ( self::is_pos_file( $file ) ) {
							$registered++;
							$unwrapped[] = $hook . ' → ' . self::callback_name( $cb['function'], $file );
						}
					}
				}
			}
		}

		$pos_files = 0;
		$pos_bytes = 0;
		foreach ( get_included_files() as $f ) {
			if ( self::is_pos_file( $f ) ) {
				$pos_files++;
				$pos_bytes += (int) @filesize( $f );
			}
		}

		$rows = array_values( array_filter( self::$stats, static function ( $s ) { return $s['calls'] > 0; } ) );
		usort( $rows, static function ( $a, $b ) { return $b['excl_ns'] <=> $a['excl_ns']; } );
		$incl_top = 0;
		$calls = 0;
		$queries = 0;
		foreach ( $rows as &$r ) {
			$calls   += $r['calls'];
			$queries += $r['queries'];
			$incl_top += $r['excl_ns'];
			$r['excl_ms'] = round( $r['excl_ns'] / 1e6, 3 );
			$r['incl_ms'] = round( $r['incl_ns'] / 1e6, 3 );
			unset( $r['excl_ns'], $r['incl_ns'] );
		}
		unset( $r );

		$out = array(
			'ts'        => gmdate( 'c' ),
			'mode'      => self::$mode,
			'method'    => $_SERVER['REQUEST_METHOD'] ?? '',
			'uri'       => $_SERVER['REQUEST_URI'] ?? '',
			'status'    => http_response_code(),
			'total_ms'  => round( ( microtime( true ) - $_SERVER['REQUEST_TIME_FLOAT'] ) * 1000, 1 ),
			'total_queries' => (int) $wpdb->num_queries,
			'peak_mem_mb'   => round( memory_get_peak_usage( true ) / 1048576, 1 ),
			'included_files_total' => count( get_included_files() ),
			'pos_included_files'   => $pos_files,
			'pos_included_kb'      => round( $pos_bytes / 1024 ),
			'plugin_include_ms'    => self::$plugin_loaded_ts,
			'pos_callbacks_registered' => $registered,
			'pos_callbacks_fired'      => count( $rows ),
			'pos_calls'     => $calls,
			'pos_excl_ms'   => round( $incl_top / 1e6, 2 ),
			'pos_queries'   => $queries,
			'pos_unwrapped' => $unwrapped,
			'callbacks'     => $rows,
		);
		@file_put_contents( self::LOG_FILE, wp_json_encode( $out ) . "\n", FILE_APPEND | LOCK_EX );
	}
}

WCPOS_Footprint_Probe::boot();
