<?php
/**
 * Seed a large catalogue by direct SQL (wp eval-file).
 * Args: simple=<n> variable=<n> per=<variations per variable product>
 */
global $wpdb;
parse_str( implode( '&', $args ?? array() ), $opt );
$simple   = (int) ( $opt['simple'] ?? 20000 );
$variable = (int) ( $opt['variable'] ?? 1000 );
$per      = (int) ( $opt['per'] ?? 20 );

$tt = function ( $slug ) {
	$t = get_term_by( 'slug', $slug, 'product_type' );
	return (int) $t->term_taxonomy_id;
};
$tt_simple   = $tt( 'simple' );
$tt_variable = $tt( 'variable' );
$now         = current_time( 'mysql' );
$now_gmt     = current_time( 'mysql', true );

$insert_post = function ( $title, $type, $parent = 0 ) use ( $wpdb, $now, $now_gmt ) {
	static $n = 0;
	++$n;
	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$wpdb->posts} (post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_name,to_ping,pinged,post_modified,post_modified_gmt,post_content_filtered,post_parent,guid,menu_order,post_type,post_mime_type,comment_count) VALUES (1,%s,%s,'',%s,'','publish','closed','closed',%s,'','',%s,%s,'',%d,'',0,%s,'',0)",
			$now, $now_gmt, $title, sanitize_title( $title ) . '-' . $n, $now, $now_gmt, $parent, $type
		)
	);
	return (int) $wpdb->insert_id;
};

$meta_rows = array();
$rel_rows  = array();
$flush     = function () use ( $wpdb, &$meta_rows, &$rel_rows ) {
	if ( $meta_rows ) {
		$wpdb->query( "INSERT INTO {$wpdb->postmeta} (post_id,meta_key,meta_value) VALUES " . implode( ',', $meta_rows ) );
		$meta_rows = array();
	}
	if ( $rel_rows ) {
		$wpdb->query( "INSERT INTO {$wpdb->term_relationships} (object_id,term_taxonomy_id,term_order) VALUES " . implode( ',', $rel_rows ) );
		$rel_rows = array();
	}
};
$meta = function ( $id, $k, $v ) use ( $wpdb, &$meta_rows ) {
	$meta_rows[] = $wpdb->prepare( '(%d,%s,%s)', $id, $k, $v );
};

$wpdb->query( 'START TRANSACTION' );
for ( $i = 1; $i <= $simple; $i++ ) {
	$id = $insert_post( "Simple $i", 'product' );
	foreach ( array( '_price' => '9.99', '_regular_price' => '9.99', '_stock_status' => 'instock', '_sku' => "S$i", '_manage_stock' => 'no', '_tax_status' => 'taxable' ) as $k => $v ) {
		$meta( $id, $k, $v );
	}
	$rel_rows[] = "($id,$tt_simple,0)";
	if ( 0 === $i % 500 ) {
		$flush();
	}
}
$flush();
$attrs = maybe_serialize( array( 'size' => array( 'name' => 'Size', 'value' => implode( ' | ', range( 1, $per ) ), 'position' => 0, 'is_visible' => 1, 'is_variation' => 1, 'is_taxonomy' => 0 ) ) );
for ( $i = 1; $i <= $variable; $i++ ) {
	$pid = $insert_post( "Variable $i", 'product' );
	foreach ( array( '_price' => '5', '_stock_status' => 'instock', '_sku' => "V$i", '_product_attributes' => $attrs ) as $k => $v ) {
		$meta( $pid, $k, $v );
	}
	$rel_rows[] = "($pid,$tt_variable,0)";
	for ( $j = 1; $j <= $per; $j++ ) {
		$vid = $insert_post( "Variable $i - $j", 'product_variation', $pid );
		foreach ( array( '_price' => '5', '_regular_price' => '5', '_stock_status' => 'instock', '_sku' => "V$i-$j", 'attribute_size' => (string) $j ) as $k => $v ) {
			$meta( $vid, $k, $v );
		}
	}
	if ( 0 === $i % 50 ) {
		$flush();
	}
}
$flush();
$wpdb->query( 'COMMIT' );
$wpdb->query( "UPDATE {$wpdb->term_taxonomy} tt SET count = (SELECT COUNT(*) FROM {$wpdb->term_relationships} tr WHERE tr.term_taxonomy_id = tt.term_taxonomy_id) WHERE taxonomy='product_type'" );
wp_cache_flush();
echo 'products=' . $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product'" ) . ' variations=' . $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product_variation'" ) . "\n";
