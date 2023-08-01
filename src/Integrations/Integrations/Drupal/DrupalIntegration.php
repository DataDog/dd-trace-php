<?php

namespace DDTrace\Integrations\Drupal;

use DDTrace\Integrations\Integration;

class DrupalIntegration extends Integration
{
    const NAME = 'drupal';

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return self::NAME;
    }

    public function init()
    {
        return Integration::LOADED;
    }
}