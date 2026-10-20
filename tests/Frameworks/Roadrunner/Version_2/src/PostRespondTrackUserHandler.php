<?php

namespace App;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class PostRespondTrackUserHandler
{
    public function handle(ServerRequestInterface $req): ResponseInterface
    {
        // Schedule track_user_login_success() to run after respond() returns.
        // worker.php consumes this callback after respond(), which is after
        // ddappsec's request_shutdown has been sent. track_user_login_success
        // is another RequestExec sender, so if it still reaches the helper
        // (socket open, active=true) it sends RequestExec into the outer loop.
        $GLOBALS['_rr_post_respond'] = static function () {
            \datadog\appsec\v2\track_user_login_success('test-user', true);
        };
        return new Response(200, ['Content-Type' => 'text/plain'], 'OK');
    }
}
