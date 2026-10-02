<?php

namespace App;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class PostRespondRaspHandler
{
    public function handle(ServerRequestInterface $req): ResponseInterface
    {
        // Schedule RASP instrumentation to run after respond() returns, which is
        // after ddappsec's request_shutdown has been sent. The evaluations cannot
        // happen at that point, so each must be accounted for under RFC-1012's
        // appsec.rasp.rule.skipped instead.
        //
        // The first call carries no rule variant, the second one does, so the test
        // can check that rule_variant is only tagged when present.
        // The ssrf address is a closed local port: the RASP push happens in a pre-hook,
        // before any I/O, so no reachable host is needed and this fails instantly rather
        // than stalling the worker between requests for the connect timeout.
        $GLOBALS['_rr_post_respond'] = static function () {
            @fopen('../etc/passwd', 'r');
            @fopen('http://127.0.0.1:1/', 'r');
        };
        return new Response(200, ['Content-Type' => 'text/plain'], 'OK');
    }
}
