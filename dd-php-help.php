#!/usr/bin/env php
<?php

const INI_CONF = 'Scan this dir for additional .ini files';
const EXTENSION_DIR = 'extension_dir';
const THREAD_SAFETY = 'Thread Safety';
const PHP_API = 'PHP API';
const IS_DEBUG = 'Debug Build';

// Options
const OPT_HELP = 'help';
const OPT_INSTALL_DIR = 'install-dir';
const OPT_PHP_BIN = 'php-bin';
const OPT_TRACER_FILE = 'tracer-file';
const OPT_TRACER_URL = 'tracer-url';
const OPT_TRACER_VERSION = 'tracer-version';
const OPT_UNINSTALL = 'uninstall';
const OPT_VERIFY = 'verify';

class Binary
{

    public $command;
    public $fullPath;

    public function __construct($command, $fullPath)
    {
        $this->command = $command;
        $this->fullPath = $fullPath;
    }

    public function __toString()
    {
        return $this->command === $this->fullPath ? $this->fullPath : ($this->command . ' (' . $this->fullPath . ')');
    }
}

/**
 * @param string[] $argv
 * @return void
 */
function main($argv)
{
    $command = $argv[1];
    $options = parse_validate_user_options();

    if ($command === 'verify') {
        verify($options);
    } elseif ($command === 'dump') {
        dump($options);
    } else {
        print_help_and_exit(1);
    }
}

function print_help_and_exit($statudCode = 0)
{
    echo <<<EOD
Usage:
    php dd-php-help.php verify
    php dd-php-help.php dump

Options:
    -h, --help                  Print this help text and exit

EOD;
    exit($statudCode);
}

function dump($options)
{
    // Picking the right binary to run checks
    $binary = pick_binary_interactive(search_php_binaries());
    $file = sys_get_temp_dir() . '/dd-php-help-' . (new DateTime())->format('Y-m-d-H-i') . '.dump';
    echo "Dumping data for binary: $binary to '$file'\n";
    write_data_section('Binary', $binary, $file);

    $versionFragments = [];
    execute_or_exit('Error while reading version', $binary->fullPath . ' -v', $versionFragments);
    write_data_section('PHP version', implode("\n", $versionFragments), $file);

    $iniFragments = [];
    execute_or_exit('Error while reading ini settings', $binary->fullPath . ' -i', $iniFragments);
    $iniFragments = sanitize_ini($iniFragments);
    write_data_section('INI settings', implode("\n", $iniFragments), $file);

    $modules = read_php_modules($binary);
    $modulesString = "";
    foreach ($modules as $moduleType => $moduleNames) {
        $modulesString .= "[$moduleType]\n";
        foreach ($moduleNames as $name => $version) {
            $modulesString .= "$name:$version\n";
        }
    }
    write_data_section('Modules', $modulesString, $file);

    echo "DONE: debug data dumped to '$file'\n";
    echo "Make sure to inspect the file before sending it to Datadog for sensitive data.\n";
}

function verify($options)
{
    // Picking the right binary to run checks
    $binary = pick_binary_interactive(search_php_binaries());
    echo "Verifying binary: $binary\n";

    check_trace_installed_or_exit($binary);
    check_request_init_hook_or_exit($binary);
    check_agent_connectivity($binary);

    echo "SUCCESS: All checks passed\n";
}

function write_data_section($title, $content, $file)
{
    file_put_contents($file, "----------------- start: $title\n$content\n----------------- end: $title\n", FILE_APPEND);
}

/**
 * @param Binary $binary
 */
