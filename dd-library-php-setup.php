<?php

const INI_CONF = 'Scan this dir for additional .ini files';
const EXTENSION_DIR = 'extension_dir';
const THREAD_SAFETY = 'Thread Safety';
const PHP_API = 'PHP API';
const IS_DEBUG = 'Debug Build';
const RELEVANT_INI_SETTINGS = [INI_CONF, EXTENSION_DIR, THREAD_SAFETY, PHP_API, IS_DEBUG];
const SUPPORTED_PHP_VERSIONS = ['5.4', '5.5', '5.6', '7.0', '7.1', '7.2', '7.3', '7.4', '8.0', '8.1'];

function main()
{
    if (is_truthy(getenv('DD_TEST_EXECUTION'))) {
        return;
    }

    $options = parse_validate_user_options();
    if ($options['uninstall']) {
        uninstall($options);
    } else {
        install($options);
    }
}

function install($options)
{
    // Checking required libraries
    check_library_prerequisite_or_exit('libcurl');
    if (is_alpine()) {
        check_library_prerequisite_or_exit('libexecinfo');
    }

    // Picking the right binaries to install the library
    $selectedBinaries = require_binaries($options);
    $interactive = empty($options['php-bin']);

    // Preparing clean tmp folder to extract files
    $tmpDir = sys_get_temp_dir() . '/dd-library';
    $tmpDirTarGz = $tmpDir . '/dd-trace-php.tar.gz';
    $tmpSourcesDir = $tmpDir . '/opt/datadog-php/dd-trace-sources';
    $tmpExtensionsDir = $tmpDir . '/opt/datadog-php/extensions';
    execute_or_exit("Cannot create directory '$tmpDir'", "mkdir -p $tmpDir");
    execute_or_exit("Cannot clean '$tmpDir'", "rm -rf $tmpDir/*.*");

    // Retrieve and extract the archive to a tmp location
    if (isset($options['tracer-file'])) {
        $archive = $options['tracer-file'];
        echo "Copying file '${archive}' to '${tmpDirTarGz}'\n";
        execute_or_exit("Cannot copy file from '${archive}' to '${tmpDirTarGz}'", "cp -r ${archive} ${tmpDirTarGz}");
    } else {
        $url = isset($options['tracer-url'])
            ? $options['tracer-url']
            : "https://github.com/DataDog/dd-trace-php/releases/download/" .
            $options['tracer-version'] . "/datadog-php-tracer-" .
            $options['tracer-version'] . ".x86_64.tar.gz";
        download($url, $tmpDirTarGz);
    }
    execute_or_exit("Cannot extract the archive", "tar -xf $tmpDirTarGz -C $tmpDir");

    $installDir = $options['install-dir'];
    $installDirSourcesDir = $installDir . '/dd-trace-sources';
    $installDirWrapperPath = $installDirSourcesDir . '/bridge/dd_wrap_autoloader.php';

    // copying sources to the final destination
    execute_or_exit("Cannot create directory '$installDirSourcesDir'", "mkdir -p $installDirSourcesDir");
    execute_or_exit(
        "Cannot copy files from '$tmpSourcesDir' to '$installDirSourcesDir'",
        "cp -r $tmpSourcesDir/* $installDirSourcesDir"
    );

    // Actual installation
    foreach ($selectedBinaries as $command => $fullPath) {
        $binaryForLog = ($command === $fullPath) ? $fullPath : "$command ($fullPath)";
        echo "Installing to binary: $binaryForLog\n";
        $phpProperties = ini_values($fullPath, RELEVANT_INI_SETTINGS);

        // Copying the extension
        $extensionVersion = $phpProperties[PHP_API];
        // Suffix (zts/debug/alpine)
        $extensionSuffix = '';
        if (is_alpine()) {
            $extensionSuffix = '-alpine';
        } elseif (is_truthy($phpProperties[IS_DEBUG])) {
            $extensionSuffix = '-debug';
        } elseif (is_truthy(THREAD_SAFETY)) {
            $extensionSuffix = '-zts';
        }
        $extensionRealPath = $tmpExtensionsDir . '/ddtrace-' . $extensionVersion . $extensionSuffix . '.so';
        $extensionFileName = 'ddtrace.so';
        $extensionDestination = $phpProperties[EXTENSION_DIR] . '/' . $extensionFileName;

        // Move - rename() - instead of copy() since copying does a fopen() and copy to stream itself, causing a
        // segfault.
        $tmpExtName = $extensionDestination . '.tmp';
        copy($extensionRealPath, $tmpExtName);
        rename($tmpExtName, $extensionDestination);
        echo "Copied '$extensionRealPath' '$extensionDestination'\n";

        // Writing the ini file
        $iniFileName = '98-ddtrace.ini';
        $iniFilePaths = [$phpProperties[INI_CONF] . '/' . $iniFileName];
        if (\strpos('/cli/conf.d', $phpProperties[INI_CONF]) >= 0) {
            $apacheConfd = str_replace('/cli/conf.d', '/apache2/conf.d', $phpProperties[INI_CONF]);
            if (\is_dir($apacheConfd)) {
                array_push($iniFilePaths, "$apacheConfd/$iniFileName");
            }
        }
        foreach ($iniFilePaths as $iniFilePath) {
            if (!file_exists($iniFilePath)) {
                file_put_contents($iniFilePath, get_ini_template($installDirWrapperPath));
                echo "Created INI file '$iniFilePath'\n";
            } else {
                echo "Updating existing INI file '$iniFilePath'\n";
                // phpcs:disable Generic.Files.LineLength.TooLong
                execute_or_exit(
                    'Impossible to update the INI settings file.',
                    "sed -i 's@datadog\.trace\.request_init_hook \?= \?\(.*\)@datadog.trace.request_init_hook = $installDirWrapperPath@g' '$iniFilePath'"
                );
                // phpcs:enable Generic.Files.LineLength.TooLong

                // In order to support upgrading from legacy installation method to new installation method, we replace
                // "extension=/opt/datadog-php/xyz.so" with "extension=ddtrace.so" honoring trailing `;`, hence not
                // automatically re-activing the extension if the user had commented it out.
                execute_or_exit(
                    'Impossible to update the INI settings file.',
                    "sed -i 's@extension \?= \?\(.*\)@extension = ddtrace.so@g' '$iniFilePath'"
                );
            }
            echo "Installation to '$command' was successful\n";
        }
    }

    echo "--------------------------------------------------\n";
    echo "SUCCESS\n\n";
    if ($interactive) {
        echo "Run this script in a non interactive mode adding the following 'php-bin' options:\n";
        $phpBins = implode(
            ' ',
            array_map(
                function ($el) {
                    return '--php-bin=' . $el;
                },
                array_keys($selectedBinaries)
            )
        );
        echo "  php dd-library-php-setup.php [ ... existing options... ] $phpBins\n";
    }
}

