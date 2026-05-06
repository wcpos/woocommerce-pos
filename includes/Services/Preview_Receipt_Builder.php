<?php
/**
 * Preview receipt data builder service.
 *
 * Generates realistic sample receipt data for template gallery/editor
 * previews using the store's actual WooCommerce settings.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\WooCommercePOS\Abstracts\Store;

/**
 * Preview_Receipt_Builder class.
 *
 * Builds a sample receipt payload that mirrors the real Receipt_Data_Builder
 * output but uses the store's catalog products, tax rates, and settings
 * instead of data from an actual order.
 */
class Preview_Receipt_Builder {

	/**
	 * POS store object used to populate store fields.
	 *
	 * @var object
	 */
	private $pos_store;

	/**
	 * Fallback tax rate percentage when no WooCommerce tax rates are configured.
	 *
	 * @var float
	 */
	const FALLBACK_TAX_RATE = 10.0;

	/**
	 * Quantities assigned to each line item (up to 3 products).
	 *
	 * @var int[]
	 */
	const LINE_QUANTITIES = array( 2, 1, 1 );

	/**
	 * Minimum number of line items in the preview.
	 *
	 * @var int
	 */
	const MIN_LINES = 2;

	/**
	 * Sample customer data keyed by ISO 3166-1 alpha-2 country code.
	 *
	 * Each entry provides first_name, last_name, address_1, address_2, email,
	 * and phone fields. City, state, postcode, and country are filled from the
	 * store's WooCommerce settings at runtime. The 'US' entry is the fallback
	 * used for any country not listed here.
	 *
	 * @var array<string, array<string, string>>
	 */
	const SAMPLE_CUSTOMERS = array(
		'US' => array(
			'first_name' => 'Sarah',
			'last_name'  => 'Johnson',
			'address_1'  => '456 Oak Avenue',
			'address_2'  => 'Suite 200',
			'email'      => 'sarah.johnson@example.com',
			'phone'      => '(555) 987-6543',
		),
		'GB' => array(
			'first_name' => 'James',
			'last_name'  => 'Smith',
			'address_1'  => '12 Baker Street',
			'address_2'  => 'Flat 3',
			'email'      => 'james.smith@example.co.uk',
			'phone'      => '07700 900123',
		),
		'DE' => array(
			'first_name' => 'Anna',
			'last_name'  => 'Mueller',
			'address_1'  => 'Hauptstraße 42',
			'address_2'  => '',
			'email'      => 'anna.mueller@example.de',
			'phone'      => '030 12345678',
		),
		'FR' => array(
			'first_name' => 'Marie',
			'last_name'  => 'Dupont',
			'address_1'  => '15 Rue de Rivoli',
			'address_2'  => 'Apt 4B',
			'email'      => 'marie.dupont@example.fr',
			'phone'      => '01 23 45 67 89',
		),
		'AU' => array(
			'first_name' => 'Liam',
			'last_name'  => 'Wilson',
			'address_1'  => '88 George Street',
			'address_2'  => '',
			'email'      => 'liam.wilson@example.com.au',
			'phone'      => '02 9876 5432',
		),
		'JP' => array(
			'first_name' => 'Yuki',
			'last_name'  => 'Tanaka',
			'address_1'  => '1-2-3 Shibuya',
			'address_2'  => '',
			'email'      => 'yuki.tanaka@example.jp',
			'phone'      => '03-1234-5678',
		),
		'NL' => array(
			'first_name' => 'Lars',
			'last_name'  => 'de Vries',
			'address_1'  => 'Keizersgracht 123',
			'address_2'  => '',
			'email'      => 'lars.devries@example.nl',
			'phone'      => '020 123 4567',
		),
		'ES' => array(
			'first_name' => 'Carmen',
			'last_name'  => 'García',
			'address_1'  => 'Calle Mayor 7',
			'address_2'  => '',
			'email'      => 'carmen.garcia@example.es',
			'phone'      => '91 234 56 78',
		),
		'IT' => array(
			'first_name' => 'Marco',
			'last_name'  => 'Rossi',
			'address_1'  => 'Via Roma 55',
			'address_2'  => '',
			'email'      => 'marco.rossi@example.it',
			'phone'      => '06 1234 5678',
		),
		'CA' => array(
			'first_name' => 'Emma',
			'last_name'  => 'Brown',
			'address_1'  => '200 Bay Street',
			'address_2'  => 'Unit 1500',
			'email'      => 'emma.brown@example.ca',
			'phone'      => '(416) 555-0123',
		),
		'NZ' => array(
			'first_name' => 'Oliver',
			'last_name'  => 'Taylor',
			'address_1'  => '42 Lambton Quay',
			'address_2'  => '',
			'email'      => 'oliver.taylor@example.co.nz',
			'phone'      => '04 123 4567',
		),
		'IN' => array(
			'first_name' => 'Priya',
			'last_name'  => 'Sharma',
			'address_1'  => '14 MG Road',
			'address_2'  => '',
			'email'      => 'priya.sharma@example.in',
			'phone'      => '98765 43210',
		),
		'BR' => array(
			'first_name' => 'Ana',
			'last_name'  => 'Silva',
			'address_1'  => 'Rua Augusta 1200',
			'address_2'  => 'Apto 42',
			'email'      => 'ana.silva@example.com.br',
			'phone'      => '(11) 98765-4321',
		),
		'MX' => array(
			'first_name' => 'Sofía',
			'last_name'  => 'Hernández',
			'address_1'  => 'Av. Reforma 222',
			'address_2'  => 'Piso 3',
			'email'      => 'sofia.hernandez@example.com.mx',
			'phone'      => '55 1234 5678',
		),
		'SE' => array(
			'first_name' => 'Erik',
			'last_name'  => 'Lindqvist',
			'address_1'  => 'Drottninggatan 50',
			'address_2'  => '',
			'email'      => 'erik.lindqvist@example.se',
			'phone'      => '08-123 456 78',
		),
		'NO' => array(
			'first_name' => 'Ingrid',
			'last_name'  => 'Hansen',
			'address_1'  => 'Karl Johans gate 10',
			'address_2'  => '',
			'email'      => 'ingrid.hansen@example.no',
			'phone'      => '22 12 34 56',
		),
		'DK' => array(
			'first_name' => 'Mads',
			'last_name'  => 'Nielsen',
			'address_1'  => 'Strøget 28',
			'address_2'  => '',
			'email'      => 'mads.nielsen@example.dk',
			'phone'      => '33 12 34 56',
		),
		'FI' => array(
			'first_name' => 'Aino',
			'last_name'  => 'Virtanen',
			'address_1'  => 'Mannerheimintie 15',
			'address_2'  => '',
			'email'      => 'aino.virtanen@example.fi',
			'phone'      => '09 123 4567',
		),
		'BE' => array(
			'first_name' => 'Lucas',
			'last_name'  => 'Peeters',
			'address_1'  => 'Meir 48',
			'address_2'  => '',
			'email'      => 'lucas.peeters@example.be',
			'phone'      => '03 123 45 67',
		),
		'AT' => array(
			'first_name' => 'Katharina',
			'last_name'  => 'Gruber',
			'address_1'  => 'Kärntner Straße 21',
			'address_2'  => '',
			'email'      => 'katharina.gruber@example.at',
			'phone'      => '01 234 5678',
		),
		'CH' => array(
			'first_name' => 'Lukas',
			'last_name'  => 'Meier',
			'address_1'  => 'Bahnhofstrasse 30',
			'address_2'  => '',
			'email'      => 'lukas.meier@example.ch',
			'phone'      => '044 123 45 67',
		),
		'PT' => array(
			'first_name' => 'Maria',
			'last_name'  => 'Santos',
			'address_1'  => 'Rua Augusta 85',
			'address_2'  => '',
			'email'      => 'maria.santos@example.pt',
			'phone'      => '21 123 4567',
		),
		'PL' => array(
			'first_name' => 'Katarzyna',
			'last_name'  => 'Nowak',
			'address_1'  => 'ul. Marszałkowska 12',
			'address_2'  => '',
			'email'      => 'katarzyna.nowak@example.pl',
			'phone'      => '22 123 45 67',
		),
		'CZ' => array(
			'first_name' => 'Tereza',
			'last_name'  => 'Nováková',
			'address_1'  => 'Václavské náměstí 30',
			'address_2'  => '',
			'email'      => 'tereza.novakova@example.cz',
			'phone'      => '221 234 567',
		),
		'IE' => array(
			'first_name' => 'Aoife',
			'last_name'  => 'Murphy',
			'address_1'  => '22 Grafton Street',
			'address_2'  => '',
			'email'      => 'aoife.murphy@example.ie',
			'phone'      => '01 234 5678',
		),
		'ZA' => array(
			'first_name' => 'Thandiwe',
			'last_name'  => 'Ndlovu',
			'address_1'  => '100 Long Street',
			'address_2'  => '',
			'email'      => 'thandiwe.ndlovu@example.co.za',
			'phone'      => '021 123 4567',
		),
		'SG' => array(
			'first_name' => 'Wei Lin',
			'last_name'  => 'Tan',
			'address_1'  => '1 Raffles Place',
			'address_2'  => '#08-01',
			'email'      => 'weilin.tan@example.sg',
			'phone'      => '6123 4567',
		),
		'HK' => array(
			'first_name' => 'Wing',
			'last_name'  => 'Chan',
			'address_1'  => '88 Queens Road Central',
			'address_2'  => 'Flat 12A',
			'email'      => 'wing.chan@example.hk',
			'phone'      => '2123 4567',
		),
		'KR' => array(
			'first_name' => 'Jimin',
			'last_name'  => 'Park',
			'address_1'  => '25 Gangnam-daero',
			'address_2'  => '',
			'email'      => 'jimin.park@example.kr',
			'phone'      => '02-1234-5678',
		),
		'MY' => array(
			'first_name' => 'Nurul',
			'last_name'  => 'Ahmad',
			'address_1'  => 'Jalan Bukit Bintang 55',
			'address_2'  => '',
			'email'      => 'nurul.ahmad@example.my',
			'phone'      => '03-1234 5678',
		),
		'TH' => array(
			'first_name' => 'Siriporn',
			'last_name'  => 'Suksawat',
			'address_1'  => '99 Sukhumvit Road',
			'address_2'  => '',
			'email'      => 'siriporn.s@example.co.th',
			'phone'      => '02 123 4567',
		),
		'PH' => array(
			'first_name' => 'Maria',
			'last_name'  => 'Reyes',
			'address_1'  => '123 Ayala Avenue',
			'address_2'  => '',
			'email'      => 'maria.reyes@example.ph',
			'phone'      => '02 8123 4567',
		),
		'AE' => array(
			'first_name' => 'Fatima',
			'last_name'  => 'Al Maktoum',
			'address_1'  => 'Sheikh Zayed Road',
			'address_2'  => 'Tower 1, Office 501',
			'email'      => 'fatima.m@example.ae',
			'phone'      => '04 123 4567',
		),
		'SA' => array(
			'first_name' => 'Nora',
			'last_name'  => 'Al-Rashid',
			'address_1'  => 'King Fahd Road',
			'address_2'  => '',
			'email'      => 'nora.alrashid@example.sa',
			'phone'      => '011 234 5678',
		),
		'IL' => array(
			'first_name' => 'Noa',
			'last_name'  => 'Cohen',
			'address_1'  => 'Rothschild Blvd 40',
			'address_2'  => '',
			'email'      => 'noa.cohen@example.co.il',
			'phone'      => '03-123-4567',
		),
		'TR' => array(
			'first_name' => 'Elif',
			'last_name'  => 'Yılmaz',
			'address_1'  => 'İstiklal Caddesi 45',
			'address_2'  => '',
			'email'      => 'elif.yilmaz@example.com.tr',
			'phone'      => '0212 123 4567',
		),
		'RU' => array(
			'first_name' => 'Anastasia',
			'last_name'  => 'Ivanova',
			'address_1'  => 'Tverskaya Ulitsa 12',
			'address_2'  => 'Kv. 5',
			'email'      => 'anastasia.ivanova@example.ru',
			'phone'      => '+7 495 123-45-67',
		),
		'GR' => array(
			'first_name' => 'Eleni',
			'last_name'  => 'Papadopoulos',
			'address_1'  => 'Ermou 25',
			'address_2'  => '',
			'email'      => 'eleni.p@example.gr',
			'phone'      => '210 123 4567',
		),
		'RO' => array(
			'first_name' => 'Andreea',
			'last_name'  => 'Popescu',
			'address_1'  => 'Calea Victoriei 100',
			'address_2'  => '',
			'email'      => 'andreea.popescu@example.ro',
			'phone'      => '021 123 4567',
		),
		'HU' => array(
			'first_name' => 'Anna',
			'last_name'  => 'Szabó',
			'address_1'  => 'Andrássy út 60',
			'address_2'  => '',
			'email'      => 'anna.szabo@example.hu',
			'phone'      => '1 234 5678',
		),
		'ID' => array(
			'first_name' => 'Siti',
			'last_name'  => 'Rahayu',
			'address_1'  => 'Jl. Sudirman No. 52',
			'address_2'  => '',
			'email'      => 'siti.rahayu@example.co.id',
			'phone'      => '021-1234567',
		),
		'TW' => array(
			'first_name' => 'Mei-Ling',
			'last_name'  => 'Chen',
			'address_1'  => 'Zhongxiao East Road Sec 4, No 12',
			'address_2'  => '3F',
			'email'      => 'meiling.chen@example.tw',
			'phone'      => '02-2771-1234',
		),
		'CN' => array(
			'first_name' => 'Xiao',
			'last_name'  => 'Wang',
			'address_1'  => 'Nanjing Road 200',
			'address_2'  => '',
			'email'      => 'xiao.wang@example.cn',
			'phone'      => '021-6234-5678',
		),
		'VN' => array(
			'first_name' => 'Linh',
			'last_name'  => 'Nguyen',
			'address_1'  => '45 Le Loi Street',
			'address_2'  => '',
			'email'      => 'linh.nguyen@example.vn',
			'phone'      => '028 1234 5678',
		),
		'AR' => array(
			'first_name' => 'Valentina',
			'last_name'  => 'Fernández',
			'address_1'  => 'Av. Corrientes 1234',
			'address_2'  => 'Piso 5',
			'email'      => 'valentina.f@example.com.ar',
			'phone'      => '11 1234-5678',
		),
		'CO' => array(
			'first_name' => 'Camila',
			'last_name'  => 'Rodríguez',
			'address_1'  => 'Carrera 7 No. 71-52',
			'address_2'  => '',
			'email'      => 'camila.r@example.co',
			'phone'      => '601 234 5678',
		),
		'CL' => array(
			'first_name' => 'Catalina',
			'last_name'  => 'Muñoz',
			'address_1'  => 'Av. Providencia 1200',
			'address_2'  => 'Of. 301',
			'email'      => 'catalina.munoz@example.cl',
			'phone'      => '2 2345 6789',
		),
	);

