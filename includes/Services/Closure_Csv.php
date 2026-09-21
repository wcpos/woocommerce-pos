<?php
/**
 * CSV projection of recorded closure figures.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

/** Two passes retain only a page of documents and the union of column keys. */
final class Closure_Csv {
	/** One million rows covers 100 registers closing daily for over 27 years; stop pathological paging. */
	private const MAX_EXPORT_PAGES = 10000;

	/** Fixed columns preceding the variable tender and tax groups. */
	private const LEADING_COLUMNS = array(
		'closure_number' => array( 'number' ),
		'register_id' => array( 'register_id' ),
		'register_name' => array( 'breakdowns', 'labels', 'register_name' ),
		'store_id' => array( 'store_id' ),
		'store_name' => array( 'breakdowns', 'store', 'name' ),
		'business_day' => array( 'business_day' ),
		'opened_at_gmt' => array( 'opened_at_gmt' ),
		'closed_at_gmt' => array( 'closed_at_gmt' ),
		'closed_by' => array( 'closed_by' ),
		'closed_by_name' => array( 'breakdowns', 'labels', 'closed_by_name' ),
		'currency' => array( 'breakdowns', 'currency' ),
		'timezone' => array( 'breakdowns', 'timezone' ),
		'float_expected' => array( 'breakdowns', 'opening_float', 'expected' ),
		'float_counted' => array( 'breakdowns', 'opening_float', 'counted' ),
		'float_variance' => array( 'breakdowns', 'opening_float', 'variance' ),
	);

	/** Build a UTF-8 download; presentation always comes from the frozen row.
	 *
	 * @param Closure_Store $store Closure store.
	 * @param array         $scope Authorization scope from woocommerce_pos_closures_list_args.
	 * @throws \RuntimeException On read, stream or page-limit failure.
	 */
	public function build( Closure_Store $store, array $scope = array() ): string {
		$keys = array(
			'tenders' => array(),
			'payment_methods' => array(),
			'tax_rates' => array(),
		);
		foreach ( $this->rows( $store, $scope ) as $row ) {
			foreach ( array( 'counted', 'expected', 'variance' ) as $field ) {
				foreach ( array_keys( $row[ $field ] ?? array() ) as $key ) {
					$keys['tenders'][ $key ] = (string) $key;
				}
			}
			foreach ( array( 'payment_methods', 'tax_rates' ) as $section ) {
				foreach ( $row['breakdowns'][ $section ] as $key => $value ) {
					$keys[ $section ][ $key ] = $value['_csv_column'];
				}
			}
		}
		foreach ( $keys as &$group ) {
			uksort(
				$group,
				static function ( $left, $right ) use ( $group ): int {
						$by_label = strcmp( (string) $group[ $left ], (string) $group[ $right ] );
					return 0 !== $by_label ? $by_label : strcmp( (string) $left, (string) $right );
				}
			);
			$used = array();
			foreach ( $group as $key => &$suffix ) {
				$base = trim( preg_replace( '/[^a-z0-9]+/', '_', strtolower( (string) $suffix ) ), '_' );
				if ( '' === $base ) {
					$base = 'unnamed';
				}
				$suffix = $base;
				$counter = 2;
				while ( isset( $used[ $suffix ] ) ) {
					$suffix = $base . '_' . $counter;
					++$counter;
				}
				$used[ $suffix ] = true;
			}
			unset( $suffix );
		}
		unset( $group );
		$columns = self::LEADING_COLUMNS;
		foreach ( array( 'counted', 'expected', 'variance' ) as $field ) {
			foreach ( $keys['tenders'] as $key => $suffix ) {
				$columns[ $field . '_' . $suffix ] = array( $field, $key );
			}
		}
		foreach ( array(
			'payment_methods' => array( 'sales', 'refunds' ),
			'tax_rates' => array( 'net', 'tax', 'gross' ),
		) as $section => $fields ) {
			foreach ( $keys[ $section ] as $key => $suffix ) {
				foreach ( $fields as $field ) {
					$prefix = 'payment_methods' === $section ? 'tender_' : 'tax_';
					$columns[ $prefix . $suffix . '_' . $field ] = array( 'breakdowns', $section, $key, $field );
				}
			}
		}
		foreach ( array( 'period_sales_total', 'period_refunds_total', 'perpetual_sales_total', 'perpetual_refunds_total', 'first_sale_counter', 'last_sale_counter', 'unsynced_count', 'unsynced_total', 'corrections_count' ) as $field ) {
			$columns[ $field ] = array( $field );
		}

		$stream = fopen( 'php://memory', 'w+' );
		if ( false === $stream ) {
			throw new \RuntimeException( 'Could not open closure CSV stream.' );
		}
		try {
			// Excel on Windows needs the UTF-8 BOM for accented store and cashier names.
			if ( 3 !== fwrite( $stream, "\xEF\xBB\xBF" ) ) {
				throw new \RuntimeException( 'Could not write closure CSV BOM.' );
			}
			$this->write( $stream, array_keys( $columns ) );
			foreach ( $this->rows( $store, $scope, true ) as $row ) {
				$cells = array();
				foreach ( $columns as $column => $path ) {
					$value = $row;
					foreach ( $path as $part ) {
						$value = is_array( $value ) ? ( $value[ $part ] ?? null ) : null;
					}
					$value = is_scalar( $value ) ? (string) $value : '';
					$text = in_array( $column, array( 'register_id', 'register_name', 'store_name', 'business_day', 'opened_at_gmt', 'closed_at_gmt', 'closed_by_name', 'currency', 'timezone' ), true );
					// Text is never a spreadsheet formula; signed decimal amounts stay raw.
					if ( preg_match( '/^[\x00-\x20]*[=+@-]/', $value ) && ( $text || ! preg_match( '/^-?\d+(?:\.\d+)?$/D', $value ) ) ) {
						$value = "'" . $value;
					}
					$cells[] = $value;
				}
				$this->write( $stream, $cells );
			}
			if ( ! rewind( $stream ) ) {
				throw new \RuntimeException( 'Could not rewind closure CSV stream.' );
			}
			$csv = stream_get_contents( $stream );
			if ( false === $csv ) {
				throw new \RuntimeException( 'Could not read closure CSV stream.' );
			}
			return $csv;
		} finally {
			fclose( $stream );
		}
	}

