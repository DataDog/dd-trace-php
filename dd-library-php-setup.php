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
const OPT_NO_TRACER = 'no-tracer';
const OPT_APPSEC_FILE = 'appsec-file';
const OPT_APPSEC_URL = 'appsec-url';
const OPT_APPSEC_VERSION = 'appsec-version';
const OPT_NO_APPSEC = 'no-appsec';
const OPT_UNINSTALL = 'uninstall';

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

function print_help_and_exit()
{
    echo <<<EOD

Usage:
    Interactive
        php get-dd-trace.php --tracer-version x.y.z ...
    Non-Interactive
        php get-dd-trace.php --tracer-version x.y.z --php-bin php ...
        php get-dd-trace.php --tracer-version x.y.z --php-bin php --php-bin /usr/local/sbin/php-fpm ...

Options:
    -h, --help                  Print this help text and exit
    --php-bin all|<path to php> Install the library to the specified binary or all php binaries in standard search
                                paths. The option can be provided multiple times.
    --tracer-version <0.1.2>    Install a specific version. If set --tracer-url and --tracer-file are ignored.
    --tracer-url <url>          Install the tracing library from a url. If set --tracer-file is ignored.
    --tracer-file <file>        Install the tracing library from a local .tar.gz file.
    --no-tracer                 Do not install the tracing library.
    --appsec-version <0.1.0>    Install a specific version of the appsec lib.
                                If set --tracer-url and --tracer-file are ignored.
    --appsec-url <url>          Install the appsec library from a url. If set --appsec-file is ignored.
    --appsec-file <file>        Install the appsec library from a local .tar.gz file.
    --no-appsec                 Do not install the appsec library.
    --install-dir <path>        Install to a specific directory. Default: '/opt/datadog'
    --uninstall                 Uninstall the library from the specified binaries

Using "--tracer-version latest" or "--appsec-version latest" will download the
latest releases. For each component, this will be implied if no version, url or
file are given.
EOD;
    exit(0);
}

