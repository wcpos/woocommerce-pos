<?php

declare (strict_types=1);
namespace WCPOS\Vendor\Sentry\Metrics\Types;

use WCPOS\Vendor\Sentry\Attributes\AttributeBag;
use WCPOS\Vendor\Sentry\Tracing\SpanId;
use WCPOS\Vendor\Sentry\Tracing\TraceId;
use WCPOS\Vendor\Sentry\Unit;
abstract class Metric
{
    /**
     * @var string
     */
    private $name;
    /**
     * @var TraceId
     */
    private $traceId;
    /**
     * @var SpanId
     */
    private $spanId;
    /**
     * @var float
     */
    private $timestamp;
    /**
     * @var AttributeBag
     */
    private $attributes;
    /**
     * @var Unit|null
     */
    private $unit;
    /**
     * @param array<string, int|float|string|bool|null> $attributes
     */
    public function __construct(string $name, TraceId $traceId, SpanId $spanId, float $timestamp, array $attributes, ?Unit $unit)
    {
        $this->name = $name;
        $this->unit = $unit;
        $this->traceId = $traceId;
        $this->spanId = $spanId;
        $this->timestamp = $timestamp;
        $this->attributes = new AttributeBag();
        foreach ($attributes as $key => $value) {
            $this->attributes->set($key, $value);
        }
    }
    /**
     * @param int|float $value
     */
    public abstract function setValue($value) : void;
    public abstract function getType() : string;
    /**
     * @return int|float
     */
    public abstract function getValue();
    public function getName() : string
    {
        return $this->name;
    }
    public function getUnit() : ?Unit
    {
        return $this->unit;
    }
    public function getTraceId() : TraceId
    {
        return $this->traceId;
    }
    public function getSpanId() : SpanId
    {
        return $this->spanId;
    }
    public function getAttributes() : AttributeBag
    {
        return $this->attributes;
    }
    public function getTimestamp() : float
    {
        return $this->timestamp;
    }
}