	/**
	 * Fallback product definitions when the catalog is empty.
	 *
	 * @var array[]
	 */
	const FALLBACK_PRODUCTS = array(
		array(
			'name'  => 'Premium Widget',
			'price' => 29.99,
			'sku'   => 'WIDGET-001',
			'meta'  => array(
				array(
					'key'   => 'Size',
					'value' => 'Large',
				),
				array(
					'key'   => 'Color',
					'value' => 'Midnight Blue',
				),
			),
		),
		array(
			'name'  => 'Standard Gadget',
			'price' => 15.50,
			'sku'   => 'GADGET-002',
			'meta'  => array(
				array(
					'key'   => 'Material',
					'value' => 'Stainless Steel',
				),
			),
		),
		array(
			'name'  => 'Deluxe Component',
			'price' => 42.00,
			'sku'   => 'COMP-003',
			'meta'  => array(),
		),
	);

	/**
	 * Build a preview receipt payload.
	 *
	 * Returns an array matching the receipt data schema with all sections
	 * populated using the store's real settings, products, and tax rates.
	 *
	 * @param object|null $pos_store POS store object. Falls back to default store.
	 *
	 * @return array Complete receipt data array.
	 */
	public function build( $pos_store = null ): array {
		$resolved_store = null === $pos_store ? wcpos_get_store() : $pos_store;
		if ( ! \is_object( $resolved_store ) ) {
			$resolved_store = wcpos_get_store();
		}
		$this->pos_store = \is_object( $resolved_store ) ? $resolved_store : new Store();
		$currency     = $this->resolve_currency();
		$display_incl = 'incl' === $this->resolve_store_string(
			'get_tax_display_cart',
			get_option( 'woocommerce_tax_display_cart', 'excl' )
		);
		$tax_enabled  = 'yes' === $this->resolve_store_string(
			'get_calc_taxes',
			get_option( 'woocommerce_calc_taxes', 'no' )
		);
		$tax_config   = $this->get_tax_config( $tax_enabled );
		$tax_rate     = $tax_config['rate'];
		$tax_label    = $tax_config['label'];
		$tax_code     = $tax_config['code'];

		$raw_products       = $this->get_products();
		$prices_include_tax = 'yes' === $this->resolve_store_string(
			'get_prices_include_tax',
			wc_prices_include_tax() ? 'yes' : 'no'
		);
		$dp                 = $this->resolve_price_num_decimals();

		// Build line items.
		$lines            = array();
		$lines_total_excl = 0.0;
		$lines_total_incl = 0.0;

		foreach ( $raw_products as $index => $product ) {
			$qty        = self::LINE_QUANTITIES[ $index ] ?? 1;
			$base_price = (float) $product['price'];

			if ( $prices_include_tax ) {
				$unit_incl = $base_price;
				$unit_excl = $base_price / ( 1 + $tax_rate / 100 );
			} else {
				$unit_excl = $base_price;
				$unit_incl = $base_price * ( 1 + $tax_rate / 100 );
			}

			$line_total_incl = round( $unit_incl * $qty, $dp );
			$line_total_excl = round( $unit_excl * $qty, $dp );

			$unit_price_rounded = round( $display_incl ? $unit_incl : $unit_excl, $dp );

			$lines[] = array(
				'key'                => (string) ( $index + 1 ),
				'sku'                => $product['sku'],
				'name'               => $product['name'],
				'qty'                => (float) $qty,
				'unit_subtotal'      => $unit_price_rounded,
				'unit_subtotal_incl' => round( $unit_incl, $dp ),
				'unit_subtotal_excl' => round( $unit_excl, $dp ),
				'unit_price'         => $unit_price_rounded,
				'unit_price_incl'    => round( $unit_incl, $dp ),
				'unit_price_excl'    => round( $unit_excl, $dp ),
				'line_subtotal'      => $display_incl ? $line_total_incl : $line_total_excl,
				'line_subtotal_incl' => $line_total_incl,
				'line_subtotal_excl' => $line_total_excl,
				'discounts'          => 0.0,
				'discounts_incl'     => 0.0,
				'discounts_excl'     => 0.0,
				'line_total'         => $display_incl ? $line_total_incl : $line_total_excl,
				'line_total_incl'    => $line_total_incl,
				'line_total_excl'    => $line_total_excl,
				'taxes'              => array(),
				'meta'               => $product['meta'] ?? array(),
			);

			$lines_total_excl += $line_total_excl;
			$lines_total_incl += $line_total_incl;
		}

		// Fee (excl tax).
		$fee_excl      = 2.50;
		$fee_tax       = round( $fee_excl * $tax_rate / 100, $dp );
		$fee_incl      = $fee_excl + $fee_tax;
		$fee_label     = __( 'Gift Wrapping', 'woocommerce-pos' );

		// Shipping (excl tax).
		$shipping_excl     = 10.00;
		$shipping_tax      = round( $shipping_excl * $tax_rate / 100, $dp );
		$shipping_incl     = $shipping_excl + $shipping_tax;
		$shipping_label    = __( 'Flat Rate Shipping', 'woocommerce-pos' );

		// Discount: 10% of line items excl total.
		$discount_rate  = 10.0;
		$discount_excl  = round( $lines_total_excl * $discount_rate / 100, $dp );
		$discount_tax   = round( $discount_excl * $tax_rate / 100, $dp );
		$discount_incl  = $discount_excl + $discount_tax;
		/* translators: %s: discount percentage */
		$discount_label = sprintf( __( 'Summer Sale (%s%%)', 'woocommerce-pos' ), (int) $discount_rate );

		// Distribute discount proportionally across line items with remainder correction.
		$sum_discount_excl = 0.0;
		$sum_discount_incl = 0.0;
		$last_index        = count( $lines ) - 1;

		foreach ( $lines as $i => &$line ) {
			if ( $lines_total_excl > 0 ) {
				$share = $line['line_subtotal_excl'] / $lines_total_excl;
			} else {
				$share = 1.0 / count( $lines );
			}

			if ( $i < $last_index ) {
				$line['discounts_excl'] = round( $discount_excl * $share, $dp );
				$line['discounts_incl'] = round( $discount_incl * $share, $dp );
				$sum_discount_excl     += $line['discounts_excl'];
				$sum_discount_incl     += $line['discounts_incl'];
			} else {
				// Assign remainder to last item so distributed totals match exactly.
				$line['discounts_excl'] = round( $discount_excl - $sum_discount_excl, $dp );
				$line['discounts_incl'] = round( $discount_incl - $sum_discount_incl, $dp );
			}

			$line['discounts']       = $display_incl ? $line['discounts_incl'] : $line['discounts_excl'];
			$line['line_total_excl'] = round( $line['line_subtotal_excl'] - $line['discounts_excl'], $dp );
			$line['line_total_incl'] = round( $line['line_subtotal_incl'] - $line['discounts_incl'], $dp );
			$line['line_total']      = $display_incl ? $line['line_total_incl'] : $line['line_total_excl'];
			$line['unit_price_incl'] = round( $line['line_total_incl'] / $line['qty'], $dp );
			$line['unit_price_excl'] = round( $line['line_total_excl'] / $line['qty'], $dp );
			$line['unit_price']      = $display_incl ? $line['unit_price_incl'] : $line['unit_price_excl'];
		}
		unset( $line );

		// Totals.
		$subtotal_excl = $lines_total_excl;
		$subtotal_incl = $lines_total_incl;

		// Taxable base: line items - discount + shipping + fee (all excl).
		$taxable_excl = $subtotal_excl - $discount_excl + $shipping_excl + $fee_excl;
		$total_tax    = round( $taxable_excl * $tax_rate / 100, $dp );

		$total_excl = $subtotal_excl - $discount_excl + $shipping_excl + $fee_excl;
		$total_incl = $total_excl + $total_tax;

		// Payment: cash rounded up to nearest 5.
		$tendered     = (float) ( ceil( $total_incl / 5 ) * 5 );
		$change_total = round( $tendered - $total_incl, $dp );

		$created_timestamp   = strtotime( '2024-01-15 10:30:00 UTC' );
		$paid_timestamp      = strtotime( '2024-01-15 10:35:00 UTC' );
		$completed_timestamp = strtotime( '2024-01-15 10:42:00 UTC' );

		$order = array(
			'id'            => 1234,
			'number'        => '1234',
			'currency'      => $currency,
			'customer_note' => __( 'Please gift wrap this order. Thank you!', 'woocommerce-pos' ),
			'wc_status'     => 'completed',
			'created_via'   => 'woocommerce-pos',
			'created'       => Receipt_Date_Formatter::from_timestamp( $created_timestamp, wp_timezone() ),
			'paid'          => Receipt_Date_Formatter::from_timestamp( $paid_timestamp, wp_timezone() ),
			'completed'     => Receipt_Date_Formatter::from_timestamp( $completed_timestamp, wp_timezone() ),
		);

		$store = $this->get_store_info();

		$cashier = $this->get_cashier();

		$customer = $this->get_customer();

		$fees = array(
			array(
				'label'      => $fee_label,
				'total'      => $display_incl ? $fee_incl : $fee_excl,
				'total_incl' => $fee_incl,
				'total_excl' => $fee_excl,
			),
		);

		$shipping = array(
			array(
				'label'      => $shipping_label,
				'total'      => $display_incl ? $shipping_incl : $shipping_excl,
				'total_incl' => $shipping_incl,
				'total_excl' => $shipping_excl,
			),
		);

		$discounts = array(
			array(
				'label'      => $discount_label,
				'codes'      => 'SUMMER10',
				'total'      => $display_incl ? $discount_incl : $discount_excl,
				'total_incl' => $discount_incl,
				'total_excl' => $discount_excl,
			),
		);

		$totals = array(
			'subtotal'            => $display_incl ? $subtotal_incl : $subtotal_excl,
			'subtotal_incl'       => $subtotal_incl,
			'subtotal_excl'       => $subtotal_excl,
			'discount_total'      => $display_incl ? $discount_incl : $discount_excl,
			'discount_total_incl' => $discount_incl,
			'discount_total_excl' => $discount_excl,
			'tax_total'           => $total_tax,
			'total'         => $display_incl ? $total_incl : $total_excl,
			'total_incl'    => $total_incl,
			'total_excl'    => $total_excl,
			'paid_total'          => $total_incl,
			'change_total'        => $change_total,
		);

		$taxable_amount_incl = $taxable_excl + $total_tax;

		if ( $tax_rate > 0 ) {
			$tax_summary = array(
				array(
					'code'                => $tax_code,
					'rate'                => $tax_rate,
					'label'               => $tax_label,
					'taxable_amount_excl' => $taxable_excl,
					'tax_amount'          => $total_tax,
					'taxable_amount_incl' => $taxable_amount_incl,
				),
			);
		} else {
			$tax_summary = array();
		}

		$payments = array(
			array(
				'method_id'      => 'pos_cash',
				'method_title'   => __( 'Cash', 'woocommerce-pos' ),
				'amount'         => $total_incl,
				'transaction_id' => '',
				'tendered'       => $tendered,
				'change'         => $change_total,
			),
		);

		$refunds = array();

		$presentation_hints = $this->build_presentation_hints( $currency, $tax_enabled, $prices_include_tax );

		$fiscal = array(
			'immutable_id'      => '12345:42',
			'receipt_number'    => '00042',
			'sequence'          => 42,
			'hash'              => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2',
			'qr_payload'        => 'https://example.com/verify?id=SAMPLE-001',
			'tax_agency_code'   => 'SAMPLE',
			'signed_at'         => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'signature_excerpt' => 'A1B2',
			'document_label'    => __( 'Tax Receipt', 'woocommerce-pos' ),
			'is_reprint'        => false,
			'reprint_count'     => 0,
			'extra_fields'      => array(
				array(
					'label' => __( 'Tax ID', 'woocommerce-pos' ),
					'value' => 'XX-1234567',
				),
				array(
					'label' => __( 'Auth Code', 'woocommerce-pos' ),
					'value' => 'ABC-789',
				),
			),
		);

		return array(
			'order'              => $order,
			'store'              => $store,
			'cashier'            => $cashier,
			'customer'           => $customer,
			'lines'              => $lines,
			'fees'               => $fees,
			'shipping'           => $shipping,
			'discounts'          => $discounts,
			'totals'             => $totals,
			'tax_summary'        => $tax_summary,
			'payments'           => $payments,
			'refunds'            => $refunds,
			'fiscal'             => $fiscal,
			'presentation_hints' => $presentation_hints,
			'i18n'               => Receipt_I18n_Labels::get_labels( $presentation_hints['locale'] ?? '' ),
		);
	}

