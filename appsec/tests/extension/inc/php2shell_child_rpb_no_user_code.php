<?php
// Child script for php2shell_mitigation_request_parse_body_no_user_code.phpt
// (PHP 8.4+). The request is a PUT, so request startup leaves the body alone
// and request_parse_body() parses it. P2S_MODE selects the user code that
// stock PHP would run from inside that call:
//   warnings  an error handler, and request_parse_body() options that raise
//             two warnings: an invalid quantity (upload_max_filesize) and
//             max_input_vars=2, exceeded by the body. After the call, a
//             trigger_error() checks that the handler is back in place;
//   session   a user session save handler, with a multipart body carrying
//             PHP_SESSION_UPLOAD_PROGRESS and a session cookie: the session
//             upload-progress callback opens the session through it.
// Prints what ran, after the call.
class P2sHandler implements SessionHandlerInterface {
    function open($path, $name): bool { $GLOBALS['p2s_open'] = true; return true; }
    function close(): bool { return true; }
    function read($id): string|false { return ''; }
    function write($id, $data): bool {
        if (str_contains($data, 'upload_progress_')) {
            $GLOBALS['p2s_progress_written'] = true;
        }
        return true;
    }
    function destroy($id): bool { return true; }
    function gc($max): int|false { return 0; }
}

$calls = [];
if (getenv('P2S_MODE') === 'session') {
    session_set_save_handler(new P2sHandler, true);
    [$post, $files] = request_parse_body();
} else {
    set_error_handler(function ($no, $str) use (&$calls) {
        $calls[] = $str;
        return true;
    });
    [$post, $files] = request_parse_body([
        'upload_max_filesize' => '1bogus',
        'max_input_vars' => 2,
    ]);
    trigger_error('after', E_USER_NOTICE);
    restore_error_handler();
}
echo "SCRIPT RAN post_fields=", count($post), " files=", count($files),
    " handler_calls=", count($calls),
    " session_opened=", var_export(!empty($GLOBALS['p2s_open']), true),
    " progress_written=", var_export(!empty($GLOBALS['p2s_progress_written']), true),
    "\n";
foreach ($calls as $c) {
    echo "HANDLER $c\n";
}
