<?php

declare (strict_types=1);
namespace WCPOS\Vendor\Sentry\Profiling;

interface ProfilerInterface
{
    public function start() : void;
    public function stop() : void;
    public function getProfile() : ?Profile;
}
