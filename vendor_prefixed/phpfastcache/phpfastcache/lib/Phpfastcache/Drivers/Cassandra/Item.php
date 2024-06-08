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
namespace WCPOS\Vendor\Phpfastcache\Drivers\Cassandra;

use WCPOS\Vendor\Phpfastcache\Core\Item\ExtendedCacheItemInterface;
use WCPOS\Vendor\Phpfastcache\Core\Item\ItemBaseTrait;
use WCPOS\Vendor\Phpfastcache\Core\Pool\ExtendedCacheItemPoolInterface;
use WCPOS\Vendor\Phpfastcache\Drivers\Cassandra\Driver as CassandraDriver;
use WCPOS\Vendor\Phpfastcache\Exceptions\PhpfastcacheInvalidArgumentException;
/**
 * Class Item
 * @package phpFastCache\Drivers\Cassandra
 */
class Item implements ExtendedCacheItemInterface
{
    use ItemBaseTrait {
        ItemBaseTrait::__construct as __BaseConstruct;
    }
    /**
     * Item constructor.
     * @param Driver $driver
     * @param $key
     * @throws PhpfastcacheInvalidArgumentException
     */
    public function __construct(CassandraDriver $driver, $key)
    {
        $this->__BaseConstruct($driver, $key);
    }
    /**
     * @param ExtendedCacheItemPoolInterface $driver
     * @return static
     * @throws PhpfastcacheInvalidArgumentException
     */
    public function setDriver(ExtendedCacheItemPoolInterface $driver)
    {
        if ($driver instanceof CassandraDriver) {
            $this->driver = $driver;
            return $this;
        }
        throw new PhpfastcacheInvalidArgumentException('Invalid driver instance');
    }
}
