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
namespace WCPOS\Vendor\Phpfastcache\Drivers\Wincache;

use DateTime;
use WCPOS\Vendor\Phpfastcache\Cluster\AggregatablePoolInterface;
use WCPOS\Vendor\Phpfastcache\Core\Pool\DriverBaseTrait;
use WCPOS\Vendor\Phpfastcache\Core\Pool\ExtendedCacheItemPoolInterface;
use WCPOS\Vendor\Phpfastcache\Entities\DriverStatistic;
use WCPOS\Vendor\Phpfastcache\Exceptions\PhpfastcacheInvalidArgumentException;
use WCPOS\Vendor\Psr\Cache\CacheItemInterface;
/**
 * Class Driver
 * @package phpFastCache\Drivers
 * @property Config $config Config object
 * @method Config getConfig() Return the config object
 */
class Driver implements ExtendedCacheItemPoolInterface, AggregatablePoolInterface
{
    use DriverBaseTrait;
    /**
     * @return bool
     */
    public function driverCheck() : bool
    {
        return \extension_loaded('wincache') && \function_exists('wincache_ucache_set');
    }
    /**
     * @return DriverStatistic
     */
    public function getStats() : DriverStatistic
    {
        $memInfo = \wincache_ucache_meminfo();
        $info = \wincache_ucache_info();
        $date = (new DateTime())->setTimestamp(\time() - $info['total_cache_uptime']);
        return (new DriverStatistic())->setInfo(\sprintf("The Wincache daemon is up since %s.\n For more information see RawData.", $date->format(\DATE_RFC2822)))->setSize($memInfo['memory_free'] - $memInfo['memory_total'])->setData(\implode(', ', \array_keys($this->itemInstances)))->setRawData($memInfo);
    }
    /**
     * @return bool
     */
    protected function driverConnect() : bool
    {
        return \true;
    }
    /**
     * @param CacheItemInterface $item
     * @return null|array
     */
    protected function driverRead(CacheItemInterface $item)
    {
        $val = \wincache_ucache_get($item->getKey(), $suc);
        if ($suc === \false) {
            return null;
        }
        return $val;
    }
    /**
     * @param CacheItemInterface $item
     * @return mixed
     * @throws PhpfastcacheInvalidArgumentException
     */
    protected function driverWrite(CacheItemInterface $item) : bool
    {
        /**
         * Check for Cross-Driver type confusion
         */
        if ($item instanceof Item) {
            return \wincache_ucache_set($item->getKey(), $this->driverPreWrap($item), $item->getTtl());
        }
        throw new PhpfastcacheInvalidArgumentException('Cross-Driver type confusion detected');
    }
    /**
     * @param CacheItemInterface $item
     * @return bool
     * @throws PhpfastcacheInvalidArgumentException
     */
    protected function driverDelete(CacheItemInterface $item) : bool
    {
        /**
         * Check for Cross-Driver type confusion
         */
        if ($item instanceof Item) {
            return \wincache_ucache_delete($item->getKey());
        }
        throw new PhpfastcacheInvalidArgumentException('Cross-Driver type confusion detected');
    }
    /********************
     *
     * PSR-6 Extended Methods
     *
     *******************/
    /**
     * @return bool
     */
    protected function driverClear() : bool
    {
        return \wincache_ucache_clear();
    }
}