	/**
	 * Get store information from the POS store object.
	 *
	 * @return array Store data with name, address, branding, and policy fields.
	 */
	private function get_store_info(): array {
		$pos_store = $this->pos_store;
		$store_name      = (string) $this->get_store_value( $pos_store, 'get_name', '' );
		$store_address   = (string) $this->get_store_value( $pos_store, 'get_store_address', '' );
		$store_address_2 = (string) $this->get_store_value( $pos_store, 'get_store_address_2', '' );
		$store_city      = (string) $this->get_store_value( $pos_store, 'get_store_city', '' );
		$store_postcode  = (string) $this->get_store_value( $pos_store, 'get_store_postcode', '' );
		$store_country   = (string) $this->get_store_value( $pos_store, 'get_store_country', '' );
		$store_state     = (string) $this->get_store_value( $pos_store, 'get_store_state', '' );
		$store_phone     = (string) $this->get_store_value( $pos_store, 'get_phone', '' );
		$store_email     = (string) $this->get_store_value( $pos_store, 'get_email', '' );
		$store_tax_id    = (string) $this->get_store_value( $pos_store, 'get_tax_id', '' );
		$store_tax_ids   = $this->get_store_value( $pos_store, 'get_tax_ids', array() );
		$store_tax_ids   = is_array( $store_tax_ids ) ? $store_tax_ids : array();
		$store_tax_ids   = self::with_store_tax_id_labels( $store_tax_ids );

		$store = array(
			'name'          => '' !== $store_name ? $store_name : get_bloginfo( 'name' ),
			'address'       => array(
				'address_1' => $store_address,
				'address_2' => $store_address_2,
				'city'      => $store_city,
				'state'     => $store_state,
				'postcode'  => $store_postcode,
				'country'   => $store_country,
			),
			'address_lines' => array_values(
				array_filter(
					array(
						$store_address,
						$store_address_2,
						trim( $store_city . ' ' . $store_postcode ),
						$this->format_country_state( $store_country, $store_state ),
					)
				)
			),
			'tax_id'        => $store_tax_id,
			'tax_ids'       => $store_tax_ids,
			'phone'         => $store_phone,
			'email'         => $store_email,
		);

		$opening_hours_raw       = $this->get_store_value( $pos_store, 'get_opening_hours', array() );
		$personal_notes          = (string) $this->get_store_value( $pos_store, 'get_personal_notes', '' );
		$policies_and_conditions = (string) $this->get_store_value( $pos_store, 'get_policies_and_conditions', '' );
		$footer_imprint          = (string) $this->get_store_value( $pos_store, 'get_footer_imprint', '' );

		if ( ! empty( $opening_hours_raw ) && \is_array( $opening_hours_raw ) ) {
			$store['opening_hours']          = Opening_Hours_Formatter::format_compact( $opening_hours_raw );
			$store['opening_hours_vertical'] = Opening_Hours_Formatter::format_vertical( $opening_hours_raw );
			$store['opening_hours_inline']   = Opening_Hours_Formatter::format_inline( $opening_hours_raw );
		} elseif ( \is_string( $opening_hours_raw ) && '' !== trim( $opening_hours_raw ) ) {
			$store['opening_hours']          = $opening_hours_raw;
			$store['opening_hours_vertical'] = null;
			$store['opening_hours_inline']   = null;
		} else {
			// Sample hours for preview when none configured.
			$opening_hours_raw = array(
				'0' => array( '09:00', '17:00' ),
				'1' => array( '09:00', '17:00' ),
				'2' => array( '09:00', '17:00' ),
				'3' => array( '09:00', '17:00' ),
				'4' => array( '09:00', '17:00' ),
				'5' => array( '10:00', '16:00' ),
				'6' => array(),
			);
			$store['opening_hours']          = Opening_Hours_Formatter::format_compact( $opening_hours_raw );
			$store['opening_hours_vertical'] = Opening_Hours_Formatter::format_vertical( $opening_hours_raw );
			$store['opening_hours_inline']   = Opening_Hours_Formatter::format_inline( $opening_hours_raw );
		}
		$store['logo']                    = Store_Logo_Resolver::resolve( $pos_store );
		$opening_hours_notes              = (string) $this->get_store_value( $pos_store, 'get_opening_hours_notes', '' );
		$store['opening_hours_notes']     = '' !== $opening_hours_notes ? $opening_hours_notes : null;
		$store['personal_notes']          = ( null !== $personal_notes && '' !== $personal_notes )
			? $personal_notes
			: __( 'Thank you for shopping with us! We appreciate your business.', 'woocommerce-pos' );
		$store['policies_and_conditions'] = ( null !== $policies_and_conditions && '' !== $policies_and_conditions )
			? $policies_and_conditions
			: __( 'Returns accepted within 30 days with original receipt. Items must be unused and in original packaging.', 'woocommerce-pos' );
		$store['footer_imprint']          = ( null !== $footer_imprint && '' !== $footer_imprint )
			? $footer_imprint
			: __( 'Thank you for your purchase!', 'woocommerce-pos' );

		return $store;
	}

