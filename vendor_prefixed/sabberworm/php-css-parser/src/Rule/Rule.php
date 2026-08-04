<?php

declare (strict_types=1);
namespace WCPOS\Vendor\Sabberworm\CSS\Rule;

use WCPOS\Vendor\Sabberworm\CSS\Property\Declaration;
use function WCPOS\Vendor\Safe\class_alias;
// @phpstan-ignore function.impossibleType
if (!\class_exists(Rule::class, \false) && !\interface_exists(Rule::class, \false)) {
    class_alias(Declaration::class, Rule::class);
    // The test is expected to evaluate to false,
    // but allows for the deprecation notice to be picked up by IDEs like PHPStorm.
    // @phpstan-ignore booleanNot.alwaysTrue, booleanAnd.alwaysTrue, function.impossibleType
    if (!\class_exists(Rule::class, \false) && !\interface_exists(Rule::class, \false)) {
        /**
         * @deprecated in v9.2, will be removed in v10.0.  Use `Property\Declaration` instead, which is a direct
         *             replacement.
         */
        class Rule
        {
        }
    }
}
