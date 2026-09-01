<?php

declare (strict_types=1);
namespace WCPOS\Vendor\Sentry\Serializer;

use WCPOS\Vendor\Sentry\Event;
use WCPOS\Vendor\Sentry\EventType;
use WCPOS\Vendor\Sentry\Options;
use WCPOS\Vendor\Sentry\Serializer\EnvelopItems\CheckInItem;
use WCPOS\Vendor\Sentry\Serializer\EnvelopItems\ClientReportItem;
use WCPOS\Vendor\Sentry\Serializer\EnvelopItems\EventItem;
use WCPOS\Vendor\Sentry\Serializer\EnvelopItems\LogsItem;
use WCPOS\Vendor\Sentry\Serializer\EnvelopItems\MetricsItem;
use WCPOS\Vendor\Sentry\Serializer\EnvelopItems\ProfileItem;
use WCPOS\Vendor\Sentry\Serializer\EnvelopItems\TransactionItem;
use WCPOS\Vendor\Sentry\Tracing\DynamicSamplingContext;
use WCPOS\Vendor\Sentry\Util\JSON;
/**
 * This is a simple implementation of a serializer that takes in input an event
 * object and returns a serialized string ready to be sent off to Sentry.
 *
 * @internal
 */
final class PayloadSerializer implements PayloadSerializerInterface
{
    /**
     * @var Options The SDK client options
     */
    private $options;
    public function __construct(Options $options)
    {
        $this->options = $options;
    }
    /**
     * {@inheritdoc}
     */
    public function serialize(Event $event) : string
    {
        $envelopeHeader = null;
        if ($event->getType() !== EventType::clientReport()) {
            // @see https://develop.sentry.dev/sdk/envelopes/#envelope-headers
            $envelopeHeader = ['sent_at' => \gmdate('Y-m-d\\TH:i:s\\Z'), 'dsn' => (string) $this->options->getDsn(), 'sdk' => $event->getSdkPayload()];
            if ($event->getType()->requiresEventId()) {
                $envelopeHeader['event_id'] = (string) $event->getId();
            }
            $dynamicSamplingContext = $event->getSdkMetadata('dynamic_sampling_context');
            if ($dynamicSamplingContext instanceof DynamicSamplingContext) {
                $entries = $dynamicSamplingContext->getEntries();
                if (!empty($entries)) {
                    $envelopeHeader['trace'] = $entries;
                }
            }
        }
        $items = [];
        switch ($event->getType()) {
            case EventType::event():
                $items[] = EventItem::toEnvelopeItem($event);
                break;
            case EventType::transaction():
                $items[] = TransactionItem::toEnvelopeItem($event);
                if ($event->getSdkMetadata('profile') !== null) {
                    $items[] = ProfileItem::toEnvelopeItem($event);
                }
                break;
            case EventType::checkIn():
                $items[] = CheckInItem::toEnvelopeItem($event);
                break;
            case EventType::logs():
                $items[] = LogsItem::toEnvelopeItem($event);
                break;
            case EventType::metrics():
                $items[] = MetricsItem::toEnvelopeItem($event);
                break;
            case EventType::clientReport():
                $items[] = ClientReportItem::toEnvelopeItem($event);
                break;
        }
        if ($envelopeHeader === null) {
            return \sprintf("{}\n%s", \implode("\n", \array_filter($items)));
        }
        return \sprintf("%s\n%s", JSON::encode($envelopeHeader), \implode("\n", \array_filter($items)));
    }
}
