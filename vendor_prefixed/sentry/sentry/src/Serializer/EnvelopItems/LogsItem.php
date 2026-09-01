<?php

declare (strict_types=1);
namespace WCPOS\Vendor\Sentry\Serializer\EnvelopItems;

use WCPOS\Vendor\Sentry\Attributes\Attribute;
use WCPOS\Vendor\Sentry\Event;
use WCPOS\Vendor\Sentry\EventType;
use WCPOS\Vendor\Sentry\Logs\Log;
use WCPOS\Vendor\Sentry\Util\JSON;
/**
 * @internal
 */
class LogsItem implements EnvelopeItemInterface
{
    public static function toEnvelopeItem(Event $event) : string
    {
        $logs = $event->getLogs();
        $header = ['type' => (string) EventType::logs(), 'item_count' => \count($logs), 'content_type' => 'application/vnd.sentry.items.log+json'];
        return \sprintf("%s\n%s", JSON::encode($header), JSON::encode(['items' => \array_map(static function (Log $log) : array {
            return ['timestamp' => $log->getTimestamp(), 'trace_id' => $log->getTraceId(), 'level' => (string) $log->getLevel(), 'body' => $log->getBody(), 'attributes' => \array_map(static function (Attribute $attribute) : array {
                return ['type' => $attribute->getType(), 'value' => $attribute->getValue()];
            }, $log->attributes()->all())];
        }, $logs)]));
    }
}
