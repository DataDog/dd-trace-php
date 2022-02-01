<?php

// Tests for the installer are in 'dockerfiles/verify_packages/installer'

const INI_CONF = 'Scan this dir for additional .ini files';
const EXTENSION_DIR = 'extension_dir';
const THREAD_SAFETY = 'Thread Safety';
const PHP_API = 'PHP API';
const IS_DEBUG = 'Debug Build';

// Options
const OPT_HELP = 'help';
const OPT_INSTALL_DIR = 'install-dir';
const OPT_PHP_BIN = 'php-bin';
const OPT_FILE = 'file';
const OPT_UNINSTALL = 'uninstall';
const OPT_ENABLE_PROFILING = 'enable-profiling';

// Release version is set while generating the final release files
const RELEASE_VERSION = '@release_version@';

// Supported platforms
const PLATFORM_X86_LINUX_GNU = 'x86_64-linux-gnu';
const PLATFORM_X86_LINUX_MUSL = 'x86_64-linux-musl';

function main()
{
    if (is_truthy(getenv('DD_TEST_EXECUTION'))) {
        return;
    }

    $options = parse_validate_user_options();
    if ($options[OPT_UNINSTALL]) {
        uninstall($options);
    } else {
        install($options);
    }
}

function print_help()
{
    echo <<<EOD

Usage:
    Interactive
        php get-dd-trace.php x.y.z ...
    Non-Interactive
        php get-dd-trace.php x.y.z --php-bin php ...
        php get-dd-trace.php x.y.z --php-bin php --php-bin /usr/local/sbin/php-fpm ...

Options:
    -h, --help                  Print this help text and exit
    --php-bin all|<path to php> Install the library to the specified binary or all php binaries in standard search
                                paths. The option can be provided multiple times.
    --install-dir <path>        Install to a specific directory. Default: '/opt/datadog'
    --uninstall                 Uninstall the library from the specified binaries
    --enable-profiling          Enable the BETA profiling module.

EOD;
}

