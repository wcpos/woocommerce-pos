<?php

declare (strict_types=1);
namespace WCPOS\Vendor\Sentry\Logger;

use WCPOS\Vendor\Psr\Log\AbstractLogger;
abstract class DebugLogger extends AbstractLogger
{
    /**
     * @param mixed              $level
     * @param string|\Stringable $message
     * @param mixed[]            $context
     */
    public function log($level, $message, array $context = []) : void
    {
        $formattedMessageAndContext = \implode(' ', \array_filter([(string) $message, \json_encode($context)]));
        $this->write(\sprintf("sentry/sentry: [%s] %s\n", $level, $formattedMessageAndContext));
    }
    public abstract function write(string $message) : void;
}
