<?php

// Keep incomplete requests separate. An idle connection must not block the
// configuration poller or either of the two independent event writers.
function ffeTestHttpRequest($server, &$clients)
{
    $read = array($server);
    foreach ($clients as $client) $read[] = $client['stream'];
    $write = $except = null;
    if (stream_select($read, $write, $except, 0, 50000) === false) {
        throw new RuntimeException('cannot select HTTP connections');
    }
    foreach ($read as $connection) {
        if ($connection === $server) {
            if ($connection = @stream_socket_accept($server, 0)) {
                stream_set_blocking($connection, false);
                $clients[(int)$connection] = array('stream' => $connection, 'buffer' => '');
            }
            continue;
        }
        $id = (int)$connection;
        $chunk = fread($connection, 8192);
        if ($chunk === false) throw new RuntimeException('cannot read HTTP request');
        if ($chunk === '') {
            if (feof($connection)) {
                fclose($connection);
                unset($clients[$id]);
            }
            continue;
        }
        $clients[$id]['buffer'] .= $chunk;
        $buffer = $clients[$id]['buffer'];
        $headerEnd = strpos($buffer, "\r\n\r\n");
        if ($headerEnd === false) continue;
        $lines = explode("\r\n", substr($buffer, 0, $headerEnd));
        $request = explode(' ', array_shift($lines));
        if (count($request) !== 3) throw new RuntimeException('invalid HTTP request line');
        $headers = array();
        foreach ($lines as $line) {
            $pair = explode(':', $line, 2);
            if (count($pair) !== 2) throw new RuntimeException('invalid HTTP header');
            $headers[strtolower($pair[0])] = trim($pair[1]);
        }
        $length = isset($headers['content-length']) ? (int)$headers['content-length'] : 0;
        $body = substr($buffer, $headerEnd + 4);
        if (strlen($body) < $length) continue;
        unset($clients[$id]);
        stream_set_blocking($connection, true);
        stream_set_timeout($connection, 2);
        return array($connection, $request[1], $headers, $body);
    }
    return null;
}
