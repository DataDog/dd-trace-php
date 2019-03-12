<?php

namespace DDTrace\Integrations\Web;

use DDTrace\Integrations\Integration;

class WebIntegration
{
    const NAME = 'web';

    /**
     * Loads the generic web request integration.
     *
     * @return int
     */
    public static function load()
    {
        // For now we do nothing, as this is done in the bootstrap logic at the moment. We may consider doing this
        // here instead, but leaving this for a future refactoring.
        return Integration::LOADED;
    }
}
