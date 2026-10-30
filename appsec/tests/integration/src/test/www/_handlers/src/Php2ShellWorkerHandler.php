<?php

namespace App;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

// Identifies the worker script run serving the request, for
// Php2ShellFrankenphpWorkerTests: the id is drawn when the worker script starts
// (index.php builds its handlers once), so a fatal that restarts the script
// changes it; tid is the OS thread, which a restart keeps.
class Php2ShellWorkerHandler
{
    private string $id;
    private int $requests = 0;

    public function __construct()
    {
        $this->id = bin2hex(random_bytes(8));
    }

    public function handle(ServerRequestInterface $req): ResponseInterface
    {
        $this->requests++;
        $tid = basename((string) readlink('/proc/thread-self'));
        return new Response(
            200,
            ['Content-Type' => 'text/plain'],
            "worker={$this->id} tid={$tid} requests={$this->requests}"
        );
    }
}
