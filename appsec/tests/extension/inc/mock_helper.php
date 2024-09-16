<?php

define('TEMP_DIR', '/tmp');
define('STDOUT_PATH', TEMP_DIR . "/mock_helper.stdout.log");
define('STDERR_PATH', TEMP_DIR . "/mock_helper.stderr.log");

#[AllowDynamicProperties]
class Helper {
    private $descriptors;
    private $process;
    private $mock_helper_path;
    private $lock_path;

    function __construct($opts = array()) {
        $runtime_path = key_exists('runtime_path', $opts) ? $opts['runtime_path'] : ini_get('datadog.appsec.helper_runtime_path');
        $sock_path = $runtime_path . "/ddappsec_" . phpversion('ddappsec') . "_" . getmyuid() . ".sock";
        $received_pipe = key_exists('received_pipe', $opts) ? $opts['received_pipe'] : true;
        $this->mock_helper_path = key_exists('mock_helper_path', $opts) ? $opts['mock_helper_path'] : getenv('MOCK_HELPER_BINARY');
        $this->lock_path = $runtime_path . "/ddappsec_" . phpversion('ddappsec') . ".lock";
        $descriptors = array(
            0 => array("pipe", "r"),
            1 => array("file", STDOUT_PATH, "w+"),
            2 => array("file", STDERR_PATH, "w+"),
            3 => self::listen($sock_path),
        );
        if ($received_pipe) {
            $descriptors[] = array('pipe', 'w');
        }
        $this->descriptors = $descriptors;
    }

    static function listen($path) {
        if (file_exists($path)) {
            unlink($path);
        }
        $s = stream_socket_server("unix://$path", $errno, $errstr);
        if (!$s) {
            die("stream_socket_server failed: " . $s);
        }
        return $s;
    }

    static function createRun($responses, $opts = array()) {
        $continuous = key_exists('continuous', $opts) && $opts['continuous'];
        $helper = new static($opts);
        $helper->run($responses, $continuous);
        return $helper;
    }

    static function createInitedRun($responses, $opts = array()) {
        $empty_obj = new ArrayObject();
        $responses = array_merge([
            response_list(
                response_client_init(['ok', phpversion('ddappsec'),[],$empty_obj,$empty_obj])
        )], $responses);
        return self::createRun($responses, $opts);
    }

    function run($responses, $continuous = false) {
        $esc_resp = "";
        foreach ($responses as $response) {
            $esc_resp .= " " . escapeshellarg(json_encode($response));
        }

        $cmd = "'{$this->mock_helper_path}' --lock {$this->lock_path}" .
            ($continuous ? ' --continuous ' : '') . ' ' . $esc_resp;

        $this->process = proc_open($cmd, $this->descriptors, $pipes, null,
            array('GLOG_logtostderr' => '1', 'GLOG_v' => '1'));
        $this->command_pipe = end($pipes);

        if (!is_resource($this->process)) {
            die("error starting helper process");
        }
        $this->ensure_running();

        $this->wait_for_daemon_ready();
    }

    function __destruct() {
        if ($this->process) {
            $this->kill();
        }
    }

    function kill() {
        if (!$this->process) {
            die("Error: no running daemon");
        }

        $count = 0;
        while($count < 20) {
            $res = proc_get_status($this->process);
            if ($res['running']) {
                usleep(0.1 * 1000 * 1000);
            } else {
                break;
            }
            $count++;
        }
        if ($res['running']) {
            $pid=$res['pid'];
            exec('kill -15 ' . $res['pid']); // SIGTERM
            $this->get_output();
            die("Killed server");
        } else {
            $code = $res['exitcode'];
            if ($code != 0) {
                $this->get_output();
                die("Exit code $code on daemon process");
            }
        }

        $this->process = false;
    }

    function get_output() {
        echo file_get_contents(STDERR_PATH);
        echo file_get_contents(STDOUT_PATH);
    }

