--TEST--
request_shutdown — user_request variant with stream (error conditions)
--INI--
expose_php=0
extension=ddtrace.so
datadog.appsec.enabled=1
datadog.appsec.log_level=info
datadog.appsec.cli_start_on_rinit=false
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
use function DDTrace\UserRequest\{notify_start,notify_commit};
use function DDTrace\start_span;
use function DDTrace\close_span;

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()])),
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()])),
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()])),
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()])),
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()])),
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()])),
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()])),
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()])),
]);

function test($stream) {
    global $helper;
    $span = start_span();
    $res = @notify_start($span, array(
        '_SERVER' => [
            'REMOTE_ADDR' => '1.2.3.4',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'REQUEST_METHOD' => 'GET',
            'SERVER_NAME' => 'example.com',
            'SERVER_PORT' => 80,
            'HTTP_HOST' => 'example2.com',
            'HTTP_CONTENT_TYPE' => 'application/xml',
        ],
    ));
    var_dump($res);

    $res = notify_commit($span, 200, array(
        'Content-Type' => ['text/xml'],
    ), $stream);
    var_dump($res);

    close_span(100.0);
    $helper->get_commands();
}

// tell() never fails in a userland stream, so we don't test it
// In particular, PHP calls the tell method right after seeking so if we force
// stream_tell() to fail, it's the stream seek operation that will fail

echo "Non-seekable stream scenario:\n";
class NonSeekableStream {
    public $context;
    public function stream_open($path, $mode, $options, &$opened_path) {
        return true;
    }
}
stream_wrapper_register('nonseekable', 'NonSeekableStream');
$s = fopen('nonseekable://resource', 'r+');
fseek($s, 0, SEEK_SET); // so PHP_STREAM_FLAG_NO_SEEK is set
test($s);



echo "\n\nFail to seek to the end:\n";
class FailSeekToEndStream {
    public $context;
    public function stream_open($path, $mode, $options, &$opened_path) {
        return true;
    }

    public function stream_tell() {
        return -1;
    }

    public function stream_seek($offset, $whence = SEEK_SET) {
        if ($whence == SEEK_END) {
            return false;
        }
        return true;
    }
}
stream_wrapper_register('failseektoend', 'FailSeekToEndStream');
test(fopen('failseektoend://resource', 'r+'));


echo "\n\nApparent shrinkage after seeking to the end:\n";
class ApparentShrinkageStream {
    public $context;
    private $pos = 0;
    public function stream_open($path, $mode, $options, &$opened_path) {
        return true;
    }

    public function stream_tell() {
        return $this->pos;
    }

    public function stream_seek($offset, $whence = SEEK_SET) {
        if ($whence == SEEK_SET) {
            $this->pos = $offset;
            return true;
        } else if ($whence == SEEK_END) {
            $this->pos -= 1; // actually go back when seeking to the end
            return true;
        }
        return true;
    }
}
stream_wrapper_register('apparentshrinkage', 'ApparentShrinkageStream');
$stream = fopen('apparentshrinkage://resource', 'r+');
fseek($stream, 10, SEEK_SET);
test($stream);


echo "\n\nFail rewind:\n";
class FailRewindStream {
    public $context;
    private $pos = 0;
    public function stream_open($path, $mode, $options, &$opened_path) {
        return true;
    }

    public function stream_tell() {
        return $this->pos;
    }

    public function stream_seek($offset, $whence = SEEK_SET) {
        if ($whence == SEEK_SET) {
            if ($offset == 0) {
                return false;
            }
            $this->pos = $offset;
            return true;
        } else if ($whence == SEEK_END) {
            $this->pos = 10;
            return true;
        }
        return true;
    }

    public function stream_eof() {
        return true;
    }
}
stream_wrapper_register('failrewind', 'FailRewindStream');
$stream = fopen('failrewind://resource', 'r+');
test($stream);


echo "\n\nToo large a body:\n";
class BodyTooLargeStream {
    public $context;
    private $pos = 0;
    public function stream_open($path, $mode, $options, &$opened_path) {
        return true;
    }

    public function stream_tell() {
        return $this->pos;
    }

    public function stream_seek($offset, $whence = SEEK_SET) {
        if ($whence == SEEK_SET) {
            $this->pos = $offset;
            return true;
        } else if ($whence == SEEK_END) {
            $this->pos = 10000000;
            return true;
        }
        return true;
    }

    public function stream_eof() {
        return true;
    }
}
stream_wrapper_register('bodytoolarge', 'BodyTooLargeStream');
$stream = fopen('bodytoolarge://resource', 'r+');
test($stream);


echo "\n\nRead failure:\n";
class ReadFailureStream {
    public $context;
    private $pos = 0;
    public function stream_open($path, $mode, $options, &$opened_path) {
        return true;
    }

    public function stream_tell() {
        return $this->pos;
    }

    public function stream_seek($offset, $whence = SEEK_SET) {
        if ($whence == SEEK_SET) {
            $this->pos = $offset;
            return true;
        } else if ($whence == SEEK_END) {
            $this->pos = 100;
            return true;
        }
        return true;
    }

