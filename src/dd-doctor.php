<?php

if ('cli' === PHP_SAPI) {
    echo 'WARNING: Script is running from the CLI SAPI.' . PHP_EOL;
    echo '         Please run this script from your web browser.' . PHP_EOL;
    echo PHP_EOL;
    $cliEnabled = getenv('DD_TRACE_CLI_ENABLED');
    $cliEnabled = ('1' === $cliEnabled || 'true' === $cliEnabled);
    if (!$cliEnabled) {
        echo 'Tracing from the CLI SAPI is not enabled.' . PHP_EOL;
        echo 'To enable, set: DD_TRACE_CLI_ENABLED=1' . PHP_EOL;
        echo 'This script should be run from the targeted SAPI.' . PHP_EOL;
        echo 'Supported SAPI\'s are fpm, apache2handler, and cli.' . PHP_EOL;
        return;
    }
} else {
    echo '<html>' . PHP_EOL;
    echo '<head>' . PHP_EOL;
    echo '<title>dd-doctor.php :: Datadog PHP Tracer</title>' . PHP_EOL;
    echo '</head>' . PHP_EOL;
    echo '<body>' . PHP_EOL;
    echo '<pre>' . PHP_EOL;
}

function result($check)
{
    if ('cli' !== PHP_SAPI) {
        return $check
            ? '<span style="color:#090">OK</span>'
            : '<span style="color:#900">FAIL</span>';
    }
    return $check
        ? "\e[0;32mOK\e[0m"
        : "\e[0;31mFAIL\e[0m";
}

function render($message, $value)
{
    if (is_bool($value)) {
        $value = result($value);
    }
    printf('- %-42s [%s]%s', $message, $value, PHP_EOL);
}

function env($key)
{
    return function_exists('dd_trace_env_config')
        ? dd_trace_env_config($key)
        : getenv($key);
}

function check_agent_connectivity()
{
    $host = env('DD_AGENT_HOST') ?: 'localhost';
    render('Configured Agent host', $host);
    $port = env('DD_TRACE_AGENT_PORT') ?: '8126';
    render('Configured Agent port', $port);

    $verbose = fopen('php://temp', 'w+b');
    $ch = curl_init("http://" . $host . ":" . $port . "/v0.3/traces");
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, "[]");
    curl_setopt($ch, CURLOPT_VERBOSE, true);
    curl_setopt($ch, CURLOPT_STDERR, $verbose);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $success = $httpcode >= 200 && $httpcode < 300;
    render('Agent can receive traces', $success);
    curl_close($ch);

    if (!$success) {
        rewind($verbose);
        $verboseLog = stream_get_contents($verbose);
        echo "Curl verbose output: " . PHP_EOL . PHP_EOL;
        echo $verboseLog . PHP_EOL;
    }
}

// Ignore any E_WARNING's from open_basedir INI directive
function quiet_file_exists($file)
{
    return @file_exists($file);
}

echo 'DataDog trace extension verification' . PHP_EOL . PHP_EOL;

render('PHP version and SAPI', PHP_VERSION . ' - ' . PHP_SAPI);
render('ddtrace extension installed', extension_loaded('ddtrace') || extension_loaded('dd_trace'));
$versionInstalled = phpversion('ddtrace') ?: false;
render('ddtrace version (installed)', $versionInstalled);
$versionConst = defined('DD_TRACE_VERSION') ? DD_TRACE_VERSION : false;
render('ddtrace version (const)', $versionConst);
$initHook = ini_get('ddtrace.request_init_hook');
$versionUserland = false;
if (!empty($initHook)) {
    $userlandVersionFile = dirname(dirname($initHook)) . '/src/DDTrace/version.php';
    $versionUserland = quiet_file_exists($userlandVersionFile) ? include $userlandVersionFile : false;
}
render('ddtrace version (userland)', $versionUserland);
render('ddtrace versions in sync', $versionInstalled === $versionConst && $versionConst === $versionUserland);
render('dd_trace() function available', function_exists('dd_trace'));
render('dd_trace_env_config() function available', function_exists('dd_trace_env_config'));

render('ddtrace.request_init_hook set', !empty($initHook));
$initHookReachable = quiet_file_exists($initHook);
render('ddtrace.request_init_hook reachable', $initHookReachable);
if ($initHookReachable) {
    $initHookHasRun = function_exists('DDTrace\\Bridge\\dd_wrap_autoloader');
    render('ddtrace.request_init_hook has run', $initHookHasRun);
} else {
    render('open_basedir INI directive', ini_get('open_basedir') ?: 'empty');
}

class AutoloadTest
{
    public static function load($class)
    {
        var_dump($class);
    }
}

$integrationsLoaderExists = class_exists('\\DDTrace\\Integrations\\IntegrationsLoader');
render('IntegrationsLoader exists', $integrationsLoaderExists);
if ($integrationsLoaderExists) {
    $notLoaded = \DDTrace\Integrations\IntegrationsLoader::get()->getLoadingStatus('web');
    render('Integrations not loaded yet', 0 === $notLoaded);

    echo '- Registering an autoloader...' . PHP_EOL;
    spl_autoload_register('AutoloadTest::load');

    $loaded = \DDTrace\Integrations\IntegrationsLoader::get()->getLoadingStatus('web');
    render('Integrations loaded', 0 !== $loaded);
}

render('DDTrace\\Tracer class exists', class_exists('\\DDTrace\\Tracer'));

check_agent_connectivity();

if ('cli' !== PHP_SAPI) {
    echo '</pre>' . PHP_EOL;
    echo '</body>' . PHP_EOL;
    echo '</html>' . PHP_EOL;
}

echo PHP_EOL;