	/**
	 * Format country and state codes into display names.
	 *
	 * Converts codes like "US" / "AL" to "Alabama, United States (US)".
	 *
	 * @param string $country Country code.
	 * @param string $state   State code.
	 *
	 * @return string
	 */
	private function format_country_state( string $country, string $state ): string {
		if ( '' === $country ) {
			return '';
		}

		$country_name = WC()->countries->get_countries()[ $country ] ?? $country;

		if ( '' !== $state ) {
			$states     = WC()->countries->get_states( $country );
			$state_name = $states[ $state ] ?? $state;

			return $state_name . ', ' . $country_name;
		}

		return $country_name;
	}

	/**
	 * Safely read a value from a POS store object.
	 *
	 * @param mixed  $pos_store Store object.
	 * @param string $getter    Getter method name.
	 * @param mixed  $fallback  Fallback value.
	 *
	 * @return mixed
	 */
	private function get_store_value( $pos_store, string $getter, $fallback ) {
		if ( ! \is_object( $pos_store ) || ! method_exists( $pos_store, $getter ) ) {
			return $fallback;
		}

		return $pos_store->{$getter}();
	}

	/**
	 * Resolve the currency code for the preview, preferring the store's
	 * configured currency over the global WooCommerce default. Sample-data
	 * previews should reflect what the chosen store will charge.
	 *
	 * @return string ISO 4217 currency code (e.g. "USD", "EUR").
	 */
	private function resolve_currency(): string {
		$fallback = get_option( 'woocommerce_currency', 'USD' );
		$store_currency = (string) $this->get_store_value( $this->pos_store, 'get_currency', '' );

		return '' !== $store_currency ? $store_currency : $fallback;
	}

