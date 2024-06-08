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
namespace WCPOS\Vendor\Phpfastcache\Drivers\Couchbasev3;

use Couchbase\BaseException as CouchbaseException;
use Couchbase\Cluster;
use Couchbase\ClusterOptions;
use Couchbase\Collection;
use Couchbase\DocumentNotFoundException;
use Couchbase\Scope;
use Couchbase\UpsertOptions;
use WCPOS\Vendor\Phpfastcache\Config\ConfigurationOption;
use WCPOS\Vendor\Phpfastcache\Drivers\Couchbase\Driver as CoubaseV2Driver;
use WCPOS\Vendor\Phpfastcache\Drivers\Couchbase\Item;
use WCPOS\Vendor\Phpfastcache\Entities\DriverStatistic;
use WCPOS\Vendor\Phpfastcache\Exceptions\PhpfastcacheDriverCheckException;
use WCPOS\Vendor\Phpfastcache\Exceptions\PhpfastcacheInvalidArgumentException;
use WCPOS\Vendor\Phpfastcache\Exceptions\PhpfastcacheLogicException;
use WCPOS\Vendor\Psr\Cache\CacheItemInterface;
/**
 * Class Driver
 * @package phpFastCache\Drivers
 * @property Cluster $instance Instance of driver service
 * @property Config $config Config object
 * @method Config getConfig() Return the config object
 */
class Driver extends CoubaseV2Driver
{
    /**
     * @var Scope
     */
    protected $scope;
    /**
     * @var Collection
     */
    protected $collection;
    public function __construct(ConfigurationOption $config, $instanceId)
    {
        $this->__baseConstruct($config, $instanceId);
    }
    /**
     * @return bool
     * @throws PhpfastcacheLogicException
     */
    protected function driverConnect() : bool
    {
        if (!\class_exists(ClusterOptions::class)) {
            throw new PhpfastcacheDriverCheckException('You are using the Couchbase PHP SDK 2.x so please use driver Couchbasev3');
        }
        $connectionString = "couchbase://{$this->getConfig()->getHost()}:{$this->getConfig()->getPort()}";
        $options = new ClusterOptions();
        $options->credentials($this->getConfig()->getUsername(), $this->getConfig()->getPassword());
        $this->instance = new Cluster($connectionString, $options);
        $this->setBucket($this->instance->bucket($this->getConfig()->getBucketName()));
        $this->setScope($this->getBucket()->scope($this->getConfig()->getScopeName()));
        $this->setCollection($this->getScope()->collection($this->getConfig()->getCollectionName()));
        return \true;
    }
    /**
     * @param CacheItemInterface $item
     * @return null|array
     */
    protected function driverRead(CacheItemInterface $item)
    {
        try {
            /**
             * CouchbaseBucket::get() returns a GetResult interface
             */
            return $this->decodeDocument((array) $this->getCollection()->get($item->getEncodedKey())->content());
        } catch (DocumentNotFoundException $e) {
            return null;
        }
    }
    /**
     * @param CacheItemInterface $item
     * @return bool
     * @throws PhpfastcacheInvalidArgumentException
     */
    protected function driverWrite(CacheItemInterface $item) : bool
    {
        /**
         * Check for Cross-Driver type confusion
         */
        if ($item instanceof Item) {
            try {
                $this->getCollection()->upsert($item->getEncodedKey(), $this->encodeDocument($this->driverPreWrap($item)), (new UpsertOptions())->expiry($item->getTtl()));
                return \true;
            } catch (CouchbaseException $e) {
                return \false;
            }
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
            try {
                $this->getCollection()->remove($item->getEncodedKey());
                return \true;
            } catch (DocumentNotFoundException $e) {
                return \true;
            } catch (CouchbaseException $e) {
                return \false;
            }
        }
        throw new PhpfastcacheInvalidArgumentException('Cross-Driver type confusion detected');
    }
    /**
     * @return bool
     */
    protected function driverClear() : bool
    {
        $this->instance->buckets()->flush($this->getConfig()->getBucketName());
        return \true;
    }
    /**
     * @return DriverStatistic
     */
    public function getStats() : DriverStatistic
    {
        /**
         * Between SDK 2 and 3 we lost a lot of useful information :(
         * @see https://docs.couchbase.com/java-sdk/current/project-docs/migrating-sdk-code-to-3.n.html#management-apis
         */
        $info = $this->getBucket()->diagnostics(\bin2hex(\random_bytes(16)));
        return (new DriverStatistic())->setSize(0)->setRawData($info)->setData(\implode(', ', \array_keys($this->itemInstances)))->setInfo($info['sdk'] . "\n For more information see RawData.");
    }
    /**
     * @return Collection
     */
    public function getCollection() : Collection
    {
        return $this->collection;
    }
    /**
     * @param Collection $collection
     * @return Driver
     */
    public function setCollection(Collection $collection) : Driver
    {
        $this->collection = $collection;
        return $this;
    }
    /**
     * @return Scope
     */
    public function getScope() : Scope
    {
        return $this->scope;
    }
    /**
     * @param Scope $scope
     * @return Driver
     */
    public function setScope(Scope $scope) : Driver
    {
        $this->scope = $scope;
        return $this;
    }
}
