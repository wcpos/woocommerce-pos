<?php

declare (strict_types=1);
namespace WCPOS\Vendor\Sentry\Metrics;

use WCPOS\Vendor\Sentry\EventId;
use WCPOS\Vendor\Sentry\Metrics\Types\CounterMetric;
use WCPOS\Vendor\Sentry\Metrics\Types\DistributionMetric;
use WCPOS\Vendor\Sentry\Metrics\Types\GaugeMetric;
use WCPOS\Vendor\Sentry\SentrySdk;
use WCPOS\Vendor\Sentry\Unit;
class TraceMetrics
{
    /**
     * @var self|null
     */
    private static $instance;
    public function __construct()
    {
    }
    public static function getInstance() : self
    {
        if (self::$instance === null) {
            self::$instance = new TraceMetrics();
        }
        return self::$instance;
    }
    /**
     * @param int|float                                 $value
     * @param array<string, int|float|string|bool|null> $attributes
     */
    public function count(string $name, $value, array $attributes = [], ?Unit $unit = null) : void
    {
        $this->aggregator()->add(CounterMetric::TYPE, $name, $value, $attributes, $unit);
    }
    /**
     * @param int|float                                 $value
     * @param array<string, int|float|string|bool|null> $attributes
     */
    public function distribution(string $name, $value, array $attributes = [], ?Unit $unit = null) : void
    {
        $this->aggregator()->add(DistributionMetric::TYPE, $name, $value, $attributes, $unit);
    }
    /**
     * @param int|float                                 $value
     * @param array<string, int|float|string|bool|null> $attributes
     */
    public function gauge(string $name, $value, array $attributes = [], ?Unit $unit = null) : void
    {
        $this->aggregator()->add(GaugeMetric::TYPE, $name, $value, $attributes, $unit);
    }
    public function flush() : ?EventId
    {
        return $this->aggregator()->flush();
    }
    private function aggregator() : MetricsAggregator
    {
        return SentrySdk::getCurrentRuntimeContext()->getMetricsAggregator();
    }
}