	/**
	 * Resolve the locale for the preview's presentation hints, preferring
	 * the store's configured locale over the site locale so date/number
	 * formatting matches the store's region.
	 *
	 * @return string Locale identifier (e.g. "en_US", "de_DE").
	 */
	private function resolve_locale(): string {
		$fallback = get_locale();
		$store_locale = (string) $this->get_store_value( $this->pos_store, 'get_locale', '' );

		return '' !== $store_locale ? $store_locale : $fallback;
	}

	/**
	 * Build price, currency, locale, and tax presentation hints for renderers.
	 *
	 * @param string $currency            Currency code used by the preview data.
	 * @param bool   $tax_enabled         Whether taxes are enabled for this store.
	 * @param bool   $prices_include_tax  Whether entered prices include tax.
	 *
	 * @return array<string,mixed>
	 */
	private function build_presentation_hints( string $currency, bool $tax_enabled, bool $prices_include_tax ): array {
		$tax_display_mode = $this->resolve_store_string(
			'get_tax_total_display',
			get_option( 'woocommerce_tax_total_display', 'itemized' )
		);

		return array(
			'display_tax'              => $tax_enabled ? ( $tax_display_mode ? $tax_display_mode : 'itemized' ) : 'hidden',
			'prices_entered_with_tax'  => $prices_include_tax,
			'rounding_mode'            => $this->resolve_store_string(
				'get_tax_round_at_subtotal',
				get_option( 'woocommerce_tax_round_at_subtotal', 'no' )
			),
			'locale'                   => $this->resolve_locale(),
			'currency_position'        => $this->resolve_store_string(
				'get_currency_position',
				get_option( 'woocommerce_currency_pos', 'left' )
			),
			'currency_symbol'          => get_woocommerce_currency_symbol( $currency ),
			'price_thousand_separator' => $this->resolve_store_string(
				'get_price_thousand_separator',
				wc_get_price_thousand_separator()
			),
			'price_decimal_separator'  => $this->resolve_store_string(
				'get_price_decimal_separator',
				wc_get_price_decimal_separator()
			),
			'price_num_decimals'       => $this->resolve_price_num_decimals(),
			'price_display_suffix'     => $this->resolve_store_string(
				'get_price_display_suffix',
				get_option( 'woocommerce_price_display_suffix', '' )
			),
		);
	}

