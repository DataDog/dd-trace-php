<?php

namespace DDTrace\Tests\Common;

/**
 * Factory trait for handling version-specific method declarations.
 */
trait RetryTraitFactory
{
    /**
     * Get the appropriate version-specific trait based on PHP version.
     *
     * @return string
     */
    private static function getVersionSpecificTrait(): string
    {
        if (\version_compare(\PHP_VERSION, '7.1.0', '<')) {
            return RetryTraitVersionSpecific70::class;
        }
        return RetryTraitVersionGeneric::class;
    }
}
