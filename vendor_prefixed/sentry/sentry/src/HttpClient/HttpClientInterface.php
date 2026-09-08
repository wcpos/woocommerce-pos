<?php

declare (strict_types=1);
namespace WCPOS\Vendor\Sentry\HttpClient;

use WCPOS\Vendor\Sentry\Options;
interface HttpClientInterface
{
    public function sendRequest(Request $request, Options $options) : Response;
}
