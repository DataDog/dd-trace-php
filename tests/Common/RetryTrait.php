<?php

namespace DDTrace\Tests\Common;

if (\version_compare(PHP_VERSION, '7.1.0', '<')) {
    trait RetryTrait
    {
        use RetryTraitCommon;
        use RetryTraitVersionSpecific70;
    }
} else {
    trait RetryTrait
    {
        use RetryTraitCommon;
        use RetryTraitVersionGeneric;
    }
}