function install($options)
{
    $platform = is_alpine() ? PLATFORM_X86_LINUX_MUSL : PLATFORM_X86_LINUX_GNU;

    // Checking required libraries
    check_library_prerequisite_or_exit('libcurl');
    if (is_alpine()) {
        check_library_prerequisite_or_exit('libexecinfo');
    }

    // Picking the right binaries to install the library
    $selectedBinaries = require_binaries_or_exit($options);
    $interactive = empty($options[OPT_PHP_BIN]);

    // Preparing clean tmp folder to extract files
    $tmpDir = sys_get_temp_dir() . '/dd-install';
    $tmpDirTarGz = $tmpDir . "/dd-library-php-{$platform}.tar.gz";
    $tmpArchiveRoot = $tmpDir . '/dd-library-php';
    $tmpArchiveTraceRoot = $tmpDir . '/dd-library-php/trace';
    $tmpArchiveProfilingRoot = $tmpDir . '/dd-library-php/profiling';
    $tmpBridgeDir = $tmpArchiveTraceRoot . '/bridge';
    execute_or_exit("Cannot create directory '$tmpDir'", "mkdir -p " . escapeshellarg($tmpDir));
    register_shutdown_function(function () use ($tmpDir) {
        execute_or_exit("Cannot remove temporary directory '$tmpDir'", "rm -rf " . escapeshellarg($tmpDir));
    });
    execute_or_exit(
        "Cannot clean '$tmpDir'",
        "rm -rf " . escapeshellarg($tmpDir) . "/* "
    );

    // Retrieve and extract the archive to a tmp location
    if (isset($options[OPT_FILE])) {
        print_warning('--' . OPT_FILE . ' option is intended for internal usage and can be removed without notice');
        $tmpDirTarGz = $options[OPT_FILE];
    } else {
        $version = RELEASE_VERSION;
        // phpcs:disable Generic.Files.LineLength.TooLong
        $url = "https://github.com/DataDog/dd-trace-php/releases/download/{$version}/dd-library-php-{$version}-{$platform}.tar.gz";
        // phpcs:enable Generic.Files.LineLength.TooLong
        download($url, $tmpDirTarGz);
        unset($version);
    }
    execute_or_exit(
        "Cannot extract the archive",
        "tar -xf " . escapeshellarg($tmpDirTarGz) . " -C " . escapeshellarg($tmpDir)
    );

    $releaseVersion = trim(file_get_contents("$tmpArchiveRoot/VERSION"));

    $installDir = $options[OPT_INSTALL_DIR] . '/' . $releaseVersion;
    $installDirSourcesDir = $installDir . '/dd-trace-sources';
    $installDirBridgeDir = $installDirSourcesDir . '/bridge';
    $installDirWrapperPath = $installDirBridgeDir . '/dd_wrap_autoloader.php';

    // copying sources to the final destination
    execute_or_exit(
        "Cannot create directory '$installDirSourcesDir'",
        "mkdir -p " . escapeshellarg($installDirSourcesDir)
    );
    execute_or_exit(
        "Cannot copy files from '$tmpBridgeDir' to '$installDirSourcesDir'",
        "cp -r " . escapeshellarg("$tmpBridgeDir") . ' ' . escapeshellarg($installDirSourcesDir)
    );
    echo "Installed required source files to '$installDir'\n";

    // Actual installation
    foreach ($selectedBinaries as $command => $fullPath) {
        $binaryForLog = ($command === $fullPath) ? $fullPath : "$command ($fullPath)";
        echo "Installing to binary: $binaryForLog\n";

        $phpMajorMinor = get_php_major_minor($fullPath);

        check_php_ext_prerequisite_or_exit($fullPath, 'json');

        $phpProperties = ini_values($fullPath);
        if (is_truthy($phpProperties[THREAD_SAFETY]) && is_truthy($phpProperties[IS_DEBUG])) {
            print_error_and_exit('(ZTS DEBUG) builds of PHP are currently not supported');
        }

        // Copying the extension
        $extensionVersion = $phpProperties[PHP_API];

        // Suffix (zts/debug/alpine)
        $extensionSuffix = '';
        if (is_truthy($phpProperties[IS_DEBUG])) {
            $extensionSuffix = '-debug';
        } elseif (is_truthy($phpProperties[THREAD_SAFETY])) {
            $extensionSuffix = '-zts';
        }

        // Trace
        $extensionRealPath = "$tmpArchiveTraceRoot/ext/$extensionVersion/ddtrace$extensionSuffix.so" ;
        $extensionDestination = $phpProperties[EXTENSION_DIR] . '/ddtrace.so';
        safe_copy_extension($extensionRealPath, $extensionDestination);

        // Profiling
        $shouldInstallProfiling =
            in_array($phpMajorMinor, ['7.1', '7.2', '7.3', '7.4', '8.0'])
            && !is_truthy($phpProperties[THREAD_SAFETY])
            && !is_truthy($phpProperties[IS_DEBUG]);

        if ($shouldInstallProfiling) {
            $profilingExtensionRealPath = "$tmpArchiveProfilingRoot/ext/$extensionVersion/datadog-profiling.so";
            $profilingExtensionDestination = $phpProperties[EXTENSION_DIR] . '/datadog-profiling.so';
            safe_copy_extension($profilingExtensionRealPath, $profilingExtensionDestination);
        }

        // Writing the ini file
        $iniFileName = '98-ddtrace.ini';
        $iniFilePaths = [$phpProperties[INI_CONF] . '/' . $iniFileName];
        if (\strpos($phpProperties[INI_CONF], '/cli/conf.d') !== false) {
            /* debian based distros have INI folders split by SAPI, in a predefined way:
             *   - <...>/cli/conf.d       <-- we know this from php -i
             *   - <...>/apache2/conf.d   <-- we derive this from relative path
             *   - <...>/fpm/conf.d       <-- we derive this from relative path
             */
            $apacheConfd = str_replace('/cli/conf.d', '/apache2/conf.d', $phpProperties[INI_CONF]);
            if (\is_dir($apacheConfd)) {
                array_push($iniFilePaths, "$apacheConfd/$iniFileName");
            }
        }
        foreach ($iniFilePaths as $iniFilePath) {
            if (!file_exists($iniFilePath)) {
                $iniDir = dirname($iniFilePath);
                execute_or_exit(
                    "Cannot create directory '$iniDir'",
                    "mkdir -p " . escapeshellarg($iniDir)
                );

                if (false === file_put_contents($iniFilePath, '')) {
                    print_error_and_exit("Cannot create INI file $iniFilePath");
                }
                echo "Created INI file '$iniFilePath'\n";
            } else {
                echo "Updating existing INI file '$iniFilePath'\n";
                // phpcs:disable Generic.Files.LineLength.TooLong
                execute_or_exit(
                    'Impossible to replace the deprecated ddtrace.request_init_hook parameter with the new name.',
                    "sed -i 's|ddtrace.request_init_hook|datadog.trace.request_init_hook|g' " . escapeshellarg($iniFilePath)
                );
                execute_or_exit(
                    'Impossible to update the INI settings file.',
                    "sed -i 's@datadog\.trace\.request_init_hook \?= \?\(.*\)@datadog.trace.request_init_hook = '" . escapeshellarg($installDirWrapperPath) . "'@g' " . escapeshellarg($iniFilePath)
                );
                // phpcs:enable Generic.Files.LineLength.TooLong

                /* In order to support upgrading from legacy installation method to new installation method, we replace
                 * "extension = /opt/datadog-php/xyz.so" with "extension =  ddtrace.so" honoring trailing `;`, hence not
                 * automatically re-activating the extension if the user had commented it out.
                 */
                execute_or_exit(
                    'Impossible to update the INI settings file.',
                    "sed -i 's@extension \?= \?.*ddtrace.*\(.*\)@extension = ddtrace.so@g' "
                        . escapeshellarg($iniFilePath)
                );
            }

            add_missing_ini_settings($iniFilePath, get_ini_settings($installDirWrapperPath));

            // Enabling profiling
            if (is_truthy($options[OPT_ENABLE_PROFILING])) {
                // phpcs:disable Generic.Files.LineLength.TooLong
                if ($shouldInstallProfiling) {
                    execute_or_exit(
                        'Impossible to update the INI settings file.',
                        "sed -i 's@ \?; \?zend_extension \?= \?datadog-profiling.so@zend_extension = datadog-profiling.so@g' "
                            . escapeshellarg($iniFilePath)
                    );
                } else {
                    $enable_profiling = OPT_ENABLE_PROFILING;
                    print_error_and_exit("Option --{$enable_profiling} was provided, but it is not supported on this PHP build or version.\n");
                }
                // phpcs:enable Generic.Files.LineLength.TooLong
            }

            echo "Installation to '$binaryForLog' was successful\n";
        }
    }

    echo "--------------------------------------------------\n";
    echo "SUCCESS\n\n";
    if ($interactive) {
        echo "To run this script in a non interactive mode, use the following options:\n";
        $args = array_merge(
            $_SERVER["argv"],
            array_map(
                function ($el) {
                    return '--php-bin=' . $el;
                },
                array_keys($selectedBinaries)
            )
        );
        echo "  php " . implode(" ", array_map("escapeshellarg", $args)) . "\n";
    }
}