	/**
	 * Resolve a string setting from the store with a WooCommerce fallback.
	 *
	 * @param string $getter   Store getter method.
	 * @param mixed  $fallback Fallback value.
	 *
	 * @return string
	 */
	private function resolve_store_string( string $getter, $fallback ): string {
		$value = $this->get_store_value( $this->pos_store, $getter, null );

		return null !== $value ? (string) $value : (string) $fallback;
	}

	/**
	 * Resolve the number of price decimals from the store with WC fallback.
	 *
	 * @return int
	 */
	private function resolve_price_num_decimals(): int {
		$value = $this->get_store_value( $this->pos_store, 'get_price_number_of_decimals', wc_get_price_decimals() );

		return '' !== (string) $value ? (int) $value : wc_get_price_decimals();
	}

	/**
	 * Get cashier data from the current logged-in user.
	 *
	 * Falls back to a sample name if no user is logged in.
	 *
	 * @return array Cashier data with id and name.
	 */
	private function get_cashier(): array {
		$current_user = wp_get_current_user();

		if ( $current_user->exists() ) {
			return array(
				'id'   => $current_user->ID,
				'name' => $current_user->display_name,
			);
		}

		return array(
			'id'   => 0,
			'name' => 'Jane Smith',
		);
	}

	/**
	 * Build a locale-aware sample customer using the store's country settings.
	 *
	 * Reads woocommerce_default_country (format "CC" or "CC:SS") to select a
	 * matching entry from SAMPLE_CUSTOMERS, falling back to the US entry for
	 * unknown countries. City, state, postcode, and country are filled from
	 * the store's WooCommerce settings so the preview reflects real store data.
	 *
	 * @return array Customer data with id, name, billing_address, shipping_address, and tax_id.
	 */
	private function get_customer(): array {
		$raw     = get_option( 'woocommerce_default_country', 'US' );
		$parts   = explode( ':', $raw );
		$country = $parts[0] ?? 'US';
		$state   = $parts[1] ?? '';

		$sample = self::SAMPLE_CUSTOMERS[ $country ] ?? self::SAMPLE_CUSTOMERS['US'];

		$city     = get_option( 'woocommerce_store_city', '' );
		$postcode = get_option( 'woocommerce_store_postcode', '' );

		$billing = array(
			'first_name' => $sample['first_name'],
			'last_name'  => $sample['last_name'],
			'company'    => '',
			'address_1'  => $sample['address_1'],
			'address_2'  => $sample['address_2'],
			'city'       => $city,
			'state'      => $state,
			'postcode'   => $postcode,
			'country'    => $country,
			'email'      => $sample['email'],
			'phone'      => $sample['phone'],
		);

		$shipping = array(
			'first_name' => $sample['first_name'],
			'last_name'  => $sample['last_name'],
			'company'    => '',
			'address_1'  => $sample['address_1'],
			'address_2'  => $sample['address_2'],
			'city'       => $city,
			'state'      => $state,
			'postcode'   => $postcode,
			'country'    => $country,
		);

		$tax_ids = self::sample_tax_ids_for_country( $country );
		$primary = '';
		if ( ! empty( $tax_ids ) ) {
			$primary_country = isset( $tax_ids[0]['country'] ) ? (string) $tax_ids[0]['country'] : '';
			$primary_value   = (string) $tax_ids[0]['value'];
			$is_vat          = \in_array(
				$tax_ids[0]['type'],
				array( Tax_Id_Types::TYPE_EU_VAT, Tax_Id_Types::TYPE_GB_VAT ),
				true
			);
			$primary         = ( $is_vat && '' !== $primary_country && ! preg_match( '/^[A-Z]{2}/', $primary_value ) )
				? $primary_country . $primary_value
				: $primary_value;
		}

		return array(
			'id'               => 42,
			'name'             => $sample['first_name'] . ' ' . $sample['last_name'],
			'billing_address'  => $billing,
			'shipping_address' => $shipping,
			'tax_id'           => $primary,
			'tax_ids'          => $tax_ids,
		);
	}

