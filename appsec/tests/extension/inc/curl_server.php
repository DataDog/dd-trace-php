<?php
// A minimal HTTP/1.1 server for the curl_downstream_*.phpt tests. Run it with
// `php -n curl_server.php [<log file>]`: it listens on a free port of
// 127.0.0.1, prints "PORT <n>" and serves one connection at a time, closing
// each after one response. It exits after 60 seconds without connections or
// when its stdin is closed (the test process went away). With a log file, it
// appends "<method> <target>" for each request it reads, and "(no request
// bytes)" for a connection closed before it sent any byte.
//
// Routes:
//   /json            200, application/json, Content-Length
//   /echo            200, text/plain, the request body (de-chunked)
//   /reqheaders      200, application/json, the names of the request
//                    headers received (lower case, sorted)
//   /len             200, text/plain, "received <n> bytes"
//   /recv            as /len, and logs "body <n> bytes" (with a log file)
//   /final           with "Expect: 100-continue": 200 at once, without
//                    "100 Continue" and without reading the body; logs
//                    "body not read" (otherwise as /len)
//   /final-to?u=<url>&code=<c>  with "Expect: 100-continue": <c> (default
//                    307) to <url> (urlencoded) at once, without "100
//                    Continue" and without reading the body; logs "body not
//                    read". Otherwise <c> to <url> after reading the body,
//                    logging "body <n> bytes"
//   /chunked         200, chunked in several writes, with chunk extensions
//                    and a trailer
//   /gzip            200, Content-Encoding: gzip
//   /gzip-json       200, application/json, Content-Encoding: gzip
//   /chunked-json    200, application/json, chunked in two chunks
//                    (trailer=1: with a trailer line)
//   /redirect        302 to /json, with a body
//   /redirect-to?u=<url>  302 to <url> (urlencoded), with a body
//   /redirect-chain?n=<k>  302 to /redirect-chain?n=<k-1> while k > 0, then
//                    as /json
//   /big?n=<n>       200, <n> bytes, Content-Length
//   /status/<code>   <code>, empty body
//   /xml             200, application/xml
//   /echo-xml        200, application/xml, the request body
//   /form            200, application/x-www-form-urlencoded
//   /formq?<query>   200, application/x-www-form-urlencoded, the query
//                    string as the body
//   /multipart       200, multipart/form-data, a body of one field
//   /octet           200, application/octet-stream, "k=v&n=1"
//   /untyped         200, no Content-Type, "k=v&n=1"
//   /close           200, text/plain, no Content-Length (ends at close)
//   /chunk16         200, chunked, chunk sizes of 16 hex digits
//   /chunk0          200, chunked, empty body
//   /badchunk        200, chunked, malformed chunk size
//   /digest          401 with a Digest challenge, unless the request has an
//                    "Authorization: Digest" header (any): then /json
// It also acts as an HTTP proxy: a request in absolute form
// ("GET http://host/path") is served as if it were for /path, and CONNECT
// opens a tunnel to this same server, whatever the target. For a target
// whose host starts with "auth.", CONNECT without Proxy-Authorization gets a
// 407. Through the tunnel, TLS is accepted (with a new self-signed
// certificate for example.test) when the client starts with a handshake.
// A request with "Expect: 100-continue" gets "100 Continue" before its body
// is read.

$srv = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!$srv) {
    fwrite(STDOUT, "ERROR $errstr\n");
    exit(1);
}
$log = isset($argv[1]) ? $argv[1] : null;
$name = stream_socket_get_name($srv, false);
$port = (int)substr($name, strrpos($name, ':') + 1);
fwrite(STDOUT, "PORT $port\n");
fflush(STDOUT);

// "hello gzip world\n", gzip-compressed (no zlib needed here)
$gzip = base64_decode('H4sIAAAAAAAA/8tIzcnJV0ivyixQKM8vyknhAgA+VgDoEQAAAA==');

$idle_since = time();
while (time() - $idle_since < 60) {
    $r = [$srv, STDIN];
    $w = $e = null;
    if (@stream_select($r, $w, $e, 1) < 1) {
        continue;
    }
    // The test process never writes to our stdin: readable means it closed
    // it. feof() alone is not enough, as it only turns true after a read hit
    // the end; without the read this loop would spin until the idle timeout.
    if (in_array(STDIN, $r, true) && ((string)fread(STDIN, 1) === '' || feof(STDIN))) {
        break;
    }
    if (!in_array($srv, $r, true)) {
        continue;
    }
    $conn = @stream_socket_accept($srv, 5);
    if (!$conn) {
        continue;
    }
    stream_set_timeout($conn, 10);
    handle($conn, $gzip, $log);
    fclose($conn);
    $idle_since = time();
}