/**
 * Copies an extension's file to a destination using copy+rename to avoid segfault if the file is loaded by php.
 *
 * @param string $source
 * @param string $destination
 * @return void
 */
function safe_copy_extension($source, $destination)
{
    /* Move - rename() - instead of copy() since copying does a fopen() and copies to the stream itself, causing a
    * segfault in the PHP process that is running and had loaded the old shared object file.
    */
    $tmpName = $destination . '.tmp';
    copy($source, $tmpName);
    rename($tmpName, $destination);
    echo "Copied '$source' to '$destination'\n";
}

function uninstall($options)
{
    $selectedBinaries = require_binaries_or_exit($options);

    foreach ($selectedBinaries as $command => $fullPath) {
        $binaryForLog = ($command === $fullPath) ? $fullPath : "$command ($fullPath)";
        echo "Uninstalling from binary: $binaryForLog\n";

        $phpProperties = ini_values($fullPath);

        $extensionDestinations = [
            $phpProperties[EXTENSION_DIR] . '/ddtrace.so',
            $phpProperties[EXTENSION_DIR] . '/datadog-profiling.so',
        ];

        // Writing the ini file
        $iniFileName = '98-ddtrace.ini';
        $iniFilePaths = [$phpProperties[INI_CONF] . '/' . $iniFileName];
        if (\strpos('/cli/conf.d', $phpProperties[INI_CONF]) >= 0) {
            /* debian based distros have INI folders split by SAPI, in a predefined way:
             *   - <...>/cli/conf.d       <-- we know this from php -i
             *   - <...>/apache2/conf.d    <-- we derive this from relative path
             *   - <...>/fpm/conf.d       <-- we derive this from relative path
             */
            $apacheConfd = str_replace('/cli/conf.d', '/apache2/conf.d', $phpProperties[INI_CONF]);
            if (\is_dir($apacheConfd)) {
                array_push($iniFilePaths, "$apacheConfd/$iniFileName");
            }
        }

        /* Actual uninstall
         *  1) comment out extension=ddtrace.so
         *  2) remove ddtrace.so
         */
        foreach ($iniFilePaths as $iniFilePath) {
            if (file_exists($iniFilePath)) {
                execute_or_exit(
                    "Impossible to disable PHP modules from '$iniFilePath'. You can disable them manually.",
                    "sed -i 's@^extension \?=@;extension =@g' " . escapeshellarg($iniFilePath)
                );
                execute_or_exit(
                    "Impossible to disable Zend modules from '$iniFilePath'. You can disable them manually.",
                    "sed -i 's@^zend_extension \?=@;zend_extension =@g' " . escapeshellarg($iniFilePath)
                );
                echo "Disabled all modules in INI file '$iniFilePath'. "
                    . "The file has not been removed to preserve custom settings.\n";
            }
        }
        $errors = false;
        foreach ($extensionDestinations as $extensionDestination) {
            if (file_exists($extensionDestination) && false === unlink($extensionDestination)) {
                print_warning("Error while removing $extensionDestination. It can be manually removed.");
                $errors = true;
            }
        }
        if ($errors) {
            echo "Uninstall from '$binaryForLog' was completed with warnings\n";
        } else {
            echo "Uninstall from '$binaryForLog' was successful\n";
        }
    }
}