function check_agent_connectivity(Binary $binary)
{
    render_check_before($binary, "checking agent connectivity");

    /**
     * @param string $url
     * @return bool
     */
    function connect_to_agent_or_exit($url, $message)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, "[]");
        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            array(
                'Content-Type: application/json',
                'X-Datadog-Trace-Count: 0',
            )
        );
        $response = curl_exec($ch);
        if (false === $response) {

            render_check_failure_and_exit(
                "Unable to send traces to a datadog agent.\n"
                    . "$message\n"
                    . "Curl output: " . curl_error($ch) . "\n"
            );
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $success = $httpCode >= 200 && $httpCode < 300;
        curl_close($ch);

        if (!$success) {
            render_check_failure_and_exit(
                "Unable to send traces to a datadog agent.?\n"
                    . "$message\n"
                    . "Response code: $httpCode, body:\n$response"
            );
        }
    }

    if ($ddTraceAgentUrlEnv = read_raw_env($binary, 'DD_TRACE_AGENT_URL')) {
        connect_to_agent_or_exit($ddTraceAgentUrlEnv, "Url '$ddTraceAgentUrlEnv' from environment variable 'DD_TRACE_AGENT_URL'");
    } elseif ($ddTraceAgentUrlIni = read_raw_ini_setting($binary, 'datadog.trace.agent_url')) {
        connect_to_agent_or_exit($ddTraceAgentUrlIni, "Url '$ddTraceAgentUrlIni' from INI setting 'datadog.trace.agent_url'");
    }

    $host = 'localhost';
    $hostFrom = 'default value';
    $port = '8126';
    $portFrom = 'default value';

    if ($hostFromEnv = read_raw_env($binary, 'DD_AGENT_HOST')) {
        $host = $hostFromEnv;
        $hostFrom = "environment variable 'DD_AGENT_HOST'";
    } elseif ($hostFromIni = read_raw_ini_setting($binary, 'datadog.agent_host')) {
        $host = $hostFromIni;
        $hostFrom = "INI setting 'datadog.agent_host'";
    }

    if ($portFromEnv = read_raw_env($binary, 'DD_TRACE_AGENT_PORT')) {
        $port = $portFromEnv;
        $portFrom = "environment variable 'DD_TRACE_AGENT_PORT'";
    } elseif ($portFromIni = read_raw_ini_setting($binary, 'datadog.trace.agent_port')) {
        $port = $portFromIni;
        $portFrom = "INI setting 'datadog.trace.agent_port'";
    }

    connect_to_agent_or_exit("$host:$port/v0.4/traces", "Host '$host' from $hostFrom, Port '$port' from $portFrom");

    render_check_success();
}

/**
 * @param Binary $binary
 */
function check_trace_installed_or_exit(Binary $binary)
{
    render_check_before($binary, "dd-trace-php installed for binary");
    $phpModules = read_php_modules($binary);
    array_key_exists('ddtrace', $phpModules['zend'])
        ? render_check_success()
        : render_check_failure_and_exit(
            <<<EOD
ddtrace module is not installed to binary '$binary'.
Follow installation instructions at: https://docs.datadoghq.com/tracing/setup_overview/setup/php/?tab=containers#installation-and-getting-started
EOD
        );
}

/**
 * @param Binary $binary
 */
function check_request_init_hook_or_exit(Binary $binary)
{
    render_check_before($binary, "dd-trace-php request init hook correctly configured");
    $iniValue = read_raw_ini_setting($binary, 'datadog.trace.request_init_hook');

    // Ini value should be set
    if (null === $iniValue) {
        render_check_failure_and_exit(
            <<<EOD
required setting datadog.trace.request_init_hook is not configured '$binary'.
Follow installation instructions at: https://docs.datadoghq.com/tracing/setup_overview/setup/php/?tab=containers#installation-and-getting-started
EOD
        );
    }

    // Request init hook should be allowed by open_basedir, if configured
    $openBaseDir = read_raw_ini_setting($binary, 'open_basedir');
    if ($openBaseDir) {
        $isBlocked = true;
        $ddWrapFolder = rtrim(dirname($iniValue), '/');
        $allowedPaths = array_map('trim', explode(',', $openBaseDir));
        foreach ($allowedPaths as $allowedPath) {
            $normalizedPath = rtrim(dirname($allowedPath), '/');
            if ($ddWrapFolder === $normalizedPath) {
                $isBlocked = false;
                break;
            }
        }

        if ($isBlocked) {
            render_check_failure_and_exit(
                <<<EOD
Directive open_basedir blocks access to folder '$ddWrapFolder' for '$binary'.
Add '$ddWrapFolder' to the list of allowed directories in the php.ini file.
EOD
            );
        }
    }

    // Request init hook should be readable
    if (!is_readable($iniValue)) {
        render_check_failure_and_exit(
            <<<EOD
Configured datadog.trace.request_init_hook '$iniValue' is not readable by '$binary'.
The file must exist and be readable by user that runs the process '$binary'.
Follow installation instructions at: https://docs.datadoghq.com/tracing/setup_overview/setup/php/?tab=containers#installation-and-getting-started
EOD
        );
    }

    render_check_success();
}