function uninstall($options)
{
    $selectedBinaries = require_binaries($options);

    foreach ($selectedBinaries as $command => $fullPath) {
        $binaryForLog = ($command === $fullPath) ? $fullPath : "$command ($fullPath)";
        echo "Uninstalling from binary: $binaryForLog\n";

        $phpProperties = ini_values($fullPath, RELEVANT_INI_SETTINGS);

        $extensionDestination = $phpProperties[EXTENSION_DIR] . '/ddtrace.so';

        // Writing the ini file
        $iniFileName = '98-ddtrace.ini';
        $iniFilePaths = [$phpProperties[INI_CONF] . '/' . $iniFileName];
        if (\strpos('/cli/conf.d', $phpProperties[INI_CONF]) >= 0) {
            $apacheConfd = str_replace('/cli/conf.d', '/apache2/conf.d', $phpProperties[INI_CONF]);
            if (\is_dir($apacheConfd)) {
                array_push($iniFilePaths, "$apacheConfd/$iniFileName");
            }
        }

        // Actual uninstall
        //  1) comment out extension=ddtrace.so
        //  2) remove ddtrace.so
        foreach ($iniFilePaths as $iniFilePath) {
            if (file_exists($iniFilePath)) {
                execute_or_exit(
                    "Impossible to disable ddtrace from '$iniFilePath'. Disable it manually.",
                    "sed -i 's@^extension \?=@;extension =@g' '$iniFilePath'"
                );
                echo "Disabled ddtrace in INI file '$iniFilePath'\n";
            }
            echo "Installation to '$command' was successful\n";
        }
        unlink($extensionDestination);
    }
}

