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
namespace WCPOS\Vendor\Phpfastcache\Exceptions;

use WCPOS\Vendor\Psr\Cache\CacheException;
use Throwable;
/**
 * Interface PhpfastcacheExceptionInterface
 * @package Phpfastcache\Exceptions
 */
interface PhpfastcacheExceptionInterface extends CacheException, Throwable
{
}