function install($options)
{
    // Checking required libraries
    check_library_prerequisite_or_exit('libcurl');
    if (is_alpine()) {
        check_library_prerequisite_or_exit('libexecinfo');
    }

    // Picking the right binaries to install the library
    $selectedBinaries = require_binaries_or_exit($options);
    $interactive = empty($options[OPT_PHP_BIN]);

    install_tracer($options, $selectedBinaries);
    install_appsec($options, $selectedBinaries);

    echo "--------------------------------------------------\n";
    echo "SUCCESS\n\n";
    if ($interactive) {
        echo "Run this script in a non interactive mode adding the following 'php-bin' options:\n";
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

function install_tracer($options, $selectedBinaries)
{
    if ($options[OPT_NO_TRACER]) {
        return;
    }
    // Preparing clean tmp folder to extract files
    $tmpDir = sys_get_temp_dir() . '/dd-library';
    $tmpDirTarGz = $tmpDir . '/dd-trace-php.tar.gz';
    $tmpSourcesDir = $tmpDir . '/opt/datadog-php/dd-trace-sources';
    $tmpExtensionsDir = $tmpDir . '/opt/datadog-php/extensions';
    execute_or_exit("Cannot create directory '$tmpDir'", "mkdir -p " . escapeshellarg($tmpDir));
    execute_or_exit(
        "Cannot clean '$tmpDir'",
        "rm -rf " . escapeshellarg($tmpDir) . "/* "
    );
    delete_on_exit($tmpDir);

    if (
        !isset($options[OPT_TRACER_FILE]) &&
        !isset($options[OPT_TRACER_URL]) &&
        !isset($options[OPT_TRACER_VERSION]) ||
        isset($options[OPT_TRACER_VERSION]) && $options[OPT_TRACER_VERSION] == 'latest'
    ) {
        $options[OPT_TRACER_VERSION] = latest_release("dd-trace-php");
    }

    // Retrieve and extract the archive to a tmp location
    if (isset($options[OPT_TRACER_FILE])) {
        $tmpDirTarGz = $options[OPT_TRACER_FILE];
    } else {
        $url = isset($options[OPT_TRACER_URL])
            ? $options[OPT_TRACER_URL]
            : "https://github.com/DataDog/dd-trace-php/releases/download/" .
            $options[OPT_TRACER_VERSION] . "/datadog-php-tracer-" .
            $options[OPT_TRACER_VERSION] . ".x86_64.tar.gz";
        download($url, $tmpDirTarGz);
    }
    execute_or_exit(
        "Cannot extract the archive",
        "tar -xf " . escapeshellarg($tmpDirTarGz) . " -C " . escapeshellarg($tmpDir)
    );

    $installDir = $options[OPT_INSTALL_DIR] . '/' . extract_version_subdir_path($options, $tmpDir, $tmpSourcesDir);
    $installDirSourcesDir = $installDir . '/dd-trace-sources';
    $installDirWrapperPath = $installDirSourcesDir . '/bridge/dd_wrap_autoloader.php';

    // copying sources to the final destination
    execute_or_exit(
        "Cannot create directory '$installDirSourcesDir'",
        "mkdir -p " . escapeshellarg($installDirSourcesDir)
    );
    execute_or_exit(
        "Cannot copy files from '$tmpSourcesDir' to '$installDirSourcesDir'",
        "cp -r " . escapeshellarg("$tmpSourcesDir") . "/* " . escapeshellarg($installDirSourcesDir)
    );
    echo "Installed required source files to '$installDir'\n";

    // Actual installation
    foreach ($selectedBinaries as $command => $fullPath) {
        $binaryForLog = ($command === $fullPath) ? $fullPath : "$command ($fullPath)";
        echo "Installing tracer to binary: $binaryForLog\n";

        check_php_ext_prerequisite_or_exit($fullPath, 'json');

        $phpProperties = ini_values($fullPath);

        // Copying the extension
        $extensionVersion = $phpProperties[PHP_API];

        // Suffix (zts/debug/alpine)
        $extensionSuffix = '';
        if (is_alpine()) {
            $extensionSuffix = '-alpine';
        } elseif (is_truthy($phpProperties[IS_DEBUG])) {
            $extensionSuffix = '-debug';
        } elseif (is_truthy($phpProperties[THREAD_SAFETY])) {
            $extensionSuffix = '-zts';
        }
        $extensionRealPath = $tmpExtensionsDir . '/ddtrace-' . $extensionVersion . $extensionSuffix . '.so';
        $extensionFileName = 'ddtrace.so';
        $extensionDestination = $phpProperties[EXTENSION_DIR] . '/' . $extensionFileName;

        /* Move - rename() - instead of copy() since copying does a fopen() and copies to the stream itself, causing a
         * segfault in the PHP process that is running and had loaded the old shared object file.
         */
        $tmpExtName = $extensionDestination . '.tmp';
        copy($extensionRealPath, $tmpExtName);
        rename($tmpExtName, $extensionDestination);
        echo "Copied '$extensionRealPath' '$extensionDestination'\n";

        // Writing the ini file
        $iniFileName = '98-ddtrace.ini';
        $iniFilePaths = ini_file_paths($phpProperties, $iniFileName);
        foreach ($iniFilePaths as $iniFilePath) {
            if (!file_exists($iniFilePath)) {
                $iniDir = dirname($iniFilePath);
                execute_or_exit(
                    "Cannot create directory '$iniDir'",
                    "mkdir -p " . escapeshellarg($iniDir)
                );

                if (false === file_put_contents($iniFilePath, get_ini_template($installDirWrapperPath))) {
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
                    "sed -i 's@extension \?= \?\(.*\)@extension = ddtrace.so@g' " . escapeshellarg($iniFilePath)
                );
            }
            echo "Installation to '$binaryForLog' was successful\n";
        }
    }
}

function install_appsec($options, $selectedBinaries)
{
    if ($options[OPT_NO_APPSEC]) {
        return;
    }
    $implicitAppsec = !isset($options[OPT_APPSEC_FILE]) &&
        !isset($options[OPT_APPSEC_URL]) &&
        !isset($options[OPT_APPSEC_VERSION]);

    if (
        $implicitAppsec ||
        isset($options[OPT_APPSEC_VERSION]) && $options[OPT_APPSEC_VERSION] == 'latest'
    ) {
        $options[OPT_APPSEC_VERSION] = latest_release("dd-appsec-php");
    }

    echo "Installing appsec\n";

    // Preparing clean tmp folder to extract files
    $tmpDir = sys_get_temp_dir() . '/dd-appsec';
    $tarball = "$tmpDir/dd-appsec-php.tar.gz";
    execute_or_exit(
        "Cannot create directory '$tmpDir'",
        "mkdir -p " . escapeshellarg($tmpDir)
        );
    execute_or_exit(
        "Cannot clean '$tmpDir'",
        "rm -f " . escapeshellarg($tarball)
    );
    delete_on_exit($tmpDir);

    // Retrieve the archive to the temporary location
    if (isset($options[OPT_APPSEC_FILE])) {
        $tarball = $options[OPT_APPSEC_FILE];
    } else {
        $url = isset($options[OPT_APPSEC_URL])
            ? $options[OPT_APPSEC_URL]
            : sprintf(
                'https://github.com/DataDog/dd-appsec-php/releases/' .
                'download/v%1$s/dd-appsec-php-%1$s-amd64.tar.gz',
                rawurlencode($options[OPT_APPSEC_VERSION])
            );
        download($url, $tarball);
    }

    $installDir = "{$options[OPT_INSTALL_DIR]}/appsec-" . extract_version_appsec($options, $tarball);

    // copying sources to the final destination
    execute_or_exit(
        "Cannot create directory '$installDir'",
        "mkdir -p " . escapeshellarg($installDir)
    );
    execute_or_exit(
        "Cannot extract files from '$tarball' to directory '$installDir'",
        'tar --strip-components=1 -C ' . escapeshellarg($installDir) .
        ' -xf ' . escapeshellarg($tarball)
    );
    echo "Installed dd-appsec files to '$installDir'\n";

    // Actual installation
    foreach ($selectedBinaries as $command => $fullPath) {
        $binaryForLog = ($command === $fullPath) ? $fullPath : "$command ($fullPath)";
        echo "Installing appsec to binary: $binaryForLog\n";
        $phpProperties = ini_values($fullPath);

        if (is_truthy($phpProperties[IS_DEBUG])) {
            if ($implicitAppsec) {
                echo "WARNING: Cannot install appsec for $binaryForLog: " .
                    "this is a debug PHP build, which is not supported\n";
                continue;
            } else {
                print_error_and_exit("Cannot install appsec for $binaryForLog: " .
                    "this is a debug PHP build, which is not supported\n");
            }
        }

        // Copy ddappsec.so
        $extensionFilename = 'ddappsec.so';
        $extensionOrigin = sprintf(
            "$installDir/lib/php/no-debug-%s-%s/$extensionFilename",
            is_truthy($phpProperties[THREAD_SAFETY]) ? 'zts' : 'non-zts',
            $phpProperties[PHP_API]
        );

        if (!file_exists($extensionOrigin)) {
            if ($implicitAppsec) {
                echo "WARNING: Cannot install appsec for $binaryForLog: " .
                    "the PHP version is unsupported; no such file '$extensionOrigin'\n";
                continue;
            } else {
                print_error_and_exit("Cannot install appsec for $binaryForLog: " .
                    "the PHP version is unsupported; no such file '$extensionOrigin'\n");
            }
        }

        $extensionDestination = "{$phpProperties[EXTENSION_DIR]}/$extensionFilename";

        $tmpExtName = "$extensionDestination.tmp";
        copy($extensionOrigin, $tmpExtName);
        rename($tmpExtName, $extensionDestination);
        echo "Copied '$extensionOrigin' to '$extensionDestination'\n";

        // Writing the ini file
        $iniFileName = '98-ddappsec.ini';
        $helperPath = "$installDir/bin/ddappsec-helper";
        $rulesPath = "$installDir/etc/dd-appsec/recommended.json";

        $iniFilePaths = ini_file_paths($phpProperties, $iniFileName);
        foreach ($iniFilePaths as $iniFilePath) {
            if (!file_exists($iniFilePath)) {
                $iniDir = dirname($iniFilePath);
                execute_or_exit(
                    "Cannot create directory '$iniDir'",
                    "mkdir -p " . escapeshellarg($iniDir)
                );

                $iniContent = get_ini_content_appsec($helperPath, $rulesPath);
                if (false === file_put_contents($iniFilePath, $iniContent)) {
                    print_error_and_exit("Cannot create INI file $iniFilePath");
                }
                echo "Created INI file '$iniFilePath'\n";
            } else {
                echo "Updating existing INI file '$iniFilePath'\n";
                repl_or_add_ini_sett($iniFilePath, 'helper_path', $helperPath);
                repl_or_add_ini_sett($iniFilePath, 'rules_path', $rulesPath);
            }
            echo "Installation to '$binaryForLog' was successful\n";
        }
    }
}

function repl_or_add_ini_sett($iniFilePath, $key, $value)
{
    $escPath = escapeshellarg($iniFilePath);
    exec("grep -qF ddappsec.$key $escPath", $out, $exitCode);
    if ($exitCode != 0) {
        // not found
        $f = fopen($iniFilePath, "a");
        if ($f === false) {
            print_error_and_exit("Could not open $iniFilePath for writing");
    }
        fwrite($f, "\n\nddappsec.$key = \"$value\"");
        return;
    }

    $expr = "s@^\(ddappsec\.$key\s*\).*@\\1= \"$value\"@";
    execute_or_exit(
        "Impossible to replace setting ddappsec.$key with the new value.",
        'sed -i ' . escapeshellarg($expr) . ' ' . escapeshellarg($iniFilePath)
    );
}

function uninstall($options)
{
    $selectedBinaries = require_binaries_or_exit($options);

    foreach ($selectedBinaries as $command => $fullPath) {
        $binaryForLog = ($command === $fullPath) ? $fullPath : "$command ($fullPath)";
        echo "Uninstalling from binary: $binaryForLog\n";

        $phpProperties = ini_values($fullPath);

        $ddtracePath = $phpProperties[EXTENSION_DIR] . '/ddtrace.so';
        $ddappsecPath = $phpProperties[EXTENSION_DIR] . '/ddappsec.so';

        // Writing the ini file
        $iniFilePaths = ini_file_paths($phpProperties, '98-ddtrace.ini');
        $iniFilePaths = array_merge($iniFilePaths, ini_file_paths($phpProperties, '98-ddappsec.ini'));

        /* Actual uninstall
         *  1) comment out extension=...
         *  2) remove ddtrace.so and ddappsec.so
         */
        foreach ($iniFilePaths as $iniPath) {
            if (file_exists($iniPath)) {
                execute_or_exit(
                    "Impossible to disable extensions from '$iniPath'. Disable it manually.",
                    "sed -i 's@^extension \?=@;extension =@g' " . escapeshellarg($iniPath)
                );
                echo "Disabled extension in INI file '$iniPath'. "
                    . "The file has not been removed to preserve custom settings.\n";
            }
        }

        $hadWarnings = false;
        if (file_exists($ddtracePath) && false === unlink($ddtracePath)) {
            print_warning("Error while removing $ddtracePath. It can be manually removed.");
            $hadWarnings = true;
        } else {
            echo "Uninstall of ddtrace from '$binaryForLog' was successful\n";
        }
        if (file_exists($ddappsecPath)) {
            if (unlink($ddappsecPath) === false) {
                print_warning("Error while removing $ddappsecPath. It can be manually removed.");
                $hadWarnings = true;
            } else {
                echo "Uninstall of ddappsec from '$binaryForLog' was successful\n";
            }
        }
        if ($hadWarnings) {
            echo "Uninstall from '$binaryForLog' was completed with warnings\n";
        }
    }
}

/**
 * Returns a list of php binaries where the tracer will be installed. If not explicitly provided by the CLI options,
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
        OPT_TRACER_FILE . ':',
        OPT_TRACER_URL . ':',
        OPT_TRACER_VERSION . ':',
        OPT_NO_TRACER,
        OPT_APPSEC_FILE . ':',
        OPT_APPSEC_URL . ':',
        OPT_APPSEC_VERSION . ':',
        OPT_NO_APPSEC,
        OPT_INSTALL_DIR . ':',
        OPT_UNINSTALL,
    ];
    $options = getopt($shortOptions, $longOptions);

    // Help and exit
    if (key_exists('h', $options) || key_exists(OPT_HELP, $options)) {
        print_help_and_exit();
    }

    $normalizedOptions = [];

    $normalizeBool = function ($name) use ($options, &$normalizedOptions) {
        $normalizedOptions[$name] = isset($options[$name]) ? true : false;
    };
    $normalizeBool(OPT_UNINSTALL);
    $normalizeBool(OPT_NO_TRACER);
    $normalizeBool(OPT_NO_APPSEC);

    if (!$normalizedOptions[OPT_UNINSTALL]) {
        // At most one among --tracer-version, --tracer-url, --tracer-file and --no-tracer must be provided
        $installables = array_intersect(
            [OPT_TRACER_VERSION, OPT_TRACER_URL, OPT_TRACER_FILE, OPT_NO_TRACER],
            array_keys($options)
        );
        if (count($installables) > 1) {
            print_error_and_exit(
                'Only one among --tracer-version, --tracer-url, --tracer-file and --no-tracer must be provided'
            );
        }
        $installables = array_intersect(
            [OPT_APPSEC_VERSION, OPT_APPSEC_URL, OPT_APPSEC_FILE, OPT_NO_APPSEC],
            array_keys($options)
        );
        if (count($installables) > 1) {
            print_error_and_exit(
                'Only one among --appsec-version, --appsec-url, --appsec-file and --no-appsec must be provided'
            );
            }
        $normalizeSingleOpt = function ($opt) use ($options, &$normalizedOptions) {
            if (isset($options[$opt])) {
                if (is_array($options[$opt])) {
                    print_error_and_exit("Only one --$opt can be provided");
            }
                $normalizedOptions[$opt] = $options[$opt];
            }
        };
        $normalizeSingleOpt(OPT_TRACER_VERSION);
        $normalizeSingleOpt(OPT_TRACER_URL);
        $normalizeSingleOpt(OPT_TRACER_FILE);
        $normalizeSingleOpt(OPT_APPSEC_VERSION);
        $normalizeSingleOpt(OPT_APPSEC_URL);
        $normalizeSingleOpt(OPT_APPSEC_FILE);
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
 * Attempts to extract the version number of the installed tracer.
 *
 * @param array $options
 * @param mixed string $extractArchiveRoot
 * @param mixed string $extractedSourcesRoot
 * @return string
 */
function extract_version_subdir_path($options, $extractArchiveRoot, $extractedSourcesRoot)
{
    /* We apply the following decision making algorithm
     *   1) if --tracer-version is provided, we use it
     *   2) if a VERSION file exists at the archive root, we use it
     *   3) if sources are provided, we parse src/DDTrace/Tracer.php
     *   4) fallback to YYYY.MM.DD-HH.mm
     */

    // 1)
    if (isset($options[OPT_TRACER_VERSION])) {
        return trim($options[OPT_TRACER_VERSION]);
    }

    // 2)
    $versionFile = $extractArchiveRoot . '/VERSION';
    if (is_readable($versionFile)) {
        return trim(file_get_contents($versionFile));
    }

    // 3)
    $ddtracerFile = "$extractedSourcesRoot/src/DDTrace/Tracer.php";
    if (is_readable($ddtracerFile)) {
        $content = file_get_contents($ddtracerFile);
        $matches = array();
        preg_match("(const VERSION = '([^']+(?<!-nightly))';)", $content, $matches);
        if (isset($matches[1])) {
            return trim($matches[1]);
        }
    }

    // 4)
    return date("Y.m.d-H.i");
}

function extract_version_appsec($options, $tarball)
{
    if (isset($options[OPT_APPSEC_VERSION])) {
        return trim($options[OPT_APPSEC_VERSION]);
    }

    execute_or_exit(
        "Cannot extract dd-appsec-php/VERSION from $tarball " .
        "(not a valid dd-appsec-php bundle)",
        "tar -O -xf " . escapeshellarg($tarball) . " dd-appsec-php/VERSION",
        $output
    );
    $version = reset($output);
    if ($version === false) {
        print_error_and_exit("Archive $tarball has an invalid dd-appsec-php/VERSION file");
    }
    return $version;
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

function execute_or_exit($exitMessage, $command, &$output = array())
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
function download($url, $destination, $print = true)
{
    $printfn = function ($msg) use ($print) {
        if ($print) {
            echo "$msg\n";
        }
    };
    $printfn("Downloading installable archive from $url.");
    $printfn("This operation might take a while.");

    $okMessage = "\nDownload completed\n";

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
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-agent: curl']);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, 'on_download_progress');
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        $progress_counter = 0;
        $return = curl_exec($ch);
        curl_close($ch);
        fclose($fp);

        if (false !== $return) {
            $printfn($okMessage);
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
            'curl -Lf --output ' . escapeshellarg($destination) . ' ' . escapeshellarg($url),
            $curlInvocationStatusCode
        );

        if ($curlInvocationStatusCode === 0) {
            $printfn($okMessage);
            return;
        }
        // Otherwise we attempt other methods
    }

    // file_get_contents
    if (is_truthy(ini_get('allow_url_fopen'))) {
        if (false === file_put_contents($destination, file_get_contents($url))) {
            print_error_and_exit("Error while downloading the installable archive from $url\n");
        }

        $printfn($okMessage);
        return;
    }

    $printfn("Error: Cannot download the installable archive.");
    $printfn("  One of the following prerequisites must be satisfied:");
    $printfn("    - PHP ext-curl extension is installed");
    $printfn("    - curl CLI command is available");
    $printfn("    - the INI setting 'allow_url_fopen=1'");

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

function latest_release($repo)
{
    $path = tempnam(sys_get_temp_dir(), "lat_ver_check");
    register_shutdown_function(function () use ($path) {
        @unlink($path);
    });
    $url = "https://api.github.com/repos/Datadog/$repo/releases/latest";
    download($url, $path, false);
    $jsonData = file_get_contents($path);
    if ($jsonData === false) {
        print_error_and_exit("Error opening $path, the destination for $url\n");
    }
    // avoid json extension dependency
    if (preg_match('/(?<="name": ")[^"]+(?=")/', $jsonData, $m) !== 1) {
        print_error_and_exit("Could not find latest release for $repo in GitHub API response.\n" .
        "URL: $url\nFull response follows:\n$jsonData");
    }
    $version = ltrim($m[0], 'v');
    echo "Latest release for $repo is $version\n";
    return $version;
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
    exec(escapeshellarg($binary) .
        ' -d ddappsec.enabled=0 -d ddappsec.enabled_on_cli=0 -d date.timezone=UTC -i', $lines);
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

function ini_file_paths($phpProperties, $iniFileName)
{
    $iniDir = $phpProperties[INI_CONF];
    if ($iniDir == '(none)') {
        print_error_and_exit(
            "This PHP installation does not have an ini configuration directory set. " .
            "Either it has single php.ini file set or none at all; in any case, such setup " .
            "is unsupported.\nHint: Set env var PHP_INI_SCAN_DIR to the desired directory"
        );
    }
    $posComma = strpos($iniDir, ':');
    if ($posComma !== false) {
        $iniDir = substr($iniDir, 0, $posComma);
        echo "More than one ini directory found. Taking the first: $iniDir";
    }
    $iniFilePaths = [$iniDir . '/' . $iniFileName];
    if (\strpos($iniDir, '/cli/conf.d') !== false) {
        /* debian based distros have INI folders split by SAPI, in a predefined way:
         *   - <...>/cli/conf.d       <-- we know this from php -i
         *   - <...>/apache2/conf.d   <-- we derive this from relative path
         *   - <...>/fpm/conf.d       <-- we derive this from relative path
         */
        $apacheConfd = str_replace('/cli/conf.d', '/apache2/conf.d', $iniDir);
        if (\is_dir($apacheConfd)) {
            array_push($iniFilePaths, "$apacheConfd/$iniFileName");
        }
        $fpmConfd = str_replace('/cli/conf.d', '/fpm/conf.d', $iniDir);
        if (\is_dir($fpmConfd)) {
            array_push($iniFilePaths, "$fpmConfd/$iniFileName");
        }
    }
    return $iniFilePaths;
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
;datadog.trace.agent_url = http://some.internal.host:6789

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
; For each integration (see https://docs.datadoghq.com/tracing/setup_overview/setup/php/?tab=containers#integration-names):
;   - *_enabled: whether the integration is enabled.
;   - *_analytics_enabled: whether analytics for the integration is enabled.
;   - *_analytics_sample_rate: sampling rate for analyzed spans. Valid values are between 0.0 and 1.0.
;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;

;datadog.trace.<integration_name>_enabled = On
;datadog.trace.<integration_name>_analytics_enabled = Off
;datadog.trace.<integration_name>_analytics_sample_rate = 1.0

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

function get_ini_content_appsec($helperPath, $rulesPath)
{
    // phpcs:disable Generic.Files.LineLength.TooLong
    return <<<EOD
; Loads the dd-appsec extension
extension = ddappsec.so

; Enables or disables the loaded dd-appsec extension.
; If disabled, the extension will do no work during the requests.
; This value is ignored on the CLI SAPI, see ddappsec.enabled_on_cli.
;ddappsec.enabled = Off

; Enables or disables the loaded dd-appsec extension for the CLI SAPI.
; This value is only used for the CLI SAPI, see ddappsec.enabled for the
; corresponding setting on other SAPIs.
;ddappsec.enabled_on_cli = Off

; Allows dd-appsec to block attacks by committing an error page response (if no
; response has already been committed), and issuing an error that cannot be
; handled, thereby aborting the request.
;ddappsec.block = On

; Sets the verbosity of the logs of the dd-appsec extension.
; The valid values are 'off', 'error', 'fatal', 'warn' (or 'warning'), 'info',
; 'debug' and 'trace', in increasing order of verbosity.
;ddappsec.log_level = 'warn'

; The destination of the log messages. Valid values are 'php_error_reporting'
; (issues PHP notices or warnings), 'syslog', 'stdout', 'stderr', or an
; arbitrary file name to which the messages will be appended.
;ddappsec.log_file = php_error_reporting

;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;
; Messages related to the helper ;
;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;

; The dd-appsec extension communicates with a helper process via UNIX sockets.
; This setting determines whether the extension should try to launch the daemon
; in case it cannot obtain a connection.
; If this is disabled, the helper should be launched through some other method.
; The extension expects the helper to run under the same user as the process
; where PHP is running, and will verify it.
;ddappsec.helper_launch = On

; If ddappsec.helper_launch is enabled, this setting determines which binary
; the extension should try to execute.
; Only relevant if ddappsec.helper_launch is enabled.
; This ini setting is configured by the installer.
ddappsec.helper_path = '$helperPath'

; Additional arguments that should be used when attempting to launch the helper
; process. The extension always passes '--lock_path - --socket_path fd:<int>'
; The arguments should be space separated. Both single and double quotes can
; be used should an argument contain spaces. The backslash (\) can be used to
; escape spaces, quotes, and the blackslash itself.
; Only relevant if ddappsec.helper_launch is enabled.
;ddappsec.helper_extra_args = ""

; The path to the rules json file. The helper process must be able to read the
; file. This ini setting is configured by the installer.
ddappsec.rules_path = "$rulesPath"

; The location to the UNIX socket that extension uses to communicate with the
; helper.
;ddappsec.helper_socket_path = /tmp/ddappsec.sock

; The location of the lock file that the extension processes will use to
; synchronize the launching of the helper.
; Only relevant if ddappsec.helper_launch is enabled.
;ddappsec.helper_lock_path = /tmp/ddappsec.lock

; The location of the log file of the helper. This default to /dev/null (the log
; messages will be discarded. This file is opened by the extension just before
; launching the daemon and the file descriptor is passed to the helper as its
; stderr, to which it will write its messages; this setting is therefore only
; relevant if ddappsec.helper_launch is enabled.
;ddappsec.helper_log_file = /dev/null
EOD;
    // phpcs:enable Generic.Files.LineLength.TooLong
}

/**
 * @return string[]
 */
function get_supported_php_versions()
{
    return ['5.4', '5.5', '5.6', '7.0', '7.1', '7.2', '7.3', '7.4', '8.0', '8.1'];
}

function delete_on_exit($dir)
{
    static $initialized = false;
    static $directories = [];
    if (!$initialized) {
        register_shutdown_function(function () use (&$directories) {
            exec('rm -rf ' . join(' ', array_map('escapeshellarg', $directories)));
        });
    }
    $directories[] = $dir;
}

main();
