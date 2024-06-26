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
namespace WCPOS\Vendor\Phpfastcache\Drivers\Devtrue;

use DateTime;
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
class Driver implements ExtendedCacheItemPoolInterface
{
    use DriverBaseTrait;
    /**
     * @return bool
     */
    public function driverCheck() : bool
    {
        return \true;
    }
    /**
     * @return DriverStatistic
     */
    public function getStats() : DriverStatistic
    {
        $stat = new DriverStatistic();
        $stat->setInfo('[Devtrue] A void info string')->setSize(0)->setData(\implode(', ', \array_keys($this->itemInstances)))->setRawData(\true);
        return $stat;
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
            return \false;
        }
        throw new PhpfastcacheInvalidArgumentException('Cross-Driver type confusion detected');
    }
    /**
     * @param CacheItemInterface $item
     * @return array
     */
    protected function driverRead(CacheItemInterface $item) : array
    {
        return [self::DRIVER_DATA_WRAPPER_INDEX => \true, self::DRIVER_TAGS_WRAPPER_INDEX => [], self::DRIVER_EDATE_WRAPPER_INDEX => new DateTime()];
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
            return \false;
        }
        throw new PhpfastcacheInvalidArgumentException('Cross-Driver type confusion detected');
    }
    /**
     * @return bool
     */
    protected function driverClear() : bool
    {
        return \false;
    }
    /********************
     *
     * PSR-6 Extended Methods
     *
     *******************/
    /**
     * @return bool
     */
    protected function driverConnect() : bool
    {
        return \false;
    }
}
