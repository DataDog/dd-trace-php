<?php

namespace App;

use Nyholm\Psr7\Response;
use Spiral\RoadRunner\Http\Request;

class XmlHandler
{
    /**
     * @param Request $req
     * @return Response
     */
    public function handle($req): Response
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
            $c);
    }
}
