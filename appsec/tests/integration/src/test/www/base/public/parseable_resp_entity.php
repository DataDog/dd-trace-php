<?php

if (isset($_GET['json'])) {
    header('Content-Type: application/json');
    echo json_encode(['message' => ['Hello world!', 42, true, 'poison']]);
} else if (isset($_GET['xml'])) {
    header('Content-Type: application/xml;charset=utf-8');
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<note foo="bar">
  <from>Jean</from>
  poison
</note>
XML;
    echo $xml;
} else {
    die('use ?json=1 or ?xml=1');
}
