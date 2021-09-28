<?php

const INI_CONF = 'Scan this dir for additional .ini files';
const EXTENSION_DIR = 'extension_dir';
const THREAD_SAFETY = 'Thread Safety';
const PHP_EXTENSION = 'PHP Extension';
const IS_DEBUG = 'Debug Build';
const RELEVANT_INI_SETTINGS = [INI_CONF, EXTENSION_DIR, THREAD_SAFETY, PHP_EXTENSION, IS_DEBUG];
const SUPPORTED_PHP_VERSIONS = ['5.4', '5.5', '5.6', '7.0', '7.1', '7.2', '7.3', '7.4', '8.0', '8.1'];

function main()
{
    if (is_truthy(getenv('DD_TEST_EXECUTION'))) {
        return;
    }

    $options = parse_validate_user_options();
    install($options);
}

main();

function install($options)
{
    // Picking the right binaries to install the library
    $selectedBinaries = [];
    if (empty($options['php-bin'])) {
        $selectedBinaries = pick_binaries_interactive(search_php_binaries(SUPPORTED_PHP_VERSIONS));
    } else {
        foreach ($options['php-bin'] as $command) {
            $selectedBinaries[$command] = exec("readlink -f $command");
        }
    }

    // Preparing clean tmp folder to extrac files
    $tmpDir = sys_get_temp_dir() . '/dd-library';
    $tmpDirTarGz = $tmpDir . '/dd-trace-php.tar.gz';
    $tmpSourcesDir = $tmpDir . '/opt/datadog-php/dd-trace-sources';
    $tmpExtensionsDir = $tmpDir . '/opt/datadog-php/extensions';
    execute_or_exit("Cannot create directory '$tmpDir'", "mkdir -p $tmpDir");
    execute_or_exit("Cannot clear '$tmpDir'", "rm -rf $tmpDir/*.*");

    // Retrieve and extract the archive to a tmp location
    if ($archive = isset($options['tracer-file'])) {
        execute_or_exit("Cannot copy file from '${archive}' to '${tmpDirTarGz}'", "cp -r ${archive}/* ${tmpDirTarGz}");
    } else {
        $url = isset($options['tracer-url']) ? $options['tracer-url'] : "https://github.com/DataDog/dd-trace-php/releases/download/" .
            $options['tracer-version'] . "/datadog-php-tracer-" .
            $options['tracer-version'] . ".x86_64.tar.gz";
        download($url, $tmpDirTarGz);
    }
    execute_or_exit("Cannot extract the archive", "tar -xf $tmpDirTarGz -C $tmpDir");

    $installDir = (empty($options['install-dir']) ? '/opt/datadog' : $options['install-dir']) . '/dd-library';
    $installDirSourcesDir = $installDir . '/dd-trace-sources';
    $installDirWrapperPath = $installDirSourcesDir . '/bridge/dd_wrap_autoloader.php';

    // copying sources to the final destination
    execute_or_exit("Cannot create directory '$installDirSourcesDir'", "mkdir -p $installDirSourcesDir");
    execute_or_exit("Cannot copy files from '$tmpSourcesDir' to '$installDirSourcesDir'", "cp -r $tmpSourcesDir/* $installDirSourcesDir");

    // Actual installation
    foreach ($selectedBinaries as $command => $fullPath) {
        echo "Installing to binary: $command\n";
        $phpProperties = ini_values($fullPath, RELEVANT_INI_SETTINGS);

        // Copying the extension
        $extensionVersion = $phpProperties[PHP_EXTENSION];
        $extensionSuffix = is_truthy($phpProperties[IS_DEBUG]) ? '-debug' : (is_truthy(THREAD_SAFETY) ? '-zts' : '');
        $extensionRealPath = $tmpExtensionsDir . '/ddtrace-' . $extensionVersion . $extensionSuffix . '.so';
        $extensionFileName = 'ddtrace.so';
        $extensionDestination = $phpProperties[EXTENSION_DIR] . '/' . $extensionFileName;

        // Move - rename() - instead of copy() since copying does a fopen() and copy to stream itself, causing a
        // segfault.
        $tmpExtName = $extensionDestination . '.tmp';
        copy($extensionRealPath, $tmpExtName);
        rename($tmpExtName, $extensionDestination);

        // Writing the ini file
        $customIniFilePath = $phpProperties[INI_CONF] . '/98-ddtrace.ini';
        if (!file_exists($customIniFilePath)) {
            file_put_contents($customIniFilePath, get_ini_template($installDirWrapperPath));
        } else {
            // replacing ...
            execute_or_exit('aaaa', "sed -i 's/datadog\.trace\.request_init_hook \?= \?\(.*\)/datadog.trace.request_init_hook = $installDirWrapperPath/g'");
        }
    }
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
    ];
    $options = getopt($shortOptions, $longOptions);

    // Help and exit
    if (key_exists('h', $options) || key_exists('help', $options)) {
        print_help_and_exit();
    }

    $normalizedOptions = [];

    // One and only one among --version, --url and --file must be provided
    $installables = array_intersect(['tracer-version', 'tracer-url', 'tracer-file'], array_keys($options));
    if (count($installables) === 0 || count($installables) > 1) {
        print_error_and_exit('One and only one among --version, --url and --file must be provided');
    }
    if (isset($options['tracer-version'])) {
        if (is_array($options['tracer-version'])) {
            print_error_and_exit('Only one --version can be provided');
        }
        $normalizedOptions['tracer-version'] = $options['tracer-version'];
    } else if (isset($options['tracer-url'])) {
        if (is_array($options['tracer-url'])) {
            print_error_and_exit('Only one --url can be provided');
        }
        $normalizedOptions['tracer-url'] = $options['tracer-url'];
    } else if (isset($options['tracer-file'])) {
        if (is_array($options['tracer-file'])) {
            print_error_and_exit('Only one --file can be provided');
        }
        $normalizedOptions['tracer-file'] = $options['tracer-file'];
    }

    if (isset($options['php-bin'])) {
        $normalizedOptions['php-bin'] = is_array($options['php-bin']) ? $options['php-bin'] : [$options['php-bin']];
    }

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
        --tracer-version=<0.1.2>    Install a specific version. If set --url and --file are ignored.
        --tracer-url=<url>          Install the tracing library from a url. If set --file is ignored.
        --tracer-file=<file>        Install the tracing library from a local file.
        --install-dir=<path>        Install to a specific directory. Default: '/opt/datadog'

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
        $fullPath = $php_binaries[$commands[$index]];
        echo "  " . str_pad($index + 1, 2, ' ', STR_PAD_LEFT) . ". " . ($command !== $fullPath ? "$command --> " : "") . $fullPath . "\n";
    }
    echo "\n";
    flush();

    $userInput = readline("Select binaries unsing their number. Multiple binaries separated by space (example: 1 3): ");
    $choices = array_map('intval', array_filter(explode(' ', $userInput)));

    $pickedBinaries = [];
    foreach ($choices as $choice) {
        $index = $choice - 1; // we render to the user as 1-indexed
        if ($index >= count($commands) || $index < 0) {
            echo "\nERROR: Wrong choice: $choice\n\n";
            return pick_binaries_interactive($php_binaries);
        }
        $pickedBinaries[$commands[$index]] = $php_binaries[$commands[$index]];
    }

    return $pickedBinaries;
}

