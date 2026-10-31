<?php

namespace App;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

// Memory the worker keeps across HTTP requests, for
// Php2ShellFrankenphpWorkerTests: with ?limit= and ?margin=, replaces it with a
// string that brings real usage (memory_get_usage(true), the figure the
// mitigation checks) to limit - margin. RAN is returned only if no error was
// raised.
class Php2ShellHoldHandler
{
    private string $held = '';

    public function handle(ServerRequestInterface $req): ResponseInterface
    {
        $qp = $req->getQueryParams();
        $limit = (int) ($qp['limit'] ?? 0);
        if ($limit > 0) {
            $this->held = '';
            $n = $limit - (int) ($qp['margin'] ?? 0) - memory_get_usage(true);
            if ($n > 0) {
                $this->held = str_repeat('h', $n);
            }
        }
        return new Response(
            200,
            ['Content-Type' => 'text/plain'],
            'RAN held=' . strlen($this->held) . ' real=' . memory_get_usage(true)
        );
    }
}