function handle($conn, $gzip, $log)
{
    $line = fgets($conn);
    if ($line === false) {
        if ($log !== null) {
            file_put_contents($log, "(no request bytes)\n", FILE_APPEND);
        }
        return;
    }
    $parts = explode(' ', trim($line));
    $target = isset($parts[1]) ? $parts[1] : '/';
    if ($log !== null) {
        file_put_contents($log, $parts[0] . " $target\n", FILE_APPEND);
    }
    $headers = [];
    while (($l = fgets($conn)) !== false) {
        $l = rtrim($l, "\r\n");
        if ($l === '') {
            break;
        }
        $p = strpos($l, ':');
        if ($p !== false) {
            $headers[strtolower(trim(substr($l, 0, $p)))] = trim(substr($l, $p + 1));
        }
    }

    if ($parts[0] === 'CONNECT') {
        tunnel($conn, $target, $headers, $gzip, $log);
        return;
    }

    if (isset($headers['expect']) && strtolower($headers['expect']) === '100-continue') {
        $epath = parse_url($target, PHP_URL_PATH);
        if ($epath === '/final' || $epath === '/final-to') {
            // a final status instead of 100: the client sends no body
            if ($log !== null) {
                file_put_contents($log, "body not read\n", FILE_APPEND);
            }
            if ($epath === '/final-to') {
                final_to($conn, (string)parse_url($target, PHP_URL_QUERY));
                return;
            }
            respond($conn, 200, ['Content-Type: text/plain'], 'final without 100');
            return;
        }
        fwrite($conn, "HTTP/1.1 100 Continue\r\n\r\n");
    }

    $body = '';
    if (isset($headers['transfer-encoding']) && stripos($headers['transfer-encoding'], 'chunked') !== false) {
        while (($sl = fgets($conn)) !== false) {
            $size = hexdec(trim(explode(';', $sl)[0]));
            if ($size === 0) {
                while (($tl = fgets($conn)) !== false && rtrim($tl, "\r\n") !== '') {
                }
                break;
            }
            $body .= read_exact($conn, $size);
            fgets($conn); // CRLF
        }
    } elseif (isset($headers['content-length'])) {
        $body = read_exact($conn, (int)$headers['content-length']);
    }

    $path = parse_url($target, PHP_URL_PATH);
    $query = (string)parse_url($target, PHP_URL_QUERY);
    switch (true) {
        case $path === '/json':
            respond($conn, 200, ['Content-Type: application/json'], '{"status":"ok","n":42}');
            break;
        case $path === '/echo':
            respond($conn, 200, ['Content-Type: text/plain'], $body);
            break;
        case $path === '/reqheaders':
            // the names of the request headers received, sorted
            $names = array_keys($headers);
            sort($names);
            respond($conn, 200, ['Content-Type: application/json'], json_encode($names));
            break;
        case $path === '/echo-json':
            respond($conn, 200, ['Content-Type: application/json'], $body);
            break;
        case $path === '/echo-xml':
            respond($conn, 200, ['Content-Type: application/xml'], $body);
            break;
        case $path === '/len':
        case $path === '/final':
            respond($conn, 200, ['Content-Type: text/plain'], 'received ' . strlen($body) . ' bytes');
            break;
        case $path === '/recv':
            if ($log !== null) {
                file_put_contents($log, 'body ' . strlen($body) . " bytes\n", FILE_APPEND);
            }
            respond($conn, 200, ['Content-Type: text/plain'], 'received ' . strlen($body) . ' bytes');
            break;
        case $path === '/chunked':
            fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\n" .
                "Transfer-Encoding: chunked\r\nConnection: close\r\n\r\n");
            foreach (["A;ext=1\r\n0123456789\r\n", "5\r\nabcde\r\n", "0\r\nX-Trailer: t\r\n\r\n"] as $piece) {
                fwrite($conn, $piece);
                fflush($conn);
                usleep(20000);
            }
            break;
        case $path === '/gzip':
            respond($conn, 200, ['Content-Type: text/plain', 'Content-Encoding: gzip'], $gzip);
            break;
        case $path === '/gzip-json':
            // {"status":"ok","n":42}, gzip-compressed (42 bytes)
            respond($conn, 200, ['Content-Type: application/json', 'Content-Encoding: gzip'],
                base64_decode('H4sIAAAAAAAA/6tWKi5JLCktVrJSys9W0lHKU7IyMaoFAG0S8E8WAAAA'));
            break;
        case $path === '/chunked-json':
            // {"status":"ok","n":42} in two chunks; trailer=1: with a trailer
            parse_str($query, $q);
            fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n" .
                "Transfer-Encoding: chunked\r\nConnection: close\r\n\r\n" .
                "b\r\n{\"status\":\"\r\nb\r\nok\",\"n\":42}\r\n0\r\n" .
                (isset($q['trailer']) ? "X-Trailer: t\r\n" : '') . "\r\n");
            break;
        case $path === '/final-to':
            if ($log !== null) {
                file_put_contents($log, 'body ' . strlen($body) . " bytes\n", FILE_APPEND);
            }
            final_to($conn, $query);
            break;
        case $path === '/redirect':
            respond($conn, 302, ['Location: /json', 'Content-Type: text/plain'], 'redirecting');
            break;
        case $path === '/redirect-chain':
            parse_str($query, $q);
            $n = isset($q['n']) ? (int)$q['n'] : 0;
            if ($n > 0) {
                respond($conn, 302, ['Location: /redirect-chain?n=' . ($n - 1),
                    'Content-Type: text/plain'], 'redirecting');
            } else {
                respond($conn, 200, ['Content-Type: application/json'], '{"status":"ok","n":42}');
            }
            break;
        case $path === '/redirect-to':
            parse_str($query, $q);
            // json=1: the body of the redirect is JSON (so it is analysed)
            respond($conn, 302, ['Location: ' . (isset($q['u']) ? $q['u'] : '/json'),
                isset($q['json']) ? 'Content-Type: application/json' : 'Content-Type: text/plain'],
                isset($q['json']) ? '{"r":"redirecting"}' : 'redirecting');
            break;
        case $path === '/big':
            parse_str($query, $q);
            $n = isset($q['n']) ? (int)$q['n'] : 1000;
            $big = substr(str_repeat('0123456789', intdiv($n, 10) + 1), 0, $n);
            if (isset($q['json'])) {
                // json=1: a JSON object of n bytes, with one long string
                $big = '{"a":"' . substr($big, 0, max(0, $n - 8)) . '"}';
                $big = substr($big, 0, $n);
            }
            respond($conn, 200, [isset($q['json']) ? 'Content-Type: application/json' : 'Content-Type: text/plain'], $big);
            break;
        case $path === '/manyheaders':
            // n distinct short header lines; a 302 to /json with redirect=1
            parse_str($query, $q);
            $hs = isset($q['redirect']) ? ['Location: /json'] : [];
            for ($i = 0, $n = isset($q['n']) ? (int)$q['n'] : 1000; $i < $n; $i++) {
                $hs[] = "X-H$i: v";
            }
            $hs[] = 'Content-Type: text/plain';
            respond($conn, isset($q['redirect']) ? 302 : 200, $hs, 'ok');
            break;
        case $path === '/xml':
            respond($conn, 200, ['Content-Type: application/xml'], '<doc><item attr="v">text</item></doc>');
            break;
        case $path === '/form':
            respond($conn, 200, ['Content-Type: application/x-www-form-urlencoded'], 'k=v&arr[]=1&arr[]=2');
            break;
        case $path === '/formq':
            respond($conn, 200, ['Content-Type: application/x-www-form-urlencoded'], $query);
            break;
        case $path === '/multipart':
            respond($conn, 200, ['Content-Type: multipart/form-data; boundary=XX'],
                "--XX\r\nContent-Disposition: form-data; name=\"k\"\r\n\r\nv\r\n--XX--\r\n");
            break;
        case $path === '/octet':
            respond($conn, 200, ['Content-Type: application/octet-stream'], 'k=v&n=1');
            break;
        case $path === '/untyped':
            respond($conn, 200, [], 'k=v&n=1');
            break;
        case $path === '/close':
            // json=1: the body is the JSON object {"a":"body until close"}
            parse_str($query, $q);
            fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: " .
                (isset($q['json']) ? 'application/json' : 'text/plain') .
                "\r\nConnection: close\r\n\r\n" .
                (isset($q['json']) ? '{"a":"body until close"}' : 'body until close'));
            break;
        case $path === '/slow':
            // the head now, the body later: the transfer is still running
            // when the test acts on it after seeing the head
            fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\n" .
                "Content-Length: 10\r\nConnection: close\r\n\r\n");
            fflush($conn);
            usleep(300000);
            fwrite($conn, "0123456789");
            break;
        case $path === '/stall':
            // review: part of the body now, the rest after a while
            // (json=1: the 30 bytes are the JSON object {"a":"0123456789...")
            parse_str($query, $q);
            $json = isset($q['json']);
            fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: " .
                ($json ? 'application/json' : 'text/plain') . "\r\n" .
                "Content-Length: 30\r\nConnection: close\r\n\r\n" .
                ($json ? '{"a":"0123456789' : '0123456789'));
            fflush($conn);
            usleep(2000000);
            fwrite($conn, $json ? '012345678901"}' : "01234567890123456789");
            break;
        case $path === '/chunk16':
        case $path === '/chunk0':
        case $path === '/badchunk':
            // json=1: the content type is JSON (the body of /chunk16 then is
            // {"a":"0123456789abcde"}), so that it is analysed
            parse_str($query, $q);
            $json = isset($q['json']);
            $head = "HTTP/1.1 200 OK\r\nContent-Type: " . ($json ? 'application/json' : 'text/plain') .
                "\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n";
            if ($path === '/chunk16') {
                $chunks = $json ? ['{"a":"0123', '456789abcde"}'] : ['0123456789', 'abcde'];
                $out = $head;
                foreach ($chunks as $c) {
                    $out .= sprintf("%016x\r\n%s\r\n", strlen($c), $c);
                }
                fwrite($conn, $out . "0000000000000000\r\n\r\n");
            } elseif ($path === '/chunk0') {
                fwrite($conn, $head . "0\r\n\r\n");
            } else {
                fwrite($conn, $head . "zz\r\nabc\r\n");
            }
            break;
        case $path === '/digest':
            if (isset($headers['authorization']) && stripos($headers['authorization'], 'Digest ') === 0) {
                respond($conn, 200, ['Content-Type: application/json'], '{"status":"ok","n":42}');
            } else {
                respond($conn, 401, ['WWW-Authenticate: Digest realm="test", qop="auth", ' .
                    'nonce="dcd98b7102dd2f0e8b11d0f600bfb0c093", opaque="5ccc069c403ebaf9f0171e9517f40e41"',
                    'Content-Type: text/plain'], 'unauthorized');
            }
            break;
        case strpos($path, '/status/') === 0:
            respond($conn, (int)substr($path, 8), [], '');
            break;
        default:
            respond($conn, 404, ['Content-Type: text/plain'], 'not found');
    }
}

