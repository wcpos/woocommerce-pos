<?php

/**
 * Class Mode
 *
 * @created      19.11.2020
 * @author       smiley <smiley@chillerlan.net>
 * @copyright    2020 smiley
 * @license      MIT
 */
namespace WCPOS\Vendor\chillerlan\QRCode\Common;

use WCPOS\Vendor\chillerlan\QRCode\Data\AlphaNum;
use WCPOS\Vendor\chillerlan\QRCode\Data\Byte;
use WCPOS\Vendor\chillerlan\QRCode\Data\Hanzi;
use WCPOS\Vendor\chillerlan\QRCode\Data\Kanji;
use WCPOS\Vendor\chillerlan\QRCode\Data\Number;
use WCPOS\Vendor\chillerlan\QRCode\QRCodeException;
/**
 * Data mode information - ISO 18004:2006, 6.4.1, Tables 2 and 3
 */
final class Mode
{
    // ISO/IEC 18004:2000 Table 2
    /** @var int */
    public const TERMINATOR = 0b0;
    /** @var int */
    public const NUMBER = 0b1;
    /** @var int */
    public const ALPHANUM = 0b10;
    /** @var int */
    public const BYTE = 0b100;
    /** @var int */
    public const KANJI = 0b1000;
    /** @var int */
    public const HANZI = 0b1101;
    /** @var int */
    public const STRCTURED_APPEND = 0b11;
    /** @var int */
    public const FNC1_FIRST = 0b101;
    /** @var int */
    public const FNC1_SECOND = 0b1001;
    /** @var int */
    public const ECI = 0b111;
    /**
     * mode length bits for the version breakpoints 1-9, 10-26 and 27-40
     *
     * ISO/IEC 18004:2000 Table 3 - Number of bits in Character Count Indicator
     */
    public const LENGTH_BITS = [self::NUMBER => [10, 12, 14], self::ALPHANUM => [9, 11, 13], self::BYTE => [8, 16, 16], self::KANJI => [8, 10, 12], self::HANZI => [8, 10, 12], self::ECI => [0, 0, 0]];
    /**
     * Map of data mode => interface (detection order)
     *
     * @var string[]
     */
    public const INTERFACES = [self::NUMBER => Number::class, self::ALPHANUM => AlphaNum::class, self::KANJI => Kanji::class, self::HANZI => Hanzi::class, self::BYTE => Byte::class];
    /**
     * returns the length bits for the version breakpoints 1-9, 10-26 and 27-40
     *
     * @throws \chillerlan\QRCode\QRCodeException
     */
    public static function getLengthBitsForVersion(int $mode, int $version) : int
    {
        if (!isset(self::LENGTH_BITS[$mode])) {
            throw new QRCodeException('invalid mode given');
        }
        $minVersion = 0;
        foreach ([9, 26, 40] as $key => $breakpoint) {
            if ($version > $minVersion && $version <= $breakpoint) {
                return self::LENGTH_BITS[$mode][$key];
            }
            $minVersion = $breakpoint;
        }
        throw new QRCodeException(\sprintf('invalid version number: %d', $version));
    }
}
