<?php

namespace App;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

// Runtime driver for Php2ShellFrankenphpWorkerTests, as
// php2shell_runtime_oom.php is for php-fpm: one allocation of ?alloc= bytes
// inside an HTTP request of the worker, then a VM safe point (a user function
// call and a loop), where an armed mitigation raises its deferred fatal.
// Unarmed, the engine raises its own fatal inside the allocation. RAN is
// returned only if no error was raised.
class Php2ShellOomHandler
{
    public function handle(ServerRequestInterface $req): ResponseInterface
    {
        $qp = $req->getQueryParams();
        $s = str_repeat('x', (int) ($qp['alloc'] ?? 1000));
        self::safePoint();
        for ($i = 0; $i < 2; $i++) {
        }
        return new Response(
            200,
            ['Content-Type' => 'text/plain'],
            'RAN len=' . strlen($s)
        );
    }

    private static function safePoint(): void
    {
    }
}
