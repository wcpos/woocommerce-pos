<?php

declare (strict_types=1);
namespace WCPOS\Vendor\Sentry\Integration;

use WCPOS\Vendor\Sentry\Options;
interface OptionAwareIntegrationInterface extends IntegrationInterface
{
    /**
     * Sets the options for the integration, is called before `setupOnce()`.
     */
    public function setOptions(Options $options) : void;
}