/**
 * @param mixed $options
 * @return []
 */
function require_binaries($options)
{
    $selectedBinaries = [];
    if (empty($options['php-bin'])) {
        $selectedBinaries = pick_binaries_interactive(search_php_binaries(SUPPORTED_PHP_VERSIONS));
    } elseif (in_array('all', $options['php-bin'])) {
        $selectedBinaries = search_php_binaries(SUPPORTED_PHP_VERSIONS);
    } else {
        foreach ($options['php-bin'] as $command) {
            if ($resolvedPath = resolve_command_full_path($command)) {
                $selectedBinaries[$command] = $resolvedPath;
            } else {
                echo "Provided PHP binary '$command' was not found.\n";
                exit(1);
            }
        }
    }

    if (empty($selectedBinaries)) {
        echo "At least one binary must be specified\n";
        exit(1);
    }

    return $selectedBinaries;
}

function check_library_prerequisite_or_exit($requiredLibrary)
{
    if (is_alpine()) {
        $lastLine = execute_or_exit(
            "Error while searching for library '$requiredLibrary'.",
            "find /usr/local/lib /usr/lib -type f -name '*${requiredLibrary}*.so*'"
        );
    } else {
        $lastLine = execute_or_exit(
            "Cannot find library '$requiredLibrary'",
            "ldconfig -p | grep $requiredLibrary"
        );
    }

    if (empty($lastLine)) {
        echo "Required library '$requiredLibrary' not found.\n";
        exit(1);
    }
}

function is_alpine()
{
    $osInfoFile = '/etc/os-release';
    // if /etc/os-release is not readable, we cannot tell and we assume NO
    if (!is_readable($osInfoFile)) {
        return false;
    }
    return false !== strpos(strtolower(file_get_contents($osInfoFile)), 'alpine');
}

/**
 * Parses command line options provided by the user.
 * @return array
 */
function parse_validate_user_options()
{
    $shortOptions = "h";
    $longOptions = [
        'help',
        'php-bin:',
        'tracer-file:',
        'tracer-url:',
        'tracer-version:',
        'install-dir:',
        'uninstall',
    ];
    $options = getopt($shortOptions, $longOptions);

    // Help and exit
    if (key_exists('h', $options) || key_exists('help', $options)) {
        print_help_and_exit();
    }

    $normalizedOptions = [];

    $normalizedOptions['uninstall'] = isset($options['uninstall']) ? true : false;

    if (!$normalizedOptions['uninstall']) {
        // One and only one among --tracer-version, --tracer-url and --tracer-file must be provided
        $installables = array_intersect(['tracer-version', 'tracer-url', 'tracer-file'], array_keys($options));
        if (count($installables) === 0 || count($installables) > 1) {
            print_error_and_exit(
                'One and only one among --tracer-version, --tracer-url and --tracer-file must be provided'
            );
        }
        if (isset($options['tracer-version'])) {
            if (is_array($options['tracer-version'])) {
                print_error_and_exit('Only one --tracer-version can be provided');
            }
            $normalizedOptions['tracer-version'] = $options['tracer-version'];
        } elseif (isset($options['tracer-url'])) {
            if (is_array($options['tracer-url'])) {
                print_error_and_exit('Only one --tracer-url can be provided');
            }
            $normalizedOptions['tracer-url'] = $options['tracer-url'];
        } elseif (isset($options['tracer-file'])) {
            if (is_array($options['tracer-file'])) {
                print_error_and_exit('Only one --tracer-file can be provided');
            }
            $normalizedOptions['tracer-file'] = $options['tracer-file'];
        }
    }

    if (isset($options['php-bin'])) {
        $normalizedOptions['php-bin'] = is_array($options['php-bin']) ? $options['php-bin'] : [$options['php-bin']];
    }

    $normalizedOptions['install-dir'] =
        isset($options['install-dir'])
        ? rtrim($options['install-dir'], '/')
        : '/opt/datadog';
    $normalizedOptions['install-dir'] =  $normalizedOptions['install-dir'] . '/dd-library';

    return $normalizedOptions;
}

