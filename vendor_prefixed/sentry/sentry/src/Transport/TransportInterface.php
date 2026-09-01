<?php

declare (strict_types=1);
namespace WCPOS\Vendor\Sentry\Transport;

use WCPOS\Vendor\Sentry\Event;
interface TransportInterface
{
    public function send(Event $event) : Result;
    public function close(?int $timeout = null) : Result;
}
