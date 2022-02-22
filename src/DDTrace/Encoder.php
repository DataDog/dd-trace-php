<?php

namespace DDTrace;

use DDTrace\Contracts\Tracer as TracerInterface;

/** @deprecated Obsoleted by moving related code to internal. */
interface Encoder
{
    /**
     * @param TracerInterface $tracer
     * @return string
     */
    public function encodeTraces(TracerInterface $tracer);

    /**
     * @return string
     */
    public function getContentType();
}