	/** Page through the existing list query in register/number order.
	 *
	 * @param Closure_Store $store Closure store.
	 * @param array         $scope Authorization scope; paging and ordering always win over it.
	 * @param bool          $counts Include grouped correction counts on the writing pass.
	 * @throws \RuntimeException When the page limit would truncate the file.
	 */
	private function rows( Closure_Store $store, array $scope = array(), bool $counts = false ): \Generator {
		for ( $page = 1; $page <= self::MAX_EXPORT_PAGES; ++$page ) {
			$rows = $store->list(
				array_merge(
					$scope,
					array(
						'page' => $page,
						'per_page' => Closure_Store::MAX_PER_PAGE,
						'number_order' => 'register_asc',
					)
				)
			);
			if ( $counts ) {
				$rows = $store->with_correction_counts( $rows );
			}
			foreach ( $rows as $row ) {
				// Stored breakdowns support both keyed maps and the receipt contract's lists.
				foreach ( array(
					'payment_methods' => 'method',
					'tax_rates' => 'name',
				) as $section => $identity ) {
					$values = $row['breakdowns'][ $section ] ?? array();
					$values = is_array( $values ) ? $values : array();
					$is_list = array_keys( $values ) === range( 0, count( $values ) - 1 );
					$named = array();
					$occurrences = array();
					foreach ( $values as $key => $value ) {
						if ( is_array( $value ) ) {
							$source = (string) ( $is_list ? ( $value[ $identity ] ?? $key ) : $key );
							$label = 'tax_rates' === $section ? ( $value['name'] ?? $source ) : $source;
							$occurrence = $occurrences[ $source ] ?? 0;
							$occurrences[ $source ] = $occurrence + 1;
							// Keep distinct stored rates (and repeated list names), even when their display names agree.
							$value['_csv_column'] = $label;
							$named[ wp_json_encode( array( $label, $source, $occurrence ) ) ] = $value;
						}
					}
					$row['breakdowns'][ $section ] = $named;
				}
				yield $row;
			}
			if ( count( $rows ) < Closure_Store::MAX_PER_PAGE ) {
				return;
			}
		}
		throw new \RuntimeException( 'Closure export page limit reached.' );
	}

	/** Let PHP quote commas, quotes and newlines, without proprietary backslash escapes.
	 *
	 * @param resource $stream Output stream.
	 * @param array    $cells CSV row.
	 * @throws \RuntimeException On write failure.
	 */
	private function write( $stream, array $cells ): void {
		if ( false === fputcsv( $stream, $cells, ',', '"', '' ) ) {
			throw new \RuntimeException( 'Could not write closure CSV row.' );
		}
	}
}