/**
 * Returns a list of php binaries where the library will be installed. If not explicitly provided by the CLI options,
 * then the list is retrieved using an interactive session.
 *
 * @param array $options
 * @return array
 */
function require_binaries_or_exit($options)
{
    $selectedBinaries = [];
    if (empty($options[OPT_PHP_BIN])) {
        $selectedBinaries = pick_binaries_interactive(search_php_binaries());
    } else {
        foreach ($options[OPT_PHP_BIN] as $command) {
            if ($command == "all") {
                $selectedBinaries += search_php_binaries();
            } elseif ($resolvedPath = resolve_command_full_path($command)) {
                $selectedBinaries[$command] = $resolvedPath;
            } else {
                print_error_and_exit("Provided PHP binary '$command' was not found.\n");
            }
        }
    }

    if (empty($selectedBinaries)) {
        print_error_and_exit("At least one binary must be specified\n");
    }

    return $selectedBinaries;
}

/**
 * Checks if a library is available or not in an OS-independent way.
 *
 * @param string $requiredLibrary E.g. libcurl
 * @return void
 */
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
        print_error_and_exit("Required library '$requiredLibrary' not found.\n");
    }
}

/**
 * Checks if an extension is enabled or not.
 *
 * @param string $binary
 * @param string $requiredLibrary E.g. json
 * @return void
 */
function check_php_ext_prerequisite_or_exit($binary, $extName)
{
    $lastLine = execute_or_exit(
        "Cannot retrieve extensions list",
        // '|| true' is necessary because grep exits with 1 if the pattern was not found.
        "$binary -m | grep '$extName' || true"
    );


    if (empty($lastLine)) {
        print_error_and_exit("Required PHP extension '$extName' not found.\n");
    }
}

/**
 * @return bool
 */