function render_check_before(Binary $binary, $title)
{
    echo "Check: $title '$binary'...";
}

function render_check_failure_and_exit($message)
{
    echo "ERROR\n";
    print_error_and_exit($message);
}

function render_check_success()
{
    echo "OK\n";
}

function read_raw_env(Binary $binary, $name)
{
    // TODO: for php-fpm, this would mostly work for php CLI only, since envs can be overwritten in www.conf
    return read_raw_ini_setting($binary, "\$_ENV['$name']");
}

/**
 * @param Binary $binary
 * @param string $name
 * @return string|null
 */
function read_raw_ini_setting(Binary  $binary, $name)
{
    $output = [];
    execute_or_exit(
        "Cannot read value of ini setting '$name'",
        $binary->fullPath . " -i",
        $output
    );

    $found = array_filter($output, function ($line) use ($name) {
        return strpos($line, "$name => ") === 0;
    });

    if (count($found) === 0) {
        return null;
    }
    // TODO: we are not handling possible repetitions, if they are possible
    $rawValue = reset($found);
    $parts = array_map('trim', explode('=>', $rawValue));
    $value = end($parts);
    return $value === 'no value' ? null : trim($value);
}

/**
 * Returns an array similar to the following example
 * [
 *      "php" => [
 *          ["php_module_1" => "1.1.0"],
 *          ["php_module_2" => "1.3.0"],
 *          ],
 *      "zend" => [
 *          ["zend_module_1" => "1.0.0"],
 *      ],
 * ]
 *
 * @param Binary $phpBinary
 * @return array
 */
function read_php_modules(Binary $phpBinary)
{
    $modulesFragments = [];
    execute_or_exit('Error while reading modules', $phpBinary->command . ' -m', $modulesFragments);

    $modules = [
        'php' => [],
        'zend' => [],
    ];

    $section = 'php';
    foreach ($modulesFragments as $line) {
        $line = trim($line);
        if (empty($line)) {
            continue;
        }
        if ($line === '[PHP Modules]') {
            $section = 'php';
            continue;
        } elseif ($line === '[Zend Modules]') {
            $section = 'zend';
            continue;
        }

        // TODO: handle how to extract this information for PHP-FPM
        $modules[$section][$line] = exec("php -r 'echo phpversion(\"${line}\");'");
    }

    return $modules;
}

function sanitize_ini($iniFragments)
{
    return array_filter(
        $iniFragments,
        function ($line) {
            if (strpos($line, '$_SERVER') === 0 && false === stripos($line, '$_SERVER[\'DD_')) {
                return false;
            } else if (strpos($line, '$_ENV') === 0 && false === stripos($line, '$_ENV[\'DD_')) {
                return false;
            } else if (false === strpos($line, '=>')) {
                return false;
            }
            // ... more ini lines to be removed to be added
            return true;
        }
    );
}


/**
 * Parses command line options provided by the user and generate a normalized $options array.

 * @return array
 */
function parse_validate_user_options()
{
    $shortOptions = "h";
    $longOptions = [
        OPT_HELP,
    ];
    $options = getopt($shortOptions, $longOptions);

    // Help and exit
    if (key_exists('h', $options) || key_exists(OPT_HELP, $options)) {
        print_help_and_exit();
    }

    $normalizedOptions = [];
    return $normalizedOptions;
}

function print_error_and_exit($message)
{
    echo "ERROR: $message\n";
    exit(1);
}

function print_warning($message)
{
    echo "WARNING: $message\n";
}

/**
 * Given a certain set of available PHP binaries, let users pick in an interactive way one.
 *
 * @param array $php_binaries
 * @return Binary
 */