function print_help_and_exit()
{
    echo <<<EOD

Usage:
    php get-dd-trace.php --php-bin=php ...
    php get-dd-trace.php --php-bin=php-fpm ...
    php get-dd-trace.php --php-bin=/usr/local/sbin/php-fpm ...
    php get-dd-trace.php --php-bin=php --php-bin=/usr/local/sbin/php-fpm ...

Options:
    -h, --help                  Print this help text and exit
    --php-bin=<0.1.2>           Install the library to the specified binary. Multiple values are allowed.
    --tracer-version=<0.1.2>    Install a specific version. If set --tracer-url and --tracer-file are ignored.
    --tracer-url=<url>          Install the tracing library from a url. If set --tracer-file is ignored.
    --tracer-file=<file>        Install the tracing library from a local file.
    --install-dir=<path>        Install to a specific directory. Default: '/opt/datadog'
    --uninstall                 Uninstall the library from the specified binaries

EOD;
    exit(0);
}

function print_error_and_exit($message)
{
    echo "ERROR: $message\n";
    exit(1);
}

function write_file($path, $content, $override = false)
{
    if ($override || !file_exists($path)) {
        file_put_contents($path, $content);
    }
}

function pick_binaries_interactive(array $php_binaries)
{
    echo "Multiple PHP binaries detected. Please select the binaries the datadog library will be installed to:\n\n";
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

    $userInput = readline("Select binaries unsing their number. Multiple binaries separated by space (example: 1 3): ");
    $choices = array_map('intval', array_filter(explode(' ', $userInput)));

    $pickedBinaries = [];
    foreach ($choices as $choice) {
        $index = $choice - 1; // we render to the user as 1-indexed
        $command = $commands[$index];
        if ($index >= count($commands) || $index < 0) {
            echo "\nERROR: Wrong choice: $choice\n\n";
            return pick_binaries_interactive($php_binaries);
        }
        $pickedBinaries[$command] = $php_binaries[$command];
    }

    return $pickedBinaries;
}

function execute_or_exit($exitMessage, $command)
{
    $output = null;
    $returnCode = 0;
    $lastLine = exec($command, $output, $returnCode);
    if (false === $lastLine || $returnCode > 0) {
        echo "ERROR: " . $exitMessage . "\n";
        exit(1);
    }

    return $lastLine;
}

global $progress_counter;

function download($url, $destination)
{
    echo "Downloading installable archive from $url\n.";
    echo "This operation might take a while.\n";

    $okMessage = "\nDownload completed\n\n";

    // We try the following options:
    //   1) `ext-curl` (with progress report); if 'ext-curl' is not installed...
    //   2) `curl` from CLI (it shows progress); if `curl` is not installed...
    //   3) `file_get_contents()` (no progress report); if `allow_url_fopen=0`...
    //   4) exit with errror

    // ext-curl
    if (extension_loaded('curl')) {
        global $progress_counter;
        $fp = fopen($destination, 'w+');
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 50);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, 'on_download_progress');
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        $progress_counter = 0;
        curl_exec($ch);
        curl_close($ch);
        fclose($fp);

        echo $okMessage;
        return;
    }

    // curl
    $statusCode = 0;
    $output = [];;
    if (false !== exec('curl --tracer-version', $output, $statusCode) && $statusCode === 0) {
        $curlInvocationStatusCode = 0;
        system(
            'curl -L --output ' . escapeshellarg($destination) . ' ' . escapeshellarg($url),
            $curlInvocationStatusCode
        );

        if ($curlInvocationStatusCode > 0) {
            echo "Error while downloading the installable archive from $url\n";
            exit(1);
        }

        echo $okMessage;
        return;
    }

    // file_get_contents
    if (is_truthy(ini_get('allow_url_fopen'))) {
        file_put_contents($destination, file_get_contents($url));

        echo $okMessage;
        return;
    }

    echo "Error: Cannot download the installable archive.\n";
    echo "  One of the following prerequisites must be satisfied:\n";
    echo "    - PHP ext-curl extension is installed\n";
    echo "    - curl CLI command is available\n";
    echo "    - the INI setting 'allow_url_fopen=1'\n";

    exit(1);
}