    function get_commands() {
        stream_set_blocking($this->command_pipe, false);
        $readfds = array($this->command_pipe);
        $writefs = NULL;
        $exceptfs = NULL;
        $ret = stream_select($readfds, $writefs, $exceptfs, 0, 1000000); // 1 s
        if ($ret == 0) {
            echo "timeout\n";
        } else if ($ret == 1) {
            $data = @stream_get_contents($this->command_pipe);
            if (!$data) {
                return NULL;
            }
            $msgs = explode("\0", rtrim($data, "\0"));
            return array_map(function ($it) {
                return json_decode($it, true, 32, JSON_BIGINT_AS_STRING);
            }, $msgs);
        }
    }

    function print_commands($sort = true) {
        $commands = $this->get_commands();
        if (!is_array($commands)) {
            echo "No commands\n";
            var_dump($commands);
            return;
        }
        if ($sort) {
            self::ksort_recurse($commands);
        }
        print_r($commands);
    }

    function get_command($command) {
            $commands = $this->get_commands();
            foreach($commands as $c) {
                if ($c[0] == $command) {
                    return $c;
                }
            }
            return [];
        }

    static function ksort_recurse(&$arr) {
        if (!is_array($arr)) {
            return;
        }
        ksort($arr);
        foreach ($arr as $k => &$v) {
            self::ksort_recurse($v);
        }
    }

    function finished_with_commands() {
        fclose($this->command_pipe);
    }

    function ensure_running() {

        $res = proc_get_status($this->process);

        if ($res['running'] == false) {
            $ret = $res['exitcode'];
            $cmd = $res['command'];
            $pid = $res['pid'];
            echo "Error: daemon $pid has already stoped, exitcode: $ret, cmd: $cmd\n";
         echo "Daemon's stdout: ", file_get_contents('/tmp/daemon.stdout.txt'), "\n";
            echo "Daemon's stderr: ", file_get_contents('/tmp/daemon.stderr.txt'), "\n";
            die;
        }

    }

    function wait_for_daemon_ready() {
        $handle = fopen($this->lock_path, "w+");
        $attempts = 10;
        while (flock($handle, LOCK_EX | LOCK_NB)) {
            flock($handle, LOCK_UN);
            if ($attempts-- == 0) {
                echo "Timeout waiting for the daemon to start\n";
                echo "Daemon's stdout: ", file_get_contents(STDOUT_PATH), "\n";
                echo "Daemon's stderr: ", file_get_contents(STDERR_PATH), "\n";
                    fclose($handle);
                die;
            }
            usleep(100000);
        }
        fclose($handle);
    }
};

function response($type, $message) {
//     var_dump([$type, $message]);
    return [$type, $message];
}

function array_override($default, $override)
{
    foreach($default as $k => $v)
    {
        if(isset($override[$k])) {
            $default[$k] = $override[$k];
        }
    }
    return $default;
}

function response_client_init($message) {
    return response("client_init", $message);
}

function response_request_init($message, $mergeWithEmpty = true) {
    if ($mergeWithEmpty) {
        $message = array_override([
            [//Actions
                [ //First action
                    '', //verdict
                    [] // parameters
                ]
            ],
            [], //triggers
            false //force_keep
        ], $message);
    }
    return response("request_init", $message);
}

function response_request_exec($message, $mergeWithEmpty = true) {
    if ($mergeWithEmpty) {
        $message = array_override([
            [//Actions
                [ //First action
                    '', //verdict
                    [] // parameters
                ]
            ],
            [],
            false
        ], $message);
    }
    return response("request_exec", $message);
}

function response_request_shutdown($message, $mergeWithEmpty = true) {
    if ($mergeWithEmpty) {
        $message = array_override([
            [//Actions
                [ //First action
                    '', //verdict
                    [] // parameters
                ]
            ],
            [],
            false,
            [],
            [],
            []
        ], $message);
    }
    return response("request_shutdown", $message);
}

function response_list(...$responses) {
    return $responses;
}

function response_config_features($status) {
    return response("config_features", [$status]);
}

function response_config_sync() {
    return response("config_sync", []);
}


// vim: set et sw=4 ts=4:
?>
