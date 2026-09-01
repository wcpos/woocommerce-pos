<?php

declare (strict_types=1);
namespace WCPOS\Vendor\Sentry\Metrics\Types;

use WCPOS\Vendor\Sentry\Tracing\SpanId;
use WCPOS\Vendor\Sentry\Tracing\TraceId;
use WCPOS\Vendor\Sentry\Unit;
/**
 * @internal
 */
final class GaugeMetric extends Metric
{
    /**
     * @var string
     */
    public const TYPE = 'gauge';
    /**
     * @var int|float
     */
    private $value;
    /**
     * @param int|float                                 $value
     * @param array<string, int|float|string|bool|null> $attributes
     */
    public function __construct(string $name, $value, TraceId $traceId, SpanId $spanId, array $attributes, float $timestamp, ?Unit $unit)
    {
        parent::__construct($name, $traceId, $spanId, $timestamp, $attributes, $unit);
        $this->value = $value;
    }
    /**
     * @param int|float $value
     */
    public function setValue($value) : void
    {
        $this->value = $value;
    }
    /**
     * @return int|float
     */
    public function getValue()
    {
        return $this->value;
    }
    public function getType() : string
    {
        return self::TYPE;
    }
}
