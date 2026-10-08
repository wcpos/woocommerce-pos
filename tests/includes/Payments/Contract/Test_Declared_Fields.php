<?php
/**
 * Declared payment field tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Tests\Payments\Contract;

use WCPOS\WooCommercePOS\Payments\Contract\Declared_Fields;
use WCPOS\WooCommercePOS\Payments\Contract\Descriptor_Builder;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/** The field vocabulary is closed and markup-free. */
class Test_Declared_Fields extends WCPOS_REST_Unit_Test_Case {
	/** Unknown components are dropped and invalid vocabulary defaults. */
	public function test_fields_invalid_vocabulary_is_normalized(): void {
		// Arrange.
		$filter = static fn() => array(
			'verb' => array(
				'kind' => 'bad',
				'label' => '',
			),
			'components' => array(
				array(
					'component' => 'field',
					'id' => 'email',
					'input' => 'email',
					'label' => '<b>Email</b>',
					'class' => 'bad',
				),
				array(
					'component' => 'checkbox',
					'id' => 'save',
					'default' => true,
				),
				array(
					'component' => 'unknown',
					'id' => 'nope',
				),
				array(
					'component' => 'field',
					'id' => 'phone',
					'input' => 'bad',
					'prefill' => 'secret',
				),
				array(
					'component' => 'field',
					'id' => 'email',
				),
				array(
					'component' => 'select',
					'id' => 'invalid',
					'options' => array(),
				),
			),
		);
		add_filter( 'wcpos_payment_method_fields', $filter );
		try {
			// Act.
			$gateway = Descriptor_Builder::instance()->gateway( 'pos_cash' );
			$fields = Declared_Fields::for_gateway( $gateway );
			// Assert.
			$this->assertSame(
				array(
					'kind' => 'take',
					'label' => $gateway->get_title(),
				),
				$fields['verb']
			);
			$this->assertCount( 3, $fields['components'] );
			$this->assertSame( 'Email', $fields['components'][0]['label'] );
			$this->assertArrayNotHasKey( 'class', $fields['components'][0] );
			$this->assertTrue( $fields['components'][1]['default'] );
			$this->assertSame( 'text', $fields['components'][2]['input'] );
			$this->assertNull( $fields['components'][2]['prefill'] );
		} finally {
			remove_filter( 'wcpos_payment_method_fields', $filter );
		}
	}

	/** No declaration means no descriptor key. */
	public function test_fields_null_declaration_omits_key(): void {
		// Arrange / Act / Assert.
		$this->assertArrayNotHasKey( 'fields', Descriptor_Builder::instance()->get( 'pos_cash' ) );
	}

	/** The published invoice declaration is preserved exactly. */
	public function test_fields_email_declaration_round_trips(): void {
		// Arrange.
		$block = array(
			'schema' => 1,
			'verb' => array(
				'kind' => 'send',
				'label' => 'Send invoice',
			),
			'components' => array(
				array(
					'component' => 'field',
					'id' => 'woocommerce_pos_invoice_email_address',
					'input' => 'email',
					'label' => 'Email address',
					'required' => true,
					'default' => '',
					'prefill' => 'order.billing.email',
				),
				array(
					'component' => 'checkbox',
					'id' => 'woocommerce_pos_save_billing_email',
					'label' => 'Save email to billing address',
					'default' => false,
					'prefill' => null,
				),
			),
		);
		$filter = static fn() => $block;
		add_filter( 'wcpos_payment_method_fields', $filter );
		try {
			// Act / Assert.
			$this->assertSame( $block, Descriptor_Builder::instance()->get( 'pos_cash' )['fields'] );
		} finally {
			remove_filter( 'wcpos_payment_method_fields', $filter );
		}
	}

	/** Select and note keep only their declared keys. */
	public function test_fields_select_and_note_drop_markup_and_extra_keys(): void {
		// Arrange.
		$filter = static fn() => array(
			'components' => array(
				array(
					'component' => 'note',
					'text' => '<b>Pay later</b>',
					'id' => 'ignored',
				),
				array(
					'component' => 'select',
					'id' => 'choice',
					'options' => array(
						array(
							'value' => 'a',
							'label' => '<i>A</i>',
							'style' => 'x',
						),
					),
				),
			),
		);
		add_filter( 'wcpos_payment_method_fields', $filter );
		try {
			// Act.
			$components = Descriptor_Builder::instance()->get( 'pos_cash' )['fields']['components'];
			// Assert.
			$this->assertSame(
				array(
					'component' => 'note',
					'text' => 'Pay later',
				),
				$components[0]
			);
			$this->assertSame(
				array(
					array(
						'value' => 'a',
						'label' => 'A',
					),
				),
				$components[1]['options']
			);
		} finally {
			remove_filter( 'wcpos_payment_method_fields', $filter );
		}
	}
}
