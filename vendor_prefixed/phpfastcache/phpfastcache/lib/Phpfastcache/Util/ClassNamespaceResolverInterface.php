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
namespace WCPOS\Vendor\Phpfastcache\Util;

/**
 * Interface ClassNamespaceResolverInterface
 * @package Phpfastcache\Util
 */
interface ClassNamespaceResolverInterface
{
    /**
     * @return string
     */
    public function getClassNamespace() : string;
    /**
     * @return string
     */
    public function getClassName() : string;
}