function is_alpine()
{
    $osInfoFile = '/etc/os-release';
    // if /etc/os-release is not readable, we cannot tell and we assume NO
    if (!is_readable($osInfoFile)) {
        return false;
    }
    return false !== stripos(file_get_contents($osInfoFile), 'alpine');
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
        OPT_PHP_BIN . ':',
        OPT_FILE . ':',
        OPT_INSTALL_DIR . ':',
        OPT_UNINSTALL,
        OPT_ENABLE_PROFILING,
    ];
    $options = getopt($shortOptions, $longOptions);

    // Help and exit
    if (key_exists('h', $options) || key_exists(OPT_HELP, $options)) {
        print_help();
        exit(0);
    }

    $normalizedOptions = [];

    $normalizedOptions[OPT_UNINSTALL] = isset($options[OPT_UNINSTALL]) ? true : false;

    if (!$normalizedOptions[OPT_UNINSTALL]) {
        if (isset($options[OPT_FILE])) {
            if (is_array($options[OPT_FILE])) {
                print_error_and_exit('Only one --file can be provided', true);
            }
            $normalizedOptions[OPT_FILE] = $options[OPT_FILE];
        }
    }

    if (isset($options[OPT_PHP_BIN])) {
        $normalizedOptions[OPT_PHP_BIN] =
            is_array($options[OPT_PHP_BIN])
            ? $options[OPT_PHP_BIN]
            : [$options[OPT_PHP_BIN]];
    }

    $normalizedOptions[OPT_INSTALL_DIR] =
        isset($options[OPT_INSTALL_DIR])
        ? rtrim($options[OPT_INSTALL_DIR], '/')
        : '/opt/datadog';
    $normalizedOptions[OPT_INSTALL_DIR] =  $normalizedOptions[OPT_INSTALL_DIR] . '/dd-library';

    $normalizedOptions[OPT_ENABLE_PROFILING] = isset($options[OPT_ENABLE_PROFILING]);

    return $normalizedOptions;
}

function print_error_and_exit($message, $printHelp = false)
{
    echo "ERROR: $message\n";
    if ($printHelp) {
        print_help();
    }
    exit(1);
}

function print_warning($message)
{
    echo "WARNING: $message\n";
}

/**
 * Given a certain set of available PHP binaries, let users pick in an interactive way the ones where the library
 * should be installed to.
 *
 * @param array $php_binaries
 * @return array
 */
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

    echo "Select binaries using their number. Multiple binaries separated by space (example: 1 3): ";
    $userInput = fgets(STDIN);
    $choices = array_map('intval', array_filter(explode(' ', $userInput)));

    $pickedBinaries = [];
    foreach ($choices as $choice) {
        $index = $choice - 1; // we render to the user as 1-indexed
        if (!isset($commands[$index])) {
            echo "\nERROR: Wrong choice: $choice\n\n";
            return pick_binaries_interactive($php_binaries);
        }
        $command = $commands[$index];
        $pickedBinaries[$command] = $php_binaries[$command];
    }

    return $pickedBinaries;
}

function execute_or_exit($exitMessage, $command)
{
    $output = [];
    $returnCode = 0;
    $lastLine = exec($command, $output, $returnCode);
    if (false === $lastLine || $returnCode > 0) {
        print_error_and_exit(
            $exitMessage .
                "\nFailed command (return code $returnCode): $command\n---- Output ----\n" .
                implode("\n", $output) .
                "\n---- End of output ----\n"
        );
    }

    return $lastLine;
}

/**
 * Downloads the library applying a number of fallback mechanisms if specific libraries/binaries are not available.
 *
 * @param string $url
 * @param string $destination
 */
