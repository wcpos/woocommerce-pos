<?php

declare (strict_types=1);
namespace WCPOS\Vendor\Sentry\Serializer\EnvelopItems;

use WCPOS\Vendor\Sentry\Event;
/**
 * @internal
 */
interface EnvelopeItemInterface
{
    public static function toEnvelopeItem(Event $event) : ?string;
}
