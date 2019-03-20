<?php

namespace DDTrace\Integrations\Web;

use DDTrace\Integrations\Integration;
use DDTrace\Integrations\AbstractIntegration;

final class WebIntegration extends AbstractIntegration
{
    const NAME = 'web';

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

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
