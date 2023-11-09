<?php

namespace WCPOS\WooCommercePOS\Tests\Helpers;

class Utils {
	/**
	 * @param mixed $min
	 * @param mixed $max
	 * @param mixed $decimals
	 */
	public static function generateRandomDecimalAsString($min = 1, $max = 100, $decimals = 2): string {
		$factor = pow(10, $decimals);
		// Generate a random number within the range multiplied by the factor
		$randomNumber = mt_rand($min * $factor, $max * $factor);

		// Divide back down to get the correct number of decimals and format
		return number_format($randomNumber / $factor, $decimals, '.', '');
	}
}
