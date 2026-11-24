<?php
/**
 * Fields a user's data permissions withhold from what WCPOS serves and accepts.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use stdClass;
use WP_REST_Request;

/**
 * Withholds the keys a user lacks the permission for: absent, never null.
 *
 * The response is stripped once, at the outermost serving seam, and the request once,
 * before the callbacks; read sites never strip. Revisions hash what the user is sent.
 */
final class Withheld_Fields {
	/**
	 * Permission => keys it guards. A key is removed wherever it appears as an object
	 * key, and a meta_data entry is removed when its `key` is one of them.
	 */
	private const MAP = array(
		'view_woocommerce_pos_costs' => array( 'cost_of_goods_sold', '_cogs_value', '_cogs_total_value' ),
	);

	/**
	 * Keys withheld from a user. User 0 lacks every permission (fail closed).
	 *
	 * @param int $user_id User id.
	 * @return string[]
	 */
	public static function withheld_for( int $user_id ): array {
		$withheld = array();
		foreach ( self::MAP as $permission => $keys ) {
			if ( $user_id <= 0 || ! user_can( $user_id, $permission ) ) {
				$withheld = array_merge( $withheld, $keys );
			}
		}
		return $withheld;
	}

	/**
	 * Remove withheld keys, recursively. Returns $data itself when nothing was removed.
	 *
	 * @param mixed    $data     Response data.
	 * @param string[] $withheld Keys to remove.
	 * @return mixed
	 */
	public static function strip( $data, array $withheld ) {
		if ( array() === $withheld || ( ! \is_array( $data ) && ! \is_object( $data ) ) ) {
			return $data;
		}
		// Objects (WC_Meta_Data, JsonSerializable) are seen as they will be sent; an empty
		// object stays an object.
		$normalised = json_decode( (string) wp_json_encode( $data ) );
		$removed    = false;
		$stripped   = self::remove( $normalised, array_flip( $withheld ), $removed );
		return $removed ? $stripped : $data;
	}

	/**
	 * Whether the request is served under the permission contract: a WCPOS route, or
	 * any route when the current user was authenticated by a WCPOS token.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function applies_to( WP_REST_Request $request ): bool {
		if ( 1 === preg_match( '#^/wcpos/v[12](/|$)#i', (string) $request->get_route() ) ) {
			return true;
		}
		$token_user = Auth::instance()->token_user_id();
		return $token_user > 0 && get_current_user_id() === $token_user;
	}

	/**
	 * The form a revision hashes: what the current user is sent.
	 *
	 * @param array $data Record about to be hashed.
	 */
	public static function project_for_revision( array $data ): array {
		$stripped = self::strip( $data, self::withheld_for( get_current_user_id() ) );
		if ( $stripped === $data ) {
			return $data;
		}
		return (array) json_decode( (string) wp_json_encode( $stripped ), true );
	}

	/**
	 * Strip the response just before it is echoed.
	 *
	 * @param mixed           $result  Response data.
	 * @param mixed           $server  REST server.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function filter_response( $result, $server, $request ) {
		if ( ! $request instanceof WP_REST_Request || ! self::applies_to( $request ) ) {
			return $result;
		}
		return self::strip( $result, self::withheld_for( get_current_user_id() ) );
	}

	/**
	 * Drop withheld keys from a write before any callback reads it.
	 *
	 * @param mixed           $response Response so far.
	 * @param mixed           $handler  Route handler.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function filter_request( $response, $handler, $request ) {
		if ( ! $request instanceof WP_REST_Request || ! self::applies_to( $request ) ) {
			return $response;
		}
		$withheld = array_flip( self::withheld_for( get_current_user_id() ) );
		if ( array() === $withheld ) {
			return $response;
		}
		$json = $request->get_json_params();
		if ( \is_array( $json ) ) {
			$removed  = false;
			$stripped = self::remove( $json, $withheld, $removed );
			foreach ( $removed ? $json : array() as $key => $value ) {
				if ( ! array_key_exists( $key, $stripped ) ) {
					unset( $request[ $key ] );
				} elseif ( $stripped[ $key ] !== $value ) {
					$request->set_param( $key, $stripped[ $key ] );
				}
			}
		}
		// Last, so each source keeps its own stripped values.
		$sources = array(
			'get_body_params'  => 'set_body_params',
			'get_query_params' => 'set_query_params',
		);
		foreach ( $sources as $get => $set ) {
			$removed  = false;
			$stripped = self::remove( $request->$get(), $withheld, $removed );
			if ( $removed ) {
				$request->$set( $stripped );
			}
		}
		return $response;
	}

	/**
	 * Remove withheld keys from arrays and plain objects.
	 *
	 * @param mixed $data     Node.
	 * @param array $withheld Withheld keys, flipped.
	 * @param bool  $removed  Set true when anything was removed.
	 * @return mixed
	 */
	private static function remove( $data, array $withheld, bool &$removed ) {
		if ( ! \is_array( $data ) && ! $data instanceof stdClass ) {
			return $data;
		}
		$is_object = $data instanceof stdClass;
		$out       = $is_object ? new stdClass() : array();
		foreach ( (array) $data as $key => $value ) {
			if ( \is_string( $key ) && isset( $withheld[ $key ] ) ) {
				$removed = true;
				continue;
			}
			if ( 'meta_data' === $key && \is_array( $value ) ) {
				$value = self::remove_meta( $value, $withheld, $removed );
			}
			$value = self::remove( $value, $withheld, $removed );
			if ( $is_object ) {
				$out->$key = $value;
			} else {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Remove meta_data entries whose key is withheld.
	 *
	 * @param array $meta     Meta entries.
	 * @param array $withheld Withheld keys, flipped.
	 * @param bool  $removed  Set true when anything was removed.
	 */
	private static function remove_meta( array $meta, array $withheld, bool &$removed ): array {
		$kept = array();
		foreach ( $meta as $index => $entry ) {
			$key = \is_array( $entry ) ? ( $entry['key'] ?? null ) : ( \is_object( $entry ) ? ( $entry->key ?? null ) : null );
			if ( \is_string( $key ) && isset( $withheld[ $key ] ) ) {
				$removed = true;
				continue;
			}
			$kept[ $index ] = $entry;
		}
		return array_keys( $meta ) === range( 0, \count( $meta ) - 1 ) ? array_values( $kept ) : $kept;
	}
}
