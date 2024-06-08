<?php

/**
 *
 * This file is part of phpFastCache.
 *
 * @license MIT License (MIT)
 *
 * For full copyright and license information, please see the docs/CREDITS.txt file.
 *
 * @author Khoa Bui (khoaofgod)  <khoaofgod@gmail.com> https://www.phpfastcache.com
 * @author Georges.L (Geolim4)  <contact@geolim4.com>
 *
 */
declare (strict_types=1);
namespace WCPOS\Vendor\Phpfastcache\Core\Pool;

use WCPOS\Vendor\Psr\Cache\CacheItemInterface;
/**
 * Trait AbstractCacheItemPoolTrait
 * @package Phpfastcache\Core\Pool
 */
trait AbstractDriverPoolTrait
{
    /**
     * @return bool
     */
    protected abstract function driverCheck() : bool;
    /**
     * @return bool
     */
    protected abstract function driverConnect() : bool;
    /**
     * @param CacheItemInterface $item
     * @return null|array [
     *      'd' => 'THE ITEM DATA'
     *      't' => 'THE ITEM DATE EXPIRATION'
     *      'g' => 'THE ITEM TAGS'
     * ]
     *
     */
    protected abstract function driverRead(CacheItemInterface $item);
    /**
     * @param CacheItemInterface $item
     * @return bool
     */
    protected abstract function driverWrite(CacheItemInterface $item) : bool;
    /**
     * @param CacheItemInterface $item
     * @return bool
     */
    protected abstract function driverDelete(CacheItemInterface $item) : bool;
    /**
     * @return bool
     */
    protected abstract function driverClear() : bool;
}
