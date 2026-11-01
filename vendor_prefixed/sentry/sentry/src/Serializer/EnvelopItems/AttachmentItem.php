<?php

declare (strict_types=1);
namespace WCPOS\Vendor\Sentry\Serializer\EnvelopItems;

use WCPOS\Vendor\Sentry\Attachment\Attachment;
use WCPOS\Vendor\Sentry\Util\JSON;
class AttachmentItem
{
    public static function toAttachmentItem(Attachment $attachment) : ?string
    {
        $data = $attachment->getData();
        if ($data === null) {
            return null;
        }
        $header = ['type' => 'attachment', 'filename' => $attachment->getFilename(), 'content_type' => $attachment->getContentType(), 'attachment_type' => 'event.attachment', 'length' => \strlen($data)];
        return \sprintf("%s\n%s", JSON::encode($header), $data);
    }
}