	/**
	 * Sample tax ID list for the given country, used by the receipt preview.
	 *
	 * Values are realistic-shape but fictitious. Returns an empty array for
	 * countries without a representative sample.
	 *
	 * @param string $country ISO 3166-1 alpha-2 country code.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function sample_tax_ids_for_country( string $country ): array {
		$country = strtoupper( $country );

		$samples = array(
			'GB' => array(
				array(
					'type'    => Tax_Id_Types::TYPE_GB_VAT,
					'value'   => 'GB123456789',
					'country' => 'GB',
				),
			),
			'DE' => array(
				array(
					'type'    => Tax_Id_Types::TYPE_EU_VAT,
					'value'   => 'DE123456789',
					'country' => 'DE',
				),
			),
			'FR' => array(
				array(
					'type'    => Tax_Id_Types::TYPE_EU_VAT,
					'value'   => 'FR12345678901',
					'country' => 'FR',
				),
			),
			'NL' => array(
				array(
					'type'    => Tax_Id_Types::TYPE_EU_VAT,
					'value'   => 'NL123456789B01',
					'country' => 'NL',
				),
			),
			'ES' => array(
				array(
					'type'    => Tax_Id_Types::TYPE_ES_NIF,
					'value'   => 'B12345678',
					'country' => 'ES',
				),
			),
			'IT' => array(
				array(
					'type'    => Tax_Id_Types::TYPE_IT_PIVA,
					'value'   => '12345678901',
					'country' => 'IT',
				),
			),
			'AU' => array(
				array(
					'type'    => Tax_Id_Types::TYPE_AU_ABN,
					'value'   => '53004085616',
					'country' => 'AU',
				),
			),
			'CA' => array(
				array(
					'type'    => Tax_Id_Types::TYPE_CA_GST_HST,
					'value'   => '123456789RT0001',
					'country' => 'CA',
				),
			),
			'IN' => array(
				array(
					'type'    => Tax_Id_Types::TYPE_IN_GST,
					'value'   => '29ABCDE1234F1Z5',
					'country' => 'IN',
				),
			),
			'BR' => array(
				array(
					'type'    => Tax_Id_Types::TYPE_BR_CPF,
					'value'   => '12345678909',
					'country' => 'BR',
				),
			),
			'US' => array(
				array(
					'type'    => Tax_Id_Types::TYPE_US_EIN,
					'value'   => '12-3456789',
					'country' => 'US',
				),
			),
		);

		return $samples[ $country ] ?? array();
	}

	/**
	 * Get product data for line items.
	 *
	 * Queries up to 3 published simple/variable products from the catalog.
	 * Falls back to hardcoded product definitions if the catalog is empty.
	 * Pads the result to at least MIN_LINES items.
	 *
	 * @return array[] Array of product arrays with name, price, and sku keys.
	 */
	private function get_products(): array {
		$products = wc_get_products(
			array(
				'status' => 'publish',
				'type'   => array( 'simple', 'variable' ),
				'limit'  => 3,
				'return' => 'objects',
			)
		);

		$result = array();

		if ( ! empty( $products ) ) {
			foreach ( $products as $product ) {
				$meta = array();

				if ( $product->is_type( 'variable' ) ) {
					// Use the first visible variation's attributes (excludes hidden/disabled).
					$children = $product->get_visible_children();
					if ( ! empty( $children ) ) {
						$variation = wc_get_product( $children[0] );
						if ( $variation instanceof \WC_Product_Variation ) {
							$product = $variation;
							foreach ( $variation->get_variation_attributes() as $attr_key => $attr_value ) {
								if ( '' !== $attr_value ) {
									$taxonomy = str_replace( 'attribute_', '', $attr_key );
									$label    = wc_attribute_label( $taxonomy, $variation );
									$value    = $variation->get_attribute( $taxonomy );
									$meta[]   = array(
										'key'   => wp_strip_all_tags( $label ),
										'value' => wp_strip_all_tags( '' !== $value ? $value : $attr_value ),
									);
								}
							}
						}
					}
				}

				$price = (float) $product->get_price();
				if ( $price <= 0 ) {
					$price = 19.99;
				}

				$result[] = array(
					'name'  => $product->get_name(),
					'price' => $price,
					'sku'   => $product->get_sku() ? $product->get_sku() : '',
					'meta'  => $meta,
				);
			}
		}

		// Fall back to hardcoded products if catalog is empty.
		if ( empty( $result ) ) {
			$result = self::FALLBACK_PRODUCTS;
		}

		// Pad to at least MIN_LINES items.
		$fallback_index    = 0;
		$fallback_count    = count( self::FALLBACK_PRODUCTS );
		$result_count      = count( $result );
		while ( $result_count < self::MIN_LINES ) {
			$result[] = self::FALLBACK_PRODUCTS[ $fallback_index % $fallback_count ];
			++$fallback_index;
			++$result_count;
		}

		return array_slice( $result, 0, 3 );
	}

