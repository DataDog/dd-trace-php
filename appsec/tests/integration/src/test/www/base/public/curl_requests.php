<?php
$variant = $_GET['variant'] ?? 'simple';

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
    default:
        http_response_code(500);
        die("Unknown variant: $variant\n");
}
