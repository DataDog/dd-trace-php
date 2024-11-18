<?php

namespace DDTrace\Integrations\SymfonyMessenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * @deprecated since 1.5.0
 */
final class DDTraceStamp implements StampInterface
{
    private $headers;

    public function __construct(array $headers)
    {
        $this->headers = $headers;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }
}