    public function stream_eof() {
        return $this->pos === 100;
    }

    public function stream_read(int $count) {
        return false;
    }


}
stream_wrapper_register('readfailure', 'ReadFailureStream');
$stream = fopen('readfailure://resource', 'r+');
test($stream);


echo "\n\nRead too little data:\n";
class TooLittleDataStream {
    public $context;
    private $pos = 0;
    public function stream_open($path, $mode, $options, &$opened_path) {
        return true;
    }

    public function stream_tell() {
        return $this->pos;
    }

    public function stream_seek($offset, $whence = SEEK_SET) {
        if ($whence == SEEK_SET) {
            $this->pos = $offset;
            return true;
        } else if ($whence == SEEK_END) {
            $this->pos = 100;
            return true;
        }
        return true;
    }

    public function stream_eof() {
        return $this->pos > 50;
    }

    public function stream_read(int $count) {
        $size = min(50 - $this->pos, $count);
        if ($size == 0) {
            return '';
        }
        $this->pos += $count;
        return str_repeat('a', $size);
    }


}
stream_wrapper_register('toolittledata', 'TooLittleDataStream');
$stream = fopen('toolittledata://resource', 'r+');
test($stream);


echo "\n\nFinal seek fails:\n";
class FinalSeekFailsStream {
    public $context;
    private $pos = 0;
    private $hasRead = false;
    public function stream_open($path, $mode, $options, &$opened_path) {
        return true;
    }

    public function stream_tell() {
        return $this->pos;
    }

    public function stream_seek($offset, $whence = SEEK_SET) {
        if ($this->hasRead) {
            return false;
        }
        if ($whence == SEEK_SET) {
            $this->pos = $offset;
            return true;
        } else if ($whence == SEEK_END) {
            $this->pos = 100;
            return true;
        }
        return true;
    }

    public function stream_eof() {
        return $this->pos >= 100;
    }

    public function stream_read(int $count) {
        $this->hasRead = true;
        $size = min(100 - $this->pos, $count);
        if ($size == 0) {
            return '';
        }
        $this->pos += $count;
        return str_repeat('a', $size);
    }


}
stream_wrapper_register('finalseekfails', 'FinalSeekFailsStream');
$stream = fopen('finalseekfails://resource', 'r+');
test($stream);

?>
--EXPECTF--
Non-seekable stream scenario:

Warning: fseek(): %s does not support seeking in %s on line %d
NULL

Notice: DDTrace\UserRequest\notify_commit(): [ddappsec] Response body entity is a stream, but it is not seekable; ignoring in %s on line %d

Notice: DDTrace\UserRequest\notify_commit(): [ddappsec] request_shutdown succeed and told to dd_success in %s on line %d
NULL


Fail to seek to the end:
NULL

Notice: DDTrace\UserRequest\notify_commit(): [ddappsec] Failed to seek to end of response body entity stream; ignoring in %s on line %d

Notice: DDTrace\UserRequest\notify_commit(): [ddappsec] request_shutdown succeed and told to dd_success in %s on line %d
NULL


Apparent shrinkage after seeking to the end:
NULL

Warning: DDTrace\UserRequest\notify_commit(): [ddappsec] Response body entity stream shrank after seek (10 to 9); response stream is likely corrupted in %s on line %d

Notice: DDTrace\UserRequest\notify_commit(): [ddappsec] request_shutdown succeed and told to dd_success in %s on line %d
NULL


Fail rewind:
NULL

Warning: DDTrace\UserRequest\notify_commit(): [ddappsec] Failed to rewind response body entity stream; response stream is likely corrupted in %s on line %d

Notice: DDTrace\UserRequest\notify_commit(): [ddappsec] request_shutdown succeed and told to dd_success in %s on line %d
NULL


Too large a body:
NULL

Notice: DDTrace\UserRequest\notify_commit(): [ddappsec] Response body entity is larger than 524288 bytes (got 10000000); ignoring in %s on line %d

Notice: DDTrace\UserRequest\notify_commit(): [ddappsec] request_shutdown succeed and told to dd_success in %s on line %d
NULL


Read failure:
NULL

Warning: DDTrace\UserRequest\notify_commit(): [ddappsec] Failed to read response body entity stream; response stream is likely corrupted in %s on line %d

Notice: DDTrace\UserRequest\notify_commit(): [ddappsec] request_shutdown succeed and told to dd_success in %s on line %d
NULL


Read too little data:
NULL

Notice: DDTrace\UserRequest\notify_commit(): [ddappsec] Read fewer data than expected (expected 100, got 50) in %s on line %d

Notice: DDTrace\UserRequest\notify_commit(): [ddappsec] request_shutdown succeed and told to dd_success in %s on line %d
NULL


Final seek fails:
NULL

Warning: DDTrace\UserRequest\notify_commit(): [ddappsec] Failed to rewind response body entity stream; response stream is likely corrupted in %s on line %d

Notice: DDTrace\UserRequest\notify_commit(): [ddappsec] request_shutdown succeed and told to dd_success in %s on line %d
NULL