function on_download_progress($curlHandle, $download_size, $downloaded)
{
    global $progress_counter;

    if ($download_size === 0) {
        return 0;
    }
    $ratio = $downloaded / $download_size;
    if ($ratio == 1) {
        return 0;
    }

    // Max 20 dots to show progress
    if ($ratio >= ($progress_counter + (1 / 20))) {
        $progress_counter = $ratio;
        echo ".";
    }

    flush();
    return 0;
}

function ini_values($binary, array $properties)
{
    // $properties = [INI_CONF, EXTENSION_DIR, THREAD_SAFETY, PHP_EXTENSION, IS_DEBUG];
    $lines = [];
    exec($binary . " -d date.timezone=UTC -i", $lines);
    $found = [];
    foreach ($lines as $line) {
        $parts = explode('=>', $line);
        if (count($parts) === 2 || count($parts) === 3) {
            $key = trim($parts[0]);
            if (in_array($key, $properties)) {
                $found[$key] = trim(count($parts) === 2 ? $parts[1] : $parts[2]);
            }
        }
    }
    return $found;
}

function is_truthy($value)
{
    $normalized = trim(strtolower($value));
    return in_array($normalized, ['1', 'true', 'yes', 'enabled']);
}

/**
 *
 * @param array $phpVersions
 * @param string $prefix Default ''. Used for testing purposes only.
 * @return array
 */
function search_php_binaries(array $phpVersions, $prefix = '')
{
    $results = [];

    // First, we search in $PATH, for php, php7, php74, php7.4, php7.4-fpm, etc....
    foreach (build_known_command_names_matrix($phpVersions) as $command) {
        $path = exec("command -v $command");
        if ($resolvedPath = resolve_command_full_path($command)) {
            $results[$command] = $resolvedPath;
        }
    }

    // Then we search in known possible locations for popular installable paths on different systems.
    $standardPaths = [
        $prefix . '/usr/bin',
        $prefix . '/usr/sbin',
    ];
    $remiSafePaths = array_map(function ($phpVersion) use ($prefix) {
        list($major, $minor) = explode('.', $phpVersion);
        return "${prefix}/opt/remi/php${major}${minor}/root/usr/sbin";
    }, $phpVersions);

    foreach (($standardPaths + $remiSafePaths) as $knownPath) {
        $pathsFound = [];
        // phpcs:disable Generic.Files.LineLength.TooLong
        exec(
            "find -L $knownPath -type f -executable -regextype sed -regex '.*/php\(-fpm\)\?\([0-9][\.]\?[0-9]\?\)\?\(-fpm\)\?' 2>/dev/null",
            $pathsFound
        );
        // phpcs:enable Generic.Files.LineLength.TooLong
        foreach ($pathsFound as $path) {
            $resolved = exec("readlink -f $path");
            if (in_array($resolved, array_values($results))) {
                continue;
            }
            $results[$resolved] = $resolved;
        }
    }

    return $results;
}

/**
 * @param mixed $command
 * @return string|false
 */
function resolve_command_full_path($command)
{
    $path = exec("command -v $command");
    if (false === $path || empty($path)) {
        // command is not defined
        return false;
    }

    // Resolving symlinks
    return exec("readlink -f $path");
}

