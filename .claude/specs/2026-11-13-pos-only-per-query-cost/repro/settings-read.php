<?php
/**
 * Cost of one POS Only settings read, and whether two reads share one array (wp eval-file).
 */
$s   = \WCPOS\WooCommercePOS\Services\Settings::instance();
$rep = 200;
$t   = hrtime( true );
for ( $i = 0; $i < $rep; $i++ ) {
	$a = $s->get_pos_only_product_visibility_settings();
}
printf( "product read %.3f ms/call\n", ( hrtime( true ) - $t ) / 1e6 / $rep );
$a = $s->get_pos_only_product_visibility_settings()['ids'];
$b = $s->get_pos_only_product_visibility_settings()['ids'];
$t = hrtime( true );
for ( $i = 0; $i < $rep; $i++ ) {
	$same = ( $a === $b );
}
printf( "=== of two reads %.4f ms (count %d, equal %d)\n", ( hrtime( true ) - $t ) / 1e6 / $rep, count( $a ), (int) $same );