function download($url, $destination)
{
    echo "Downloading installable archive from $url.\n";
    echo "This operation might take a while.\n";

    $okMessage = "\nDownload completed\n\n";

    /* We try the following options, mostly to provide progress report, if possible:
     *   1) `ext-curl` (with progress report); if 'ext-curl' is not installed...
     *   2) `curl` from CLI (it shows progress); if `curl` is not installed...
     *   3) `file_get_contents()` (no progress report); if `allow_url_fopen=0`...
     *   4) exit with errror
     */

    // ext-curl
    if (extension_loaded('curl')) {
        if (false === $fp = fopen($destination, 'w+')) {
            print_error_and_exit("Error while opening target file '$destination' for writing\n");
        }
        global $progress_counter;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 50);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, 'on_download_progress');
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        $progress_counter = 0;
        $return = curl_exec($ch);
        curl_close($ch);
        fclose($fp);

        if (false !== $return) {
            echo $okMessage;
            return;
        }
        // Otherwise we attempt other methods
    }

    // curl
    $statusCode = 0;
    $output = [];
    if (false !== exec('curl --version', $output, $statusCode) && $statusCode === 0) {
        $curlInvocationStatusCode = 0;
        system(
            'curl -L --output ' . escapeshellarg($destination) . ' ' . escapeshellarg($url),
            $curlInvocationStatusCode
        );

        if ($curlInvocationStatusCode === 0) {
            echo $okMessage;
            return;
        }
        // Otherwise we attempt other methods
    }

    // file_get_contents
    if (is_truthy(ini_get('allow_url_fopen'))) {
        if (false === file_put_contents($destination, file_get_contents($url))) {
            print_error_and_exit("Error while downloading the installable archive from $url\n");
        }

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

/**
 * Progress callback as specified by the ext-curl documentation.
 *   see: https://www.php.net/manual/en/function.curl-setopt.php#:~:text=CURLOPT_PROGRESSFUNCTION
 *
 * @return int
 */
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

/**
 * Extracts and normalizes a set of properties from PHP's ini values.
 *
 * @param string $binary
 * @return array
 */
function ini_values($binary)
{
    $properties = [INI_CONF, EXTENSION_DIR, THREAD_SAFETY, PHP_API, IS_DEBUG];
    $lines = [];
    // Timezone is irrelevant to this script. Quick-and-dirty workaround to the PHP 5 warning with missing timezone
    exec(escapeshellarg($binary) . " -d date.timezone=UTC -i", $lines);
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
 * Adds ini entries that are not present in the provided ini file.
 *
 * @param string $iniFilePath
 */
function add_missing_ini_settings($iniFilePath, $settings)
{
    $iniFileContent = file_get_contents($iniFilePath);
    $formattedMissingProperties = '';

    foreach ($settings as $setting) {
        $settingMightExist = 1 === preg_match(
            '/' . str_replace('.', '\.', $setting['name']) . '\s?=\s?/',
            $iniFileContent
        );
        if ($settingMightExist) {
            continue;
        }

        // Formatting the setting to be added.
        $description =
            is_string($setting['description'])
                ? '; ' . $setting['description']
                : implode(
                    "\n",
                    array_map(
                        function ($line) {
                            return '; ' . $line;
                        },
                        $setting['description']
                    )
                );
        $setting = ($setting['commented'] ? ';' : '') . $setting['name'] . ' = ' . $setting['default'];
        $formattedMissingProperties .= "\n$description\n$setting\n";
    }

    if ($formattedMissingProperties !== '') {
        if (false === file_put_contents($iniFilePath, $iniFileContent . $formattedMissingProperties)) {
            print_error_and_exit("Cannot add additional settings to the INI file $iniFilePath");
        }
    }
}

function get_php_major_minor($binary)
{
    return execute_or_exit(
        "Cannot read PHP version",
        "$binary -v | grep -oE 'PHP [[:digit:]]+.[[:digit:]]+' | awk '{print \$NF}'"
    );
}

/**
 * Returns array of associative arrays with the following keys:
 *   - name (string): the setting name;
 *   - default (string): the default value;
 *   - commented (bool): whether this setting should be commented or not when added;
 *   - description (string|string[]): A string (or an array of strings, each representing a line) that describes
 *                                    the setting.
 *
 * @param string $requestInitHookPath
 * @return array
 */
function get_ini_settings($requestInitHookPath)
{
    // phpcs:disable Generic.Files.LineLength.TooLong
    return [
        [
            'name' => 'extension',
            'default' => 'ddtrace.so',
            'commented' => false,
            'description' => 'Enables or disables tracing (set by the installer, do not change it)',
        ],
        [
            'name' => 'zend_extension',
            'default' => 'datadog-profiling.so',
            'commented' => true,
            'description' => 'Enables the profiling module',
        ],
        [
            'name' => 'datadog.trace.request_init_hook',
            'default' => $requestInitHookPath,
            'commented' => false,
            'description' => 'Path to the request init hook (set by the installer, do not change it)',
        ],
        [
            'name' => 'datadog.trace.enabled',
            'default' => 'On',
            'commented' => true,
            'description' => 'Enables or disables tracing. On by default',
        ],
        [
            'name' => 'datadog.trace.cli_enabled',
            'default' => 'Off',
            'commented' => true,
            'description' => 'Enable or disable tracing of CLI scripts. Off by default',
        ],
        [
            'name' => 'datadog.trace.auto_flush_enabled',
            'default' => 'Off',
            'commented' => true,
            'description' => 'For long running processes, this setting has to be set to On',
        ],
        [
            'name' => 'datadog.trace.generate_root_span',
            'default' => 'On',
            'commented' => true,
            'description' => 'For long running processes, this setting has to be set to Off',
        ],
        [
            'name' => 'datadog.trace.debug',
            'default' => 'Off',
            'commented' => true,
            'description' => 'Enables or disables debug mode.  When On logs are printed to the error_log',
        ],
        [
            'name' => 'datadog.trace.startup_logs',
            'default' => 'On',
            'commented' => true,
            'description' => 'Enables startup logs, including diagnostic checks',
        ],
        [
            'name' => 'datadog.service',
            'default' => 'unnamed-php-service',
            'commented' => true,
            'description' => 'Sets a custom service name for the application',
        ],
        [
            'name' => 'datadog.env',
            'default' => 'my_env',
            'commented' => true,
            'description' => 'Sets a custom environment name for the application',
        ],
        [
            'name' => 'datadog.version',
            'default' => '1.0.0',
            'commented' => true,
            'description' => 'Sets a version for the user application, not the datadog php library',
        ],
        [
            'name' => 'datadog.agent_host',
            'default' => '127.0.0.1',
            'commented' => true,
            'description' => 'Configures the agent host. If you need more flexibility use `datadog.trace.agent_url` instead',
        ],
        [
            'name' => 'datadog.trace.agent_port',
            'default' => '8126',
            'commented' => true,
            'description' => 'Configures the agent port. If you need more flexibility use `datadog.trace.agent_url` instead',
        ],
        [
            'name' => 'datadog.dogstatsd_port',
            'default' => '8125',
            'commented' => true,
            'description' => 'Configures the dogstatsd agent port',
        ],
        [
            'name' => 'datadog.trace.agent_url',
            'default' => 'http://127.0.0.1:8126',
            'commented' => true,
            'description' => 'When set, `datadog.trace.agent_url` has priority over `datadog.agent_host` and `datadog.trace.agent_port`',
        ],
        [
            'name' => 'datadog.trace.http_client_split_by_domain',
            'default' => 'Off',
            'commented' => true,
            'description' => 'Sets the service name of spans generated for HTTP clients\' requests to host-<hostname>',
        ],
        [
            'name' => 'datadog.trace.url_as_resource_names_enabled',
            'default' => 'On',
            'commented' => true,
            'description' => [
                'Enables URL to resource name normalization. For more details see:',
                'https://docs.datadoghq.com/tracing/setup_overview/setup/php/?tab=containers#map-resource-names-to-normalized-uri',
            ],
        ],
        [
            'name' => 'datadog.trace.resource_uri_fragment_regex',
            'default' => '',
            'commented' => true,
            'description' => [
                'Configures obfuscation patterns based on regex. For more details see:',
                'https://docs.datadoghq.com/tracing/setup_overview/setup/php/?tab=containers#map-resource-names-to-normalized-uri',
            ],
        ],
        [
            'name' => 'datadog.trace.resource_uri_mapping_incoming',
            'default' => '',
            'commented' => true,
            'description' => [
                'Configures obfuscation path fragments for incoming requests. For more details see:',
                'https://docs.datadoghq.com/tracing/setup_overview/setup/php/?tab=containers#map-resource-names-to-normalized-uri',
            ],
        ],
        [
            'name' => 'datadog.trace.resource_uri_mapping_outgoing',
            'default' => '',
            'commented' => true,
            'description' => [
                'Configures obfuscation path fragments for outgoing requests. For more details see:',
                'https://docs.datadoghq.com/tracing/setup_overview/setup/php/?tab=containers#map-resource-names-to-normalized-uri',
            ],
        ],
        [
            'name' => 'datadog.service_mapping',
            'default' => '',
            'commented' => true,
            'description' => [
                'Changes the default name of an APM integration. Rename one or more integrations at a time, for example:',
                '"pdo:payments-db,mysqli:orders-db"',
            ],
        ],
        [
            'name' => 'datadog.tags',
            'default' => '',
            'commented' => true,
            'description' => 'Tags to be set on all spans, for example: "key1:value1,key2:value2"',
        ],
        [
            'name' => 'datadog.trace.sample_rate',
            'default' => '1.0',
            'commented' => true,
            'description' => 'The sampling rate for the trace. Valid values are between 0.0 and 1.0',
        ],
        [
            'name' => 'datadog.trace.sample_rate',
            'default' => '1.0',
            'commented' => true,
            'description' => 'The sampling rate for the trace. Valid values are between 0.0 and 1.0',
        ],
        [
            'name' => 'datadog.trace.sampling_rules',
            'default' => '',
            'commented' => true,
            'description' => [
                'A JSON encoded string to configure the sampling rate.',
                'Examples:',
                '  - Set the sample rate to 20%: \'[{"sample_rate": 0.2}]\'.',
                '  - Set the sample rate to 10% for services starting with `a` and span name `b` and set the sample rate to 20%',
                '    for all other services: \'[{"service": "a.*", "name": "b", "sample_rate": 0.1}, {"sample_rate": 0.2}]\'',
                '**Note** that the JSON object must be included in single quotes (\') to avoid problems with escaping of the',
                'double quote (") character.',
            ],
        ],
        [
            'name' => 'datadog.trace.<integration_name>_enabled',
            'default' => 'On',
            'commented' => true,
            'description' => [
                'Whether a specific integration is enabled.',
                'Integrations names available at: see https://docs.datadoghq.com/tracing/setup_overview/setup/php/?tab=containers#integration-names',
            ],
        ],
        [
            'name' => 'datadog.trace.<integration_name>_analytics_enabled',
            'default' => 'Off',
            'commented' => true,
            'description' => [
                'Whether analytics for the integration is enabled.',
                'Integrations names available at: see https://docs.datadoghq.com/tracing/setup_overview/setup/php/?tab=containers#integration-names',
            ],
        ],
        [
            'name' => 'datadog.trace.<integration_name>_analytics_sample_rate',
            'default' => '1.0',
            'commented' => true,
            'description' => [
                'Sampling rate for analyzed spans. Valid values are between 0.0 and 1.0.',
                'Integrations names available at: see https://docs.datadoghq.com/tracing/setup_overview/setup/php/?tab=containers#integration-names',
            ],
        ],
        [
            'name' => 'datadog.distributed_tracing',
            'default' => 'On',
            'commented' => true,
            'description' => 'Enables distributed tracing',
        ],
        [
            'name' => 'datadog.trace.analytics_enabled',
            'default' => 'Off',
            'commented' => true,
            'description' => 'Global switch for trace analytics',
        ],
        [
            'name' => 'datadog.trace.bgs_connect_timeout',
            'default' => '2000',
            'commented' => true,
            'description' => 'Set connection timeout in milliseconds while connecting to the agent',
        ],
        [
            'name' => 'datadog.trace.bgs_timeout',
            'default' => '5000',
            'commented' => true,
            'description' => 'Set request timeout in milliseconds while sending payloads to the agent',
        ],
        [
            'name' => 'datadog.trace.spans_limit',
            'default' => '1000',
            'commented' => true,
            'description' => 'datadog.trace.spans_limit = 1000',
        ],
        [
            'name' => 'datadog.trace.retain_thread_capabilities',
            'default' => 'Off',
            'commented' => true,
            'description' => [
                'Only for Linux. Set to `true` to retain capabilities on Datadog background threads when you change the effective',
                'user ID. This option does not affect most setups, but some modules - to date Datadog is only aware of Apache`s',
                'mod-ruid2 - may invoke `setuid()` or similar syscalls, leading to crashes or loss of functionality as it loses',
                'capabilities.',
                '**Note** Enabling this option may compromise security. This option, standalone, does not pose a security risk.',
                'However, an attacker being able to exploit a vulnerability in PHP or web server may be able to escalate privileges',
                'with relative ease, if the web server or PHP were started with full capabilities, as the background threads will',
                'retain their original capabilities. Datadog recommends restricting the capabilities of the web server with the',
                'setcap utility.',
            ],
        ],
    ];
    // phpcs:enable Generic.Files.LineLength.TooLong
}

/**
 * @return string[]
 */
function get_supported_php_versions()
{
    return ['5.4', '5.5', '5.6', '7.0', '7.1', '7.2', '7.3', '7.4', '8.0', '8.1'];
}

main();
