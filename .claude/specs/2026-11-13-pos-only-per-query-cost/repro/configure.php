<?php
/**
 * Configure POS Only (wp eval-file). Args: enabled=0|1 prod=<n> var=<n>
 * The first <n> real product / variation ids go in the lists, padded with
 * ids above the max post id when <n> exceeds what exists.
 */
global $wpdb;
parse_str( implode( '&', $args ?? array() ), $opt );
$enabled = ! empty( $opt['enabled'] );
$np      = (int) ( $opt['prod'] ?? 0 );
$nv      = (int) ( $opt['var'] ?? 0 );

$general                      = get_option( 'woocommerce_pos_settings_general', array() );
$general['pos_only_products'] = $enabled;
update_option( 'woocommerce_pos_settings_general', $general );

$max  = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" );
$pick = function ( $type, $n, $pad_base ) use ( $wpdb ) {
	$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_title LIKE %s ORDER BY ID LIMIT %d", $type, 'product' === $type ? 'Simple %' : '%', $n ) ) );
	for ( $i = count( $ids ); $i < $n; $i++ ) {
		$ids[] = $pad_base + $i;
	}
	return $ids;
};
$vis = array(
	'products'   => array( 'default' => array( 'pos_only' => array( 'ids' => $pick( 'product', $np, $max + 1000000 ) ), 'online_only' => array( 'ids' => array() ) ) ),
	'variations' => array( 'default' => array( 'pos_only' => array( 'ids' => $pick( 'product_variation', $nv, $max + 3000000 ) ), 'online_only' => array( 'ids' => array() ) ) ),
);
update_option( 'woocommerce_pos_settings_visibility', $vis, false );
wp_cache_flush();
echo "enabled=" . (int) $enabled . " prod=" . count( $vis['products']['default']['pos_only']['ids'] ) . " var=" . count( $vis['variations']['default']['pos_only']['ids'] ) . " option_bytes=" . strlen( maybe_serialize( $vis ) ) . "\n";
