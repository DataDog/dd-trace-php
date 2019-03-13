<?php

namespace DDTrace\Contracts;

/**
 * An interface describing a generic integration.
 */
interface Integration
{
    /**
     * @return string The integration name.
     */
    public function getName();
}
