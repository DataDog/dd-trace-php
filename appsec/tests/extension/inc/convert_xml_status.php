<?php
// Calls convert_xml and reads the parse status from the AppSec log, which
// convert_xml's result alone does not reveal: Partial and Complete bodies both
// return data. Requires datadog.appsec.log_level=debug and a log_file.
function convert_xml_status($xml, $content_type = 'application/xml')
{
    $log = ini_get('datadog.appsec.log_file');
    clearstatcache();
    $offset = file_exists($log) ? filesize($log) : 0;
    $result = datadog\appsec\convert_xml($xml, $content_type);
    $new = (string)file_get_contents($log, false, null, $offset);
    if (strpos($new, 'Successfully parsed XML') !== false) {
        $status = 'Complete';
    } elseif (strpos($new, 'with partial data') !== false) {
        $status = 'Partial';
    } elseif (strpos($new, 'Error parsing XML (no data)') !== false) {
        $status = 'Empty';
    } else {
        $status = 'not parsed'; // e.g. an unsupported Content-Type charset
    }
    return [$status, $result];
}

// The status followed by the JSON-encoded result.
function convert_xml_status_json($xml, $content_type = 'application/xml', $flags = 0)
{
    list($status, $result) = convert_xml_status($xml, $content_type);
    return $status . ' ' . json_encode($result, $flags);
}
