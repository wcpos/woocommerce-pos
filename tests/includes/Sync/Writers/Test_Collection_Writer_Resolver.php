<?php
/** Resolver tests. @package WCPOS\WooCommercePOS\Tests\Sync\Writers */
namespace WCPOS\WooCommercePOS\Tests\Sync\Writers;

use WCPOS\WooCommercePOS\API\V2\Writers\Collection_Writer_Resolver;
use WCPOS\WooCommercePOS\API\V2\Writers\Customer_Writer;
use WCPOS\WooCommercePOS\API\V2\Writers\Null_Writer;
use WCPOS\WooCommercePOS\API\V2\Writers\Order_Writer;
use WCPOS\WooCommercePOS\API\V2\Writers\Variation_Writer;
use WP_UnitTestCase;

/** Pins metadata-to-writer selection, including the variation override. */
class Test_Collection_Writer_Resolver extends WP_UnitTestCase {
	/** Resolve every approved metadata shape. */
	public function test_resolve_selects_writer(): void {
		$resolver = new Collection_Writer_Resolver();
		$this->assertInstanceOf( Order_Writer::class, $resolver->resolve( array( 'id_type' => 'order' ) ) );
		$this->assertInstanceOf( Customer_Writer::class, $resolver->resolve( array( 'id_type' => 'user' ) ) );
		$this->assertInstanceOf( Variation_Writer::class, $resolver->resolve( array( 'id_type' => 'post', 'post_type' => 'product_variation' ) ) );
		$this->assertInstanceOf( Null_Writer::class, $resolver->resolve( array( 'id_type' => 'post', 'post_type' => 'product' ) ) );
	}
}