	/**
	 * Get tax configuration from WooCommerce tax rates.
	 *
	 * Uses WC_Tax::find_rates() with the store's base location to find
	 * the primary tax rate. Falls back to a default rate if no rates
	 * are configured.
	 *
	 * @param bool $tax_enabled Whether taxes are enabled for this store.
	 *
	 * @return array Tax config with rate (float), label (string), and code (string).
	 */
	private function get_tax_config( bool $tax_enabled ): array {
		if ( ! $tax_enabled ) {
			return array(
				'rate'  => 0.0,
				'label' => '',
				'code'  => '',
			);
		}

		$default = array(
			'rate'  => self::FALLBACK_TAX_RATE,
			'label' => __( 'Tax', 'woocommerce-pos' ),
			'code'  => '1',
		);

		$raw     = get_option( 'woocommerce_default_country', '' );
		$parts   = explode( ':', $raw );
		$country = $parts[0] ?? '';
		$state   = $parts[1] ?? '';

		$rates = \WC_Tax::find_rates(
			array(
				'country'  => $country,
				'state'    => $state,
				'postcode' => get_option( 'woocommerce_store_postcode', '' ),
				'city'     => get_option( 'woocommerce_store_city', '' ),
				'tax_class' => '',
			)
		);

		if ( ! empty( $rates ) ) {
			$first_rate = reset( $rates );
			$rate_id    = key( $rates );

			return array(
				'rate'  => (float) $first_rate['rate'],
				'label' => $first_rate['label'] ? $first_rate['label'] : __( 'Tax', 'woocommerce-pos' ),
				'code'  => (string) $rate_id,
			);
		}

		return $default;
	}

	/**
	 * Ensure store tax IDs include display labels for logicless templates.
	 *
	 * @param array<int,array<string,mixed>> $tax_ids Store tax IDs.
	 * @return array<int,array<string,mixed>>
	 */
	private static function with_store_tax_id_labels( array $tax_ids ): array {
		$labels = Receipt_I18n_Labels::get_labels();

		return array_map(
			static function ( array $tax_id ) use ( $labels ): array {
				if ( ! empty( $tax_id['label'] ) ) {
					return $tax_id;
				}

				$type            = isset( $tax_id['type'] ) ? (string) $tax_id['type'] : 'other';
				$key             = 'store_tax_id_label_' . $type;
				$tax_id['label'] = $labels[ $key ] ?? $labels['store_tax_id_label_other'];

				return $tax_id;
			},
			$tax_ids
		);
	}
}
