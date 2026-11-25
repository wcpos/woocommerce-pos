<?php

declare (strict_types=1);
namespace WCPOS\Vendor\Sentry\Serializer\EnvelopItems;

use WCPOS\Vendor\Sentry\Event;
use WCPOS\Vendor\Sentry\Profiling\Profile;
use WCPOS\Vendor\Sentry\Util\JSON;
/**
 * @internal
 */
class ProfileItem implements EnvelopeItemInterface
{
    public static function toEnvelopeItem(Event $event) : ?string
    {
        $header = ['type' => 'profile', 'content_type' => 'application/json'];
        $profile = $event->getSdkMetadata('profile');
        if (!$profile instanceof Profile) {
            return null;
        }
        $payload = $profile->getFormattedData($event);
        if ($payload === null) {
            // The profile can never be sent, so detach it to not record its drop again
            // when the event is serialized a second time (e.g. for Spotlight) or dropped later.
            $event->setSdkMetadata('profile', null);
            return null;
        }
        return \sprintf("%s\n%s", JSON::encode($header), JSON::encode($payload));
    }
}
