<?php

namespace WCPOS\Vendor\Picqer\Barcode\Types;

use WCPOS\Vendor\Picqer\Barcode\Barcode;
interface TypeInterface
{
    public function getBarcodeData(string $code) : Barcode;
}
