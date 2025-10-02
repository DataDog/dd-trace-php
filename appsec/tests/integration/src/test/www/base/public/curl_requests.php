<?php
$variant = $_GET['variant'] ?? 'simple';

// Hook for tracing WAF push_addresses calls
if (isset($_GET['trace_waf_runs'])) {
    \DDTrace\trace_function('datadog\appsec\push_addresses', function (\DDTrace\SpanData $span, array $args) {
        $span->name = 'appsec.push_addresses';
        $span->type = 'appsec';
        $span->service = 'appsec-waf';
        $span->resource = 'push_addresses';

        // Serialize first argument (data) to JSON and add as tag
        if (isset($args[0])) {
            $span->meta['push_call.data'] = json_encode($args[0]);
        }

        // Serialize second argument (options) to JSON and add as tag
        if (isset($args[1])) {
            $span->meta['push_call.options'] = json_encode($args[1]);
        }
    });
}

function curl_ret_transfer_exec($ch)
{
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        echo 'Error:' . curl_error($ch);
    } else {
        echo "Return from curl_exec:\n";
        var_dump($response);
    }
    curl_close($ch);
}
function endpoint_url($variant) : string
{
    return 'http://127.0.0.1:8899/curl_requests_endpoint.php?variant=' . $variant;
}

/**
 * Generate a JSON body just under the 512KB limit with a blocking pattern
 * @param string $blocking_pattern The pattern that triggers WAF blocking
 * @return string JSON-encoded body
 */
function generate_body_under_limit($blocking_pattern = 'blocked_request_body') : string
{
    $limit = 524288;
    $json_overhead = strlen('{"key":"","padding":""}') + strlen($blocking_pattern);
    $padding_size = $limit - $json_overhead - 100; // 100 byte safety margin
    return json_encode(array(
        'key' => $blocking_pattern,
        'padding' => str_repeat('a', $padding_size)
    ));
}

/**
 * Generate a JSON body over the 512KB limit with blocking pattern after truncation point
 * @param string $blocking_pattern The pattern that should NOT be captured (beyond limit)
 * @return string JSON-encoded body
 */
function generate_body_over_limit($blocking_pattern = 'blocked_request_body') : string
{
    $limit = 524288;
    $padding_size = $limit + 5000;
    return json_encode(array(
        'padding' => str_repeat('a', $padding_size),
        'key' => $blocking_pattern  // This comes after truncation point
    ));
}