function pick_binary_interactive(array $php_binaries)
{
    echo "Multiple PHP binaries detected. Please select one binary to run diagnostic checks:\n\n";
    $commands = array_keys($php_binaries);
    for ($index = 0; $index < count($commands); $index++) {
        $command = $commands[$index];
        $fullPath = $php_binaries[$command];
        echo "  "
            . str_pad($index + 1, 2, ' ', STR_PAD_LEFT)
            . ". "
            . ($command !== $fullPath ? "$command --> " : "")
            . $fullPath
            . "\n";
    }
    echo "\n";
    flush();

    echo "Select one binary by its index: ";
    $userInput = fgets(STDIN);
    $choice = intval($userInput);

    $index = $choice - 1; // we render to the user as 1-indexed
    if (!isset($commands[$index])) {
        echo "\nERROR: Wrong choice: $choice\n\n";
        return pick_binary_interactive($php_binaries);
    }

    $command = $commands[$index];

    return new Binary($command, $php_binaries[$command]);
}

function execute_or_exit($exitMessage, $command, &$output = [])
{
    $returnCode = 0;
    $lastLine = exec($command, $output, $returnCode);
    if (false === $lastLine || $returnCode > 0) {
        print_error_and_exit(
            $exitMessage .
                "\nFailed command: $command\n---- Output ----\n" .
                implode("\n", $output) .
                "\n---- End of output ----\n"
        );
    }

    return $lastLine;
}

/**
 * @param array $phpVersions
 * @param string $prefix Default ''. Used for testing purposes only.
 * @return array
 */
function search_php_binaries($prefix = '')
{
    echo "Searching for available php binaries, this operation might take a while.\n";

    $results = [];

    $allPossibleCommands = build_known_command_names_matrix();

    // First, we search in $PATH, for php, php7, php74, php7.4, php7.4-fpm, etc....
    foreach ($allPossibleCommands as $command) {
        $path = exec("command -v " . escapeshellarg($command));
        if ($resolvedPath = resolve_command_full_path($command)) {
            $results[$command] = $resolvedPath;
        }
    }

    // Then we search in known possible locations for popular installable paths on different systems.
    $standardPaths = [
        $prefix . '/usr/bin',
        $prefix . '/usr/sbin',
        $prefix . '/usr/local/bin',
        $prefix . '/usr/local/sbin',
    ];
    $remiSafePaths = array_map(function ($phpVersion) use ($prefix) {
        list($major, $minor) = explode('.', $phpVersion);
        /* php is installed to /usr/bin/php${major}${minor} so we do not need to do anything special, while php-fpm
         * is installed to /opt/remi/php${major}${minor}/root/usr/sbin and it needs to be added to the searched
         * locations.
         */
        return "${prefix}/opt/remi/php${major}${minor}/root/usr/sbin";
    }, get_supported_php_versions());
    $escapedSearchLocations =  implode(' ', array_map('escapeshellarg', $standardPaths + $remiSafePaths));
    $escapedCommandNamesForFind = implode(
        ' -o ',
        array_map(
            function ($cmd) {
                return '-name ' . escapeshellarg($cmd);
            },
            $allPossibleCommands
        )
    );

    $pathsFound = [];
    exec(
        "find -L $escapedSearchLocations -type f \( $escapedCommandNamesForFind \) 2>/dev/null",
        $pathsFound
    );

    foreach ($pathsFound as $path) {
        $resolved = realpath($path);
        if (in_array($resolved, array_values($results))) {
            continue;
        }
        $results[$path] = $resolved;
    }

    return $results;
}

/**
 * @param mixed $command
 * @return string|false
 */
function resolve_command_full_path($command)
{
    $path = exec("command -v " . escapeshellarg($command));
    if (false === $path || empty($path)) {
        // command is not defined
        return false;
    }

    // Resolving symlinks
    return realpath($path);
}

function build_known_command_names_matrix()
{
    $results = ['php', 'php-fpm'];

    foreach (get_supported_php_versions() as $phpVersion) {
        list($major, $minor) = explode('.', $phpVersion);
        array_push(
            $results,
            "php${major}",
            "php${major}${minor}",
            "php${major}.${minor}",
            "php${major}-fpm",
            "php${major}${minor}-fpm",
            "php${major}.${minor}-fpm",
            "php-fpm${major}",
            "php-fpm${major}${minor}",
            "php-fpm${major}.${minor}"
        );
    }

    return array_unique($results);
}

/**
 * @return string[]
 */
function get_supported_php_versions()
{
    return ['5.4', '5.5', '5.6', '7.0', '7.1', '7.2', '7.3', '7.4', '8.0', '8.1'];
}

main($argv);
