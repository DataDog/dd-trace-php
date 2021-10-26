<?php

namespace DDTrace\Tests\Sapi;

interface Sapi
{
    /**
     * Start the SAPI process
     */
    public function start();

    /**
     * Stop the SAPI process
     */
    public function stop();

    /**
     * Whether or not SAPI runs as FastCGI
     */
    public function isFastCgi();

    /**
     * Check for errors, e.g. crashes or memory leaks
     * @return string|null
     */
    public function checkErrors();
}