function build_known_command_names_matrix(array $phpVersions)
{
    $results = ['php', 'php-fpm'];

    foreach ($phpVersions as $phpVersion) {
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

    return $results;
}

function get_ini_template($requestInitHookPath)
{
    // phpcs:disable Generic.Files.LineLength.TooLong
    return <<<EOD
;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;
; Required settings
;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;

; Enables or disables tracing (set by the installer, do not change it)
extension = ddtrace.so

; Path to the request init hook (set by the installer, do not change it)
datadog.trace.request_init_hook = $requestInitHookPath

;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;
; Common settings
;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;

; Enables or disables tracing. On by default.
;datadog.trace.enabled = On

; Enables or disables debug mode.  When On logs are printed to the error_log.
;datadog.trace.debug = Off

; Enables startup logs, including diagnostic checks.
;datadog.trace.startup_logs = On

; Sets a custom service name for the application.
;datadog.service = my_service

; Sets a custom environment name for the application.
;datadog.env = my_env

; Sets a version for the user application, not the datadog php library.
;datadog.version = 1.0.0

; Configures the agent host and ports. If you need more flexibility use `datadog.trace.agent_url` instead.
;datadog.agent_host = localhost
;datadog.trace.agent_port = 8126
;datadog.dogstatsd_port = 8125

; When set, 'datadog.trace.agent_url' has priority over 'datadog.agent_host' and 'datadog.trace.agent_port'.
;datadog.trace.agent_url = https://some.internal.host:6789

; Sets the service name of spans generated for HTTP clients' requests to host-<hostname>.
;datadog.trace.http_client_split_by_domain = Off

; Configures URL to resource name normalization. For more details see:
; https://docs.datadoghq.com/tracing/setup_overview/setup/php/?tab=containers#map-resource-names-to-normalized-uri
; NOTE: Colons ',' in `datadog.trace.resource_uri_fragment_regex` are not supported.
;datadog.trace.url_as_resource_names_enabled = On
;datadog.trace.resource_uri_fragment_regex =
;datadog.trace.resource_uri_mapping_incoming =
;datadog.trace.resource_uri_mapping_outgoing =

; Changes the default name of an APM integration. Rename one or more integrations at a time, for example:
; "pdo:payments-db,mysqli:orders-db"
;datadog.service_mapping =

; Tags to be set on all spans, for example: "key1:value1,key2:value2".
;datadog.tags =

; The sampling rate for the trace. Valid values are between 0.0 and 1.0.
;datadog.trace.sample_rate = 1.0

; A JSON encoded string to configure the sampling rate.
; Examples:
;   - Set the sample rate to 20%: '[{"sample_rate": 0.2}]'.
;   - Set the sample rate to 10% for services starting with ‘a’ and span name ‘b’ and set the sample rate to 20%
;     for all other services: '[{"service": "a.*", "name": "b", "sample_rate": 0.1}, {"sample_rate": 0.2}]'
; **Note** that the JSON object must be included in single quotes (') to avoid problems with escaping of the
; double quote (") character.
;datadog.trace.sampling_rules =

;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;
; CLI settings
;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;

; Enable or disable tracing of CLI scripts. Off by default.
;datadog.trace.cli_enabled = Off

; For long running processes, this setting has to be set to On
;datadog.trace.auto_flush_enabled = Off

; For long running processes, this setting has to be set to Off
;datadog.trace.generate_root_span = On

;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;
; Integrations settings
; For each integration:
;   - *_enabled: whether the integration is enabled.
;   - *_analytics_enabled: whether analytics for the integration is enabled.
;   - *_analytics_sample_rate: sampling rate for analyzed spans. Valid values are between 0.0 and 1.0.
;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;

;datadog.trace.cakephp_enabled = On
;datadog.trace.cakephp_analytics_enabled = Off
;datadog.trace.cakephp_analytics_sample_rate = 1
;datadog.trace.codeigniter_enabled = On
;datadog.trace.codeigniter_analytics_enabled = Off
;datadog.trace.codeigniter_analytics_sample_rate = 1
;datadog.trace.curl_enabled = On
;datadog.trace.curl_analytics_enabled = Off
;datadog.trace.curl_analytics_sample_rate = 1
;datadog.trace.elasticsearch_enabled = On
;datadog.trace.elasticsearch_analytics_enabled = Off
;datadog.trace.elasticsearch_analytics_sample_rate = 1
;datadog.trace.eloquent_enabled = On
;datadog.trace.eloquent_analytics_enabled = Off
;datadog.trace.eloquent_analytics_sample_rate = 1
;datadog.trace.guzzle_enabled = On
;datadog.trace.guzzle_analytics_enabled = Off
;datadog.trace.guzzle_analytics_sample_rate = 1
;datadog.trace.laravel_enabled = On
;datadog.trace.laravel_analytics_enabled = Off
;datadog.trace.laravel_analytics_sample_rate = 1
;datadog.trace.lumen_enabled = On
;datadog.trace.lumen_analytics_enabled = Off
;datadog.trace.lumen_analytics_sample_rate = 1
;datadog.trace.memcached_enabled = On
;datadog.trace.memcached_analytics_enabled = Off
;datadog.trace.memcached_analytics_sample_rate = 1
;datadog.trace.mongo_enabled = On
;datadog.trace.mongo_analytics_enabled = Off
;datadog.trace.mongo_analytics_sample_rate = 1
;datadog.trace.mysqli_enabled = On
;datadog.trace.mysqli_analytics_enabled = Off
;datadog.trace.mysqli_analytics_sample_rate = 1
;datadog.trace.nette_enabled = On
;datadog.trace.nette_analytics_enabled = Off
;datadog.trace.nette_analytics_sample_rate = 1
;datadog.trace.pdo_enabled = On
;datadog.trace.pdo_analytics_enabled = Off
;datadog.trace.pdo_analytics_sample_rate = 1
;datadog.trace.phpredis_enabled = On
;datadog.trace.phpredis_analytics_enabled = Off
;datadog.trace.phpredis_analytics_sample_rate = 1
;datadog.trace.predis_enabled = On
;datadog.trace.predis_analytics_enabled = Off
;datadog.trace.predis_analytics_sample_rate = 1
;datadog.trace.slim_enabled = On
;datadog.trace.slim_analytics_enabled = Off
;datadog.trace.slim_analytics_sample_rate = 1
;datadog.trace.symfony_enabled = On
;datadog.trace.symfony_analytics_enabled = Off
;datadog.trace.symfony_analytics_sample_rate = 1
;datadog.trace.web_enabled = On
;datadog.trace.web_analytics_enabled = Off
;datadog.trace.web_analytics_sample_rate = 1
;datadog.trace.wordpress_enabled = On
;datadog.trace.wordpress_analytics_enabled = Off
;datadog.trace.wordpress_analytics_sample_rate = 1
;datadog.trace.yii_enabled = On
;datadog.trace.yii_analytics_enabled = Off
;datadog.trace.yii_analytics_sample_rate = 1
;datadog.trace.zendframework_enabled = On
;datadog.trace.zendframework_analytics_enabled = Off
;datadog.trace.zendframework_analytics_sample_rate = 1

;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;
; Other settings
;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;

; Enables distributed tracing.
;datadog.distributed_tracing = On

; Global switch for trace analytics.
;datadog.trace.analytics_enabled = Off

; Set connection timeout in millisecodns while connecting to the agent.
;datadog.trace.bgs_connect_timeout = 2000

; Set request timeout in millisecodns while while sending payloads to the agent.
;datadog.trace.bgs_timeout = 5000

; Set the maximum number of spans generated per trace during a single request.
;datadog.trace.spans_limit = 1000

; Only for Linux. Set to `true` to retain capabilities on Datadog background threads when you change the effective
; user ID. This option does not affect most setups, but some modules - to date Datadog is only aware of Apache’s
; mod-ruid2 - may invoke `setuid()` or similar syscalls, leading to crashes or loss of functionality as it loses
; capabilities.
; **Note** Enabling this option may compromise security. This option, standalone, does not pose a security risk.
; However, an attacker being able to exploit a vulnerability in PHP or web server may be able to escalate privileges
; with relative ease, if the web server or PHP were started with full capabilities, as the background threads will
; retain their original capabilities. Datadog recommends restricting the capabilities of the web server with the
; setcap utility.
;datadog.trace.retain_thread_capabilities = Off

EOD;
    // phpcs:enable Generic.Files.LineLength.TooLong
}

main();