switch ($variant) {
    case 'simple':
        $url = 'http://localhost/example.json';
        $ch = curl_init($url);
        curl_ret_transfer_exec($ch);
        break;
    case 'simple_post_json':
        $url = endpoint_url('echo');
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array('key' => 'blocked_request_body')));
        curl_ret_transfer_exec($ch);
        break;
    case 'simple_post_urlencoded':
        $url = endpoint_url('echo');
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array('key' => 'blocked_request_body')));
        curl_ret_transfer_exec($ch);
        break;
    case 'simple_post_multipart':
        $url = endpoint_url('echo');
        $ch = curl_init($url);
        $data = array('key' => 'blocked_request_body');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_ret_transfer_exec($ch);
        break;
    case 'simple_post_text_plain':
        $url = endpoint_url('echo');
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: text/plain'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'blocked_request_body');
        curl_ret_transfer_exec($ch);
        break;
    case 'infile_request':
    case 'infile_request_chunked':
        $url = endpoint_url('echo');
        file_put_contents('/tmp/blocked_request_body.json', '{"key": "blocked_request_body"}');
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Expect:'));
        $f = fopen("/tmp/blocked_request_body.json", 'rb');
        curl_setopt($ch, CURLOPT_INFILE, $f);
        if ($variant == 'infile_request_chunked') {
            curl_setopt($ch, CURLOPT_POST, true);
        } else {
            curl_setopt($ch, CURLOPT_PUT, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_INFILESIZE, filesize("/tmp/blocked_request_body.json"));
        }
        curl_ret_transfer_exec($ch);
        break;
    case 'readfunction_request':
    case 'readfunction_request_chunked':
        $url = endpoint_url('echo');
        $ch = curl_init($url);
        $data = '{"key": "blocked_request_body"}';
        $headers = array('Content-Type: application/json', 'Expect:');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($variant == 'readfunction_request_chunked') {
            curl_setopt($ch, CURLOPT_POST, true);
        } else {
            // with CURLOPT_POST, INFILESIZE is ignored: we're supposed to use
            // CURLOPT_POSTFIELDSIZE, but PHP doesn't expose it
            curl_setopt($ch, CURLOPT_UPLOAD, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_INFILESIZE, strlen($data));
        }

        $readPos = 0;
        curl_setopt($ch, CURLOPT_READFUNCTION, function ($ch, $fd, $length) use ($data, &$readPos) {
            if ($readPos >= strlen($data)) {
                return '';
            }
            $chunk = substr($data, $readPos, $length);
            $readPos += strlen($chunk);
            return $chunk;
        });
        curl_ret_transfer_exec($ch);
        break;
    case 'file_response':
        $url = 'http://localhost/example.json';
        $ch = curl_init($url);
        $outFile = fopen('/tmp/curl_outfile_response.json', 'w');
        curl_setopt($ch, CURLOPT_FILE, $outFile);
        curl_exec($ch);
        fclose($outFile);
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        } else {
            echo "Response written to /tmp/curl_outfile_response.json:\n",
            file_get_contents('/tmp/curl_outfile_response.json');
        }
        break;
    case 'writefunction_response':
        $url = 'http://localhost/example.json';
        $ch = curl_init($url);
        $responseData = '';
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$responseData) {
            $responseData .= $data;
            return strlen($data);
        });
        curl_exec($ch);
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        } else {
            echo "Response from writefunction curl request:\n";
            echo $responseData;
        }
        break;
    case 'writeheader':
        $url = 'http://localhost/example.json';
        $ch = curl_init($url);
        $f = fopen('/tmp/curl_header_response.txt', 'w');
        curl_setopt($ch, CURLOPT_WRITEHEADER, $f);
        curl_ret_transfer_exec($ch);
        echo "Headers:\n";
        fclose($f);
        echo file_get_contents('/tmp/curl_header_response.txt');
        break;
    case 'query_block':
        $url = 'http://localhost/example.html?param=blocked_query_param';
        $ch = curl_init($url);
        curl_ret_transfer_exec($ch);
        break;
    case 'uri_block':
        $url = 'http://localhost/blocked_uri_path/example.html';
        $ch = curl_init($url);
        curl_ret_transfer_exec($ch);
        break;
    case 'header_block':
        $url = endpoint_url('echo');
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-Custom-Header: blocked_request_headers'));
        curl_ret_transfer_exec($ch);
        break;
    case 'cookie_block':
        $url = endpoint_url('echo');
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_COOKIE, 'session=blocked_request_cookies');
        curl_ret_transfer_exec($ch);
        break;
    case 'method_put_block':
        $url = endpoint_url('echo');
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'test data');
        curl_ret_transfer_exec($ch);
        break;
    case 'method_put_block_curlopt_put':
        $url = endpoint_url('echo');
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_PUT, true);
        $fp = fopen('php://temp', 'r+');
        fwrite($fp, 'test data');
        $size = ftell($fp);
        rewind($fp);
        curl_setopt($ch, CURLOPT_INFILE, $fp);
        curl_setopt($ch, CURLOPT_INFILESIZE, $size);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Expect:'));
        curl_ret_transfer_exec($ch);
        break;
    case 'response_header_block':
        $url = endpoint_url('header');
        $ch = curl_init($url);
        curl_ret_transfer_exec($ch);
        break;
    case 'response_cookie_block':
        $url = endpoint_url('cookie');
        $ch = curl_init($url);
        curl_ret_transfer_exec($ch);
        break;
    case 'multi_exec_request_block':
        // Test curl_multi_exec with blocking request body in one handle
        $mh = curl_multi_init();

        $url = endpoint_url('echo');
        $ch1 = curl_init($url);
        curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch1, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch1, CURLOPT_POSTFIELDS, json_encode(array('key' => 'blocked_request_body')));
        curl_multi_add_handle($mh, $ch1);

        $ch2 = curl_init($url);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_POSTFIELDS, 'safe content');
        curl_multi_add_handle($mh, $ch2);

        $active = null;
        do {
            curl_multi_exec($mh, $active);
            if ($active) {
                curl_multi_select($mh);
            }

            while ($info = curl_multi_info_read($mh)) {
                $data = curl_multi_getcontent($info['handle']);
                echo "Handle completed with response:\n", $data, "\n";
            }
        } while ($active);

        echo "Multi-exec completed\n";
        curl_multi_remove_handle($mh, $ch1);
        curl_multi_remove_handle($mh, $ch2);
        curl_close($ch1);
        curl_close($ch2);
        curl_multi_close($mh);
        break;
    case 'multi_exec_response_block':
    case 'multi_exec_response_block_noreturntransfer':
        // Test blocking when curl_multi_info_read returns blocking response
        $mh = curl_multi_init();

        $ch1 = curl_init(endpoint_url('echo'));
        if ($variant !== 'multi_exec_response_block_noreturntransfer') {
            curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
        }
        curl_multi_add_handle($mh, $ch1);

        $ch2 = curl_init('http://localhost/example.json');
        if ($variant !== 'multi_exec_response_block_noreturntransfer') {
            curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        }
        curl_multi_add_handle($mh, $ch2);

        $active = null;
        ob_start();
        do {
            curl_multi_exec($mh, $active);

            // Use curl_multi_info_read to check for completed transfers
            while ($info = curl_multi_info_read($mh)) {
                if ($info['msg'] === CURLMSG_DONE) {
                    echo "Handle completed via info_read\n";
                    $data = curl_multi_getcontent($info['handle']);
                    echo "Response:\n", $data, "\n";
                }
            }

            if ($active) {
                curl_multi_select($mh);
            }
        } while ($active);
        ob_end_flush();

        echo "Multi-exec info_read completed\n";
        curl_multi_remove_handle($mh, $ch1);
        curl_multi_remove_handle($mh, $ch2);
        curl_close($ch1);
        curl_close($ch2);
        curl_multi_close($mh);
        break;
    case 'multi_exec_dynamic_add_block':
        // Test adding handle with blocking content during execution
        $mh = curl_multi_init();

        $url = endpoint_url('echo');

        $ch1 = curl_init($url);
        curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
        curl_multi_add_handle($mh, $ch1);

        $active = null;
        $exec_count = 0;
        $ch2_added = false;
        do {
            curl_multi_exec($mh, $active);
            $exec_count++;

            if ($exec_count === 2 && !$ch2_added) {
                $ch2 = curl_init($url);
                curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch2, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
                curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode(array('key' => 'blocked_request_body')));
                curl_multi_add_handle($mh, $ch2);
                $ch2_added = true;
            }

            while ($info = curl_multi_info_read($mh)) {
                $data = curl_multi_getcontent($info['handle']);
                echo "Handle completed with response:\n", $data, "\n";
            }

            if ($active) {
                curl_multi_select($mh, 0.1);
            }
        } while ($active);

        echo "Multi-exec dynamic add completed\n";
        curl_multi_remove_handle($mh, $ch1);
        if ($ch2_added) {
            curl_multi_remove_handle($mh, $ch2);
            curl_close($ch2);
        }
        curl_close($ch1);
        curl_multi_close($mh);
        break;
    case 'clone_block_request_body':
        // Test cloning a handle with blocking request body
        $url = endpoint_url('echo');
        $ch1 = curl_init($url);
        curl_setopt($ch1, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch1, CURLOPT_POSTFIELDS, json_encode(array('key' => 'blocked_request_body')));

        $ch2 = curl_copy_handle($ch1);

        curl_ret_transfer_exec($ch2);
        curl_close($ch1);
        break;
    case 'clone_block_header':
        // Test cloning a handle with blocking header
        $url = endpoint_url('echo');
        $ch1 = curl_init($url);
        curl_setopt($ch1, CURLOPT_HTTPHEADER, array('X-Custom-Header: blocked_request_headers'));

        $ch2 = curl_copy_handle($ch1);

        curl_ret_transfer_exec($ch2);
        curl_close($ch1);
        break;
    case 'clone_block_query':
        // Test cloning a handle with blocking query parameter
        $url = 'http://localhost/example.html?param=blocked_query_param';
        $ch1 = curl_init($url);

        $ch2 = curl_copy_handle($ch1);

        curl_ret_transfer_exec($ch2);
        curl_close($ch1);
        break;
    case 'clone_with_stream_no_block':
        // Test technical limitation: when a handle with stream filter is cloned,
        // the filter is removed (because shared streams can't track per-handle data)
        // so blocking stops working
        $url = 'http://localhost/example.html';
        $ch1 = curl_init($url);
        curl_setopt($ch1, CURLOPT_POST, true);

        // Use a stream with blocking content
        $fp = fopen('php://temp', 'r+');
        $content = json_encode(array('key' => 'blocked_request_body'));
        fwrite($fp, $content);
        $size = ftell($fp);
        rewind($fp);
        curl_setopt($ch1, CURLOPT_INFILE, $fp);
        curl_setopt($ch1, CURLOPT_INFILESIZE, $size);
        curl_setopt($ch1, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));

        // Clone the handle - this removes the stream filter
        $ch2 = curl_copy_handle($ch1);

        // Execute the cloned handle - should NOT block due to technical limitation
        // (filter was removed, body becomes noop)
        curl_ret_transfer_exec($ch2);
        fclose($fp);
        curl_close($ch1);
        break;
    case 'reset_clears_blocking':
        $url = 'http://localhost/example.html';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array('key' => 'blocked_request_body')));

        curl_reset($ch);

        // now set URL again and execute - should NOT block since context was cleared
        curl_setopt($ch, CURLOPT_URL, 'http://localhost/example.html');
        curl_ret_transfer_exec($ch);
        break;
    case 'request_body_under_limit_blocks':
        // Test that a request body just under the limit can trigger blocking
        $url = endpoint_url('echo');
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, generate_body_under_limit());
        curl_ret_transfer_exec($ch);
        break;
    case 'request_body_over_limit_no_block':
        // Test that a request body over the limit does NOT trigger blocking
        $url = endpoint_url('echo');
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, generate_body_over_limit());
        curl_ret_transfer_exec($ch);
        break;
    case 'request_body_infile_under_limit_blocks':
        // Test CURLOPT_INFILE with body just under limit
        $url = endpoint_url('echo');
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Expect:'));

        $body = generate_body_under_limit();
        $tmpfile = tempnam(sys_get_temp_dir(), 'curl_infile_');
        file_put_contents($tmpfile, $body);
        $fp = fopen($tmpfile, 'rb');
        curl_setopt($ch, CURLOPT_INFILE, $fp);
        curl_setopt($ch, CURLOPT_PUT, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_INFILESIZE, strlen($body));
        curl_ret_transfer_exec($ch);
        fclose($fp);
        unlink($tmpfile);
        break;
    case 'request_body_infile_over_limit_no_block':
        // Test CURLOPT_INFILE with body over limit
        $url = endpoint_url('echo');
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Expect:'));

        $body = generate_body_over_limit();
        $tmpfile = tempnam(sys_get_temp_dir(), 'curl_infile_');
        file_put_contents($tmpfile, $body);
        $fp = fopen($tmpfile, 'rb');
        curl_setopt($ch, CURLOPT_INFILE, $fp);
        curl_setopt($ch, CURLOPT_PUT, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_INFILESIZE, strlen($body));
        curl_ret_transfer_exec($ch);
        fclose($fp);
        unlink($tmpfile);
        break;
    case 'request_body_readfunction_under_limit_blocks':
        $url = endpoint_url('echo');
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Expect:'));

        $data = generate_body_under_limit();
        curl_setopt($ch, CURLOPT_UPLOAD, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_INFILESIZE, strlen($data));

        $readPos = 0;
        curl_setopt($ch, CURLOPT_READFUNCTION, function ($ch, $fd, $length) use ($data, &$readPos) {
            if ($readPos >= strlen($data)) {
                return '';
            }
            $chunk = substr($data, $readPos, $length);
            $readPos += strlen($chunk);
            return $chunk;
        });
        curl_ret_transfer_exec($ch);
        break;
    case 'request_body_readfunction_over_limit_no_block':
        $url = endpoint_url('echo');
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Expect:'));

        $data = generate_body_over_limit();
        curl_setopt($ch, CURLOPT_UPLOAD, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_INFILESIZE, strlen($data));

        $readPos = 0;
        curl_setopt($ch, CURLOPT_READFUNCTION, function ($ch, $fd, $length) use ($data, &$readPos) {
            if ($readPos >= strlen($data)) {
                return '';
            }
            $chunk = substr($data, $readPos, $length);
            $readPos += strlen($chunk);
            return $chunk;
        });
        curl_ret_transfer_exec($ch);
        break;
    case 'request_body_array_under_limit_blocks':
        $url = endpoint_url('echo');
        $ch = curl_init($url);

        $limit = 524288;
        $blocking_pattern = 'blocked_request_body';
        // account for multipart overhead
        $padding_size = $limit - strlen($blocking_pattern) - 500;
        curl_setopt($ch, CURLOPT_POSTFIELDS, array(
            'key' => $blocking_pattern,
            'padding' => str_repeat('a', $padding_size)
        ));
        curl_ret_transfer_exec($ch);
        break;
    case 'request_body_array_over_limit_no_block':
        $url = endpoint_url('echo');
        $ch = curl_init($url);

        $limit = 524288;
        $padding_size = $limit + 5000;
        curl_setopt($ch, CURLOPT_POSTFIELDS, array(
            'padding' => str_repeat('a', $padding_size),
            'key' => 'blocked_request_body'
        ));
        curl_ret_transfer_exec($ch);
        break;
    case 'response_body_under_limit_blocks':
        $url = endpoint_url('large_response_under_limit');
        $ch = curl_init($url);
        curl_ret_transfer_exec($ch);
        break;
    case 'response_body_over_limit_no_block':
        $url = endpoint_url('large_response_over_limit');
        $ch = curl_init($url);
        curl_ret_transfer_exec($ch);
        break;
    case 'response_body_file_under_limit_blocks':
        $url = endpoint_url('large_response_under_limit');
        $ch = curl_init($url);
        $tmpfile = tempnam(sys_get_temp_dir(), 'curl_response_');
        $fp = fopen($tmpfile, 'wb');
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_exec($ch);
        fclose($fp);
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        } else {
            echo "Response written to file\n";
        }
        curl_close($ch);
        unlink($tmpfile);
        break;
    case 'response_body_file_over_limit_no_block':
        $url = endpoint_url('large_response_over_limit');
        $ch = curl_init($url);
        $tmpfile = tempnam(sys_get_temp_dir(), 'curl_response_');
        $fp = fopen($tmpfile, 'wb');
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_exec($ch);
        fclose($fp);
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        } else {
            echo "Response written to file\n";
        }
        curl_close($ch);
        unlink($tmpfile);
        break;
    case 'response_body_writefunction_under_limit_blocks':
        $url = endpoint_url('large_response_under_limit');
        $ch = curl_init($url);
        $responseData = '';
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$responseData) {
            $responseData .= $data;
            return strlen($data);
        });
        curl_exec($ch);
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        } else {
            echo "Response captured via writefunction\n";
        }
        curl_close($ch);
        break;
    case 'response_body_writefunction_over_limit_no_block':
        $url = endpoint_url('large_response_over_limit');
        $ch = curl_init($url);
        $responseData = '';
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$responseData) {
            $responseData .= $data;
            return strlen($data);
        });
        curl_exec($ch);
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        } else {
            echo "Response captured via writefunction\n";
        }
        curl_close($ch);
        break;
    case 'multiple_downstream_with_body_blocks':
        // first downstream request with safe body - body analyzed, no block
        $url1 = endpoint_url('echo');
        $ch1 = curl_init($url1);
        curl_setopt($ch1, CURLOPT_POST, true);
        curl_setopt($ch1, CURLOPT_POSTFIELDS, json_encode(['key' => 'safe_value']));
        curl_setopt($ch1, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_ret_transfer_exec($ch1); // Closes handle automatically

        // second downstream request with blocking body - body NOT analyzed (limit=1 reached)
        // If this body were analyzed, it would trigger blocking and return 403
        $url2 = endpoint_url('echo');
        $ch2 = curl_init($url2);
        curl_setopt($ch2, CURLOPT_POST, true);
        curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode(['key' => 'blocked_request_body']));
        curl_setopt($ch2, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_ret_transfer_exec($ch2); // Closes handle automatically

        echo "Both requests completed\n";
        break;
    case 'forward':
    case 'forward_auth':
    case 'forward_postredir':
    case 'forward_post':
    case 'forward_post_postredir':
    case 'forward_put':
    case 'forward_patch':
        $code = intval($_GET['code'] ?? '302');
        $hops = intval($_GET['hops'] ?? '1');

        // Support path_pattern or final_path
        if (isset($_GET['path_pattern'])) {
            $path_pattern = $_GET['path_pattern'];
            $url = endpoint_url('forward') . "&code=$code&hops=$hops&path_pattern=$path_pattern";
        } else {
            $final_path = $_GET['final_path'] ?? '/example.html';
            $url = endpoint_url('forward') . "&code=$code&hops=$hops&final_path=$final_path";
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        if ($variant === 'forward_post' || $variant === 'forward_post_postredir') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, 'test=data');
        }

        if ($variant === 'forward_put') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        }

        if ($variant === 'forward_patch') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        }

        if ($variant === 'forward_auth') {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer test-token']);
            curl_setopt($ch, CURLOPT_COOKIE, 'session=test-session');
            curl_setopt($ch, CURLOPT_UNRESTRICTED_AUTH, true);
        }

        if ($variant === 'forward_postredir') {
            curl_setopt($ch, CURLOPT_POSTREDIR, true);
        }
        if ($variant === 'forward_post_postredir') {
            $postredir = intval($_GET['postredir'] ?? '7');
            curl_setopt($ch, CURLOPT_POSTREDIR, $postredir);
        }

        curl_ret_transfer_exec($ch);
        break;
    default:
        http_response_code(500);
        die("Unknown variant: $variant\n");
}
