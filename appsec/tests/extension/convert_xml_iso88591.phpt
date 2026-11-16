--TEST--
_convert_xml function (ISO-8859-1)
--INI--
datadog.appsec.log_level=info
--FILE--
<?php

$entity = <<<XML
<note value="\xC3">
</note>
XML;

$content_type = "application/xml;charset=iso-8859-1";

$result = datadog\appsec\convert_xml($entity, $content_type);
var_dump($result);

--EXPECTF--
Notice: datadog\appsec\convert_xml(): [ddappsec] Only UTF-8 is supported for XML parsing in %s on line %d
NULL
