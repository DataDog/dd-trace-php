<?php

namespace App;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class XmlHandler
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $c = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<note foo="bar">
  <from>Jean</from>
  poison
</note>
XML;

        return new Response(
            200,
            ['Content-Type' => 'application/xml'],
            $c
        );
    }
}