function execute_or_exit($exitMessage, $command)
{
    $result = exec($command);
    if (false === $result) {
        echo "ERROR: " . $exitMessage;
        exit(1);
    }

    return $result;
}

global $progress_counter;

function download($url, $destination)
{
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
    echo "\nDownload completed\n\n";
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
    exec(PHP_BINARY . " -i", $lines);
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
        if (false === $path || empty($path)) {
            // command is not defined
            continue;
        }

        // Resolving symlinks
        $resolvedPath = exec("readlink -f $path");
        $results[$command] = $resolvedPath;
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
        exec("find -L $knownPath -type f -executable -regextype sed -regex '.*/php\(-fpm\)\?\([0-9][\.]\?[0-9]\?\)\?\(-fpm\)\?' 2>/dev/null", $pathsFound);
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

function build_known_command_names_matrix(array $phpVersions)
{
    $results = ['php', 'php-fpm'];

    foreach ($phpVersions as $phpVersion) {
        list($major, $minor) = explode('.', $phpVersion);
        $results[] = "php${major}";
        $results[] = "php${major}${minor}";
        $results[] = "php${major}.${minor}";
        $results[] = "php${major}-fpm";
        $results[] = "php${major}${minor}-fpm";
        $results[] = "php${major}.${minor}-fpm";
        $results[] = "php-fpm${major}";
        $results[] = "php-fpm${major}${minor}";
        $results[] = "php-fpm${major}.${minor}";
    }

    return $results;
}

function get_ini_template($requestInitHookPath)
{
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
}
