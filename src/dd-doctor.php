<?php

const OK = '__OK__';
const FAIL = '__FAIL_';
const WARN = '__WARN__';

const TEXT_WIDTH = 50;


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

function result($value)
{
    if ('cli' !== PHP_SAPI) {
        if ($value === OK) {
            return '<span style="color:#009900">OK</span>';
        } else if ($value === FAIL) {
            return '<span style="color:#990000">FAIL</span>';
        } else if ($value === WARN) {
            return '<span style="color:#FFCC00">WARN</span>';
        } else {
            return $value;
        }
    }

    if ($value === OK) {
        return "\e[0;32mOK\e[0m";
    } else if ($value === FAIL) {
        return "\e[0;31mFAIL\e[0m";
    } else if ($value === WARN) {
        return "\e[0;33mWARN\e[0m";
    } else {
        return $value;
    }
}

/**
 * Conditionally escape a message to be shown as plan text based on the environment.
 *
 * @param string $string
 * @return string
 */
function escape($string)
{
    if ('cli' !== PHP_SAPI) {
        return htmlspecialchars($string);
    } else {
        return $string;
    }
}

function render($message, $value)
{
    $width = TEXT_WIDTH;
    printf("- %-${width}s [%s]%s", escape($message), result($value), PHP_EOL);
}

function renderSuccessOrFailure($message, $value)
{
    render($message, $value ? OK : FAIL);
}

/**
 * Adds an indented sub-paragraph after a check to add additional hints.
 *
 * @param string $message
 */
function sub_paragraph($message)
{
    $wrapped = wordwrap(sprintf('  > %s%s', remove_newline($message), PHP_EOL), TEXT_WIDTH - 4, PHP_EOL . '    ');
    echo escape($wrapped);
}

/**
 * Removes new lines characters from $message and replace them with ' '.
 *
 * @param string $message
 * @return string
 */
function remove_newline($message)
{
    return str_replace(PHP_EOL, ' ', $message);
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
    renderSuccessOrFailure('Agent can receive traces', $success);
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
renderSuccessOrFailure('ddtrace extension installed', extension_loaded('ddtrace') || extension_loaded('dd_trace'));
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
renderSuccessOrFailure('ddtrace versions in sync', $versionInstalled === $versionConst && $versionConst === $versionUserland);
renderSuccessOrFailure('dd_trace() function available', function_exists('dd_trace'));
renderSuccessOrFailure('dd_trace_env_config() function available', function_exists('dd_trace_env_config'));

renderSuccessOrFailure('ddtrace.request_init_hook set', !empty($initHook));
$initHookReachable = quiet_file_exists($initHook);
renderSuccessOrFailure('ddtrace.request_init_hook reachable', $initHookReachable);
$openBaseDirs = ini_get('open_basedir') ? explode(':', ini_get('open_basedir')) : [];
if ($initHookReachable) {
    $initHookHasRun = function_exists('DDTrace\\Bridge\\dd_wrap_autoloader');
    renderSuccessOrFailure('ddtrace.request_init_hook has run', $initHookHasRun);
} elseif($initHook && $openBaseDirs) {
    $initHookDir = dirname($initHook);
    if (!in_array($initHookDir, $openBaseDirs)) {
        $hint = <<<EOT
Ini directive 'open_basedir' has been set but it does not include the directory where
the extension is installed. This prevents our extension PHP code to be executed.
After consulting with your system admin, you might
want to add the path to the folder where the extension is installed to the ini directive 'open_basedir'.
EOT;
        sub_paragraph($hint);
    }
} else {
    render('open_basedir INI directive', ini_get('open_basedir') ?: 'empty');
}

// open_basedir prevents/allows access to '/proc/self/cgroup'
$isProcSelfForbiddenByOpenBaseDir = !empty($openBaseDirs) && !in_array('/proc/self/', $openBaseDirs);
render("'open_basedir' allows access to '/proc/self/'", $isProcSelfForbiddenByOpenBaseDir ? WARN : OK);
if ($isProcSelfForbiddenByOpenBaseDir) {
    $hint = <<<EOT
Directive 'open_basedir' prevents access to '/proc/self/cgroup' that is used to extract container info.
If your app does not run in a containerized environment ignore this message. If your app runs in a containerized
environment, e.g. Docker or k8s, you might want to add '/proc/self/' to 'open_basedir' in order to have container
info added to your tracer metadata.
EOT;
    sub_paragraph($hint);
}

class AutoloadTest
{
    public static function load($class)
    {
        var_dump($class);
    }
}

$integrationsLoaderExists = class_exists('\\DDTrace\\Integrations\\IntegrationsLoader');
renderSuccessOrFailure('IntegrationsLoader exists', $integrationsLoaderExists);
if ($integrationsLoaderExists) {
    $notLoaded = \DDTrace\Integrations\IntegrationsLoader::get()->getLoadingStatus('web');
    renderSuccessOrFailure('Integrations not loaded yet', 0 === $notLoaded);

    echo '- Registering an autoloader...' . PHP_EOL;
    spl_autoload_register('AutoloadTest::load');

    $loaded = \DDTrace\Integrations\IntegrationsLoader::get()->getLoadingStatus('web');
    renderSuccessOrFailure('Integrations loaded', 0 !== $loaded);
}

renderSuccessOrFailure('DDTrace\\Tracer class exists', class_exists('\\DDTrace\\Tracer'));

check_agent_connectivity();

if ('cli' !== PHP_SAPI) {
    echo '</pre>' . PHP_EOL;
    echo '</body>' . PHP_EOL;
    echo '</html>' . PHP_EOL;
}

echo PHP_EOL;
