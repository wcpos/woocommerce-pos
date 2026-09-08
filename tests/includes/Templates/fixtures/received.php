<?php
/**
 * Render the production received template, then return control to PHPUnit.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates
 */

namespace WCPOS\WooCommercePOS\Tests\Templates;

require \WCPOS\WooCommercePOS\PLUGIN_PATH . 'templates/received.php';

throw new \Exception( 'Received template rendered.' );
