<?php

declare (strict_types=1);
namespace WCPOS\Vendor\Sentry\Monolog;

use WCPOS\Vendor\Monolog\Formatter\FormatterInterface;
use WCPOS\Vendor\Monolog\Formatter\LineFormatter;
use WCPOS\Vendor\Monolog\Handler\HandlerInterface;
use WCPOS\Vendor\Monolog\LogRecord;
use WCPOS\Vendor\Sentry\Logs\LogLevel;
use WCPOS\Vendor\Sentry\Logs\Logs;
class LogsHandler implements HandlerInterface
{
    use CompatibilityLogLevelTrait;
    /**
     * The minimum logging level at which this handler will be triggered.
     *
     * @var LogLevel|\Monolog\Level|int
     */
    private $logLevel;
    /**
     * Whether the messages that are handled can bubble up the stack or not.
     *
     * @var bool
     */
    private $bubble;
    /**
     * Creates a new Monolog handler that converts Monolog logs to Sentry logs.
     *
     * @param LogLevel|\Monolog\Level|int|null $logLevel the minimum logging level at which this handler will be triggered and collects the logs
     * @param bool                             $bubble   whether the messages that are handled can bubble up the stack or not
     */
    public function __construct($logLevel = null, bool $bubble = \true)
    {
        $this->logLevel = $logLevel ?? LogLevel::debug();
        $this->bubble = $bubble;
    }
    /**
     * @param array<string, mixed>|LogRecord $record
     */
    public function isHandling($record) : bool
    {
        if ($this->logLevel instanceof LogLevel) {
            return self::getSentryLogLevelFromMonologLevel($record['level'])->getPriority() >= $this->logLevel->getPriority();
        } elseif ($this->logLevel instanceof \WCPOS\Vendor\Monolog\Level) {
            return $record['level'] >= $this->logLevel->value;
        }
        return $record['level'] >= $this->logLevel;
    }
    /**
     * @param array<string, mixed>|LogRecord $record
     */
    public function handle($record) : bool
    {
        if (!$this->isHandling($record)) {
            return \false;
        }
        // Do not collect logs for exceptions, they should be handled separately by `ExceptionToSentryIssueHandler` or `captureException`
        if (isset($record['context']['exception']) && $record['context']['exception'] instanceof \Throwable) {
            return \false;
        }
        Logs::getInstance()->aggregator()->add(self::getSentryLogLevelFromMonologLevel($record['level']), $record['message'], [], $this->compileAttributes($record));
        return $this->bubble === \false;
    }
    /**
     * @param array<array<string, mixed>|LogRecord> $records
     */
    public function handleBatch(array $records) : void
    {
        foreach ($records as $record) {
            $this->handle($record);
        }
    }
    public function close() : void
    {
        Logs::getInstance()->flush();
    }
    /**
     * @param callable $callback
     */
    public function pushProcessor($callback) : void
    {
        // noop, this handler does not support processors
    }
    /**
     * @return callable
     */
    public function popProcessor()
    {
        // Since we do not support processors, we throw an exception if this method is called
        throw new \LogicException('You tried to pop from an empty processor stack.');
    }
    public function setFormatter(FormatterInterface $formatter) : void
    {
        // noop, this handler does not support formatters
    }
    public function getFormatter() : FormatterInterface
    {
        // To adhere to the interface we need to return a formatter so we return a default one
        return new LineFormatter();
    }
    public function __destruct()
    {
        try {
            $this->close();
        } catch (\Throwable $e) {
            // Just in case so that the destructor can never fail.
        }
    }
    /**
     * @param array<string,mixed>|LogRecord $record
     *
     * @return array<string,mixed>
     */
    protected function compileAttributes($record) : array
    {
        return \array_merge($record['context'], $record['extra'], ['sentry.origin' => 'auto.log.monolog']);
    }
}