// CONNECT <target>: 407 for an "auth." host without credentials; otherwise
// "200 Connection established", then a request through the tunnel, over TLS
// if the client starts a TLS handshake
function tunnel($conn, $target, array $headers, $gzip, $log)
{
    if (strpos($target, 'auth.') === 0 && !isset($headers['proxy-authorization'])) {
        $body = 'proxy authentication required';
        fwrite($conn, "HTTP/1.1 407 Proxy Authentication Required\r\n" .
            "Proxy-Authenticate: Basic realm=\"test\"\r\nContent-Type: text/plain\r\n" .
            'Content-Length: ' . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
        return;
    }
    fwrite($conn, "HTTP/1.1 200 Connection established\r\nX-Proxy: tunnel\r\n\r\n");
    fflush($conn);
    $first = stream_socket_recvfrom($conn, 1, STREAM_PEEK);
    if ($first === "\x16") {
        // OpenSSL reads the file when the handshake is set up
        $cert = tls_cert_file();
        stream_context_set_option($conn, 'ssl', 'local_cert', $cert);
        stream_context_set_option($conn, 'ssl', 'verify_peer', false);
        // before PHP 7.2, TLS_SERVER means TLS 1.0 only, which OpenSSL 3
        // refuses by default
        $ok = @stream_socket_enable_crypto($conn, true,
            STREAM_CRYPTO_METHOD_TLS_SERVER | STREAM_CRYPTO_METHOD_TLSv1_2_SERVER);
        unlink($cert);
        if (!$ok) {
            return;
        }
    }
    handle($conn, $gzip, $log);
}

// a new self-signed certificate for example.test, with its key
function tls_cert_file()
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    $csr = openssl_csr_new(['commonName' => 'example.test'], $key);
    $cert = openssl_csr_sign($csr, null, $key, 1);
    openssl_x509_export($cert, $cert_pem);
    openssl_pkey_export($key, $key_pem);
    $file = tempnam(sys_get_temp_dir(), 'cdcert');
    file_put_contents($file, $cert_pem . $key_pem);
    return $file;
}

function read_exact($conn, $n)
{
    $data = '';
    while (strlen($data) < $n && !feof($conn)) {
        $chunk = fread($conn, $n - strlen($data));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $data .= $chunk;
    }
    return $data;
}

// /final-to: a redirect (307 unless code=<c>) to u=<url>
function final_to($conn, $query)
{
    parse_str($query, $q);
    respond($conn, isset($q['code']) ? (int)$q['code'] : 307,
        ['Location: ' . (isset($q['u']) ? $q['u'] : '/recv'), 'Content-Type: text/plain'],
        'redirecting');
}

function respond($conn, $status, array $headers, $body)
{
    $reasons = [200 => 'OK', 204 => 'No Content', 302 => 'Found', 401 => 'Unauthorized',
        404 => 'Not Found'];
    $reason = isset($reasons[$status]) ? $reasons[$status] : 'Status';
    $out = "HTTP/1.1 $status $reason\r\n";
    foreach ($headers as $h) {
        $out .= "$h\r\n";
    }
    if ($status !== 204) {
        $out .= 'Content-Length: ' . strlen($body) . "\r\n";
    }
    $out .= "Connection: close\r\n\r\n" . $body;
    fwrite($conn, $out);
}
