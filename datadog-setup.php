<?php

// Tests for the installer are in 'dockerfiles/verify_packages/installer'

const INI_SCANDIR = 'Scan this dir for additional .ini files';
const INI_MAIN = 'Loaded Configuration File';
const EXTENSION_DIR = 'extension_dir';
const THREAD_SAFETY = 'Thread Safety';
const PHP_VER = 'PHP Version';
const PHP_API = 'PHP API';
const IS_DEBUG = 'Debug Build';

// Commands
const CMD_CONFIG_GET = 'config get';
const CMD_CONFIG_SET = 'config set';
const CMD_CONFIG_LIST = 'config list';

// Options
const OPT_HELP = 'help';
const OPT_INSTALL_DIR = 'install-dir';
const OPT_EXTENSION_DIR = 'extension-dir';
const OPT_PHP_BIN = 'php-bin';
const OPT_PHP_INI = 'ini';
const OPT_FILE = 'file';
const OPT_UNINSTALL = 'uninstall';
const OPT_ENABLE_APPSEC = 'enable-appsec';
const OPT_ENABLE_PROFILING = 'enable-profiling';
const OPT_INI_SETTING = 'd';

// Release version is set while generating the final release files
const RELEASE_VERSION = '@release_version@';

// phpcs:disable Generic.Files.LineLength.TooLong
// For testing purposes, we need an alternate repo where we can push bundles that includes changes that we are
// trying to test, as the previously released versions would not have those changes.
define('RELEASE_URL_PREFIX', (getenv('DD_TEST_INSTALLER_REPO') ?: "https://github.com/DataDog/dd-trace-php") . "/releases/download/" . RELEASE_VERSION . "/");
// phpcs:enable Generic.Files.LineLength.TooLong

define('IS_WINDOWS', strncasecmp(PHP_OS, "WIN", 3) == 0);
define('EXTENSION_PREFIX', IS_WINDOWS ? "php_" : "");
define('EXTENSION_SUFFIX', IS_WINDOWS ? "dll" : "so");

define('DEFAULT_INSTALL_DIR', IS_WINDOWS ? getenv('ProgramW6432') . '\Datadog\PHP Tracer' : '/opt/datadog');

/**
 * The number of items to shift off `get_ini_settings` for config commands.
 */
const CMD_CONFIG_NUM_SHIFT = 3;

function main()
{
    if (is_truthy(getenv('DD_TEST_EXECUTION'))) {
        return;
    }

    $arguments = parse_validate_user_options();
    $options = $arguments['opts'];
    switch ($arguments['cmd']) {
        case CMD_CONFIG_GET:
            cmd_config_get($options);
            break;
        case CMD_CONFIG_SET:
            cmd_config_set($options);
            break;
        case CMD_CONFIG_LIST:
            cmd_config_list($options);
            break;
        default:
            if ($options[OPT_UNINSTALL]) {
                uninstall($options);
            } else {
                install($options);
            }
    }
}

function print_help()
{
    $installdir = DEFAULT_INSTALL_DIR;
    echo <<<EOD

Usage:
    Interactive
        php datadog-setup.php [command] ...
    Non-Interactive
        php datadog-setup.php --php-bin php ...
        php datadog-setup.php --php-bin php --php-bin /usr/local/sbin/php-fpm ...
        php datadog-setup.php config get --php-bin php -d datadog.profiling.enabled
        php datadog-setup.php config set --php-bin php -d datadog.profiling.enabled=On

Options:
    -h, --help                  Print this help text and exit.
    --php-bin all|<path to php> Install the library to the specified binary or
                                all php binaries in standard search paths. The
                                option can be provided multiple times.
    --install-dir <path>        Install to a specific directory. Default: '$installdir'
    --uninstall                 Uninstall the library from the specified binaries.
    --file <path to .tar.gz>    Path to a dd-library-php-*.tar.gz file. Can be used for offline installation.
    --extension-dir <path>      Specify the extension directory. Default: PHP's extension directory.
    --ini <path>                Specify the INI file to use. Default: <ini-dir>/98-ddtrace.ini
    --enable-appsec             Enable the application security monitoring module.
    --enable-profiling          Enable the profiling module.
    -d setting[=value]          Used in conjunction with `config <set|get>`
                                command to specify the INI setting to get or set.

Available commands:
    config list                 List Datadog's INI setting for the specified binaries.
    config get                  Get INI setting for the specified binaries.
    config set                  Set INI setting for the specified binaries.

EOD;
}

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
class IniRecord
{
    /** @var string */
    public $setting;
    /** @var string */
    public $currentValue;
    /** @var string */
    public $defaultValue;
    /** @var string */
    public $iniFile;
    /** @var string */
    public $binary;

    public function println()
    {
        // phpcs:disable Generic.Files.LineLength.TooLong
        echo "$this->setting = $this->currentValue; default: $this->defaultValue, binary: $this->binary, INI file: $this->iniFile\n";
        // phpcs:disable Generic.Files.LineLength.TooLong
    }
}

/**
 * @param array $options
 * @return array<string, IniRecord>
 */
function config_list(array $options)
{
    $iniSettings = get_ini_settings('', '', '');

    // The first 3 are 'extension' type of settings.
    $iniSettings = array_slice($iniSettings, CMD_CONFIG_NUM_SHIFT);

    // Build an index by unique names for filtering.
    $indexByName = array_column($iniSettings, null, 'name');

    $return = [];

    foreach (require_binaries_or_exit($options) as $command => $fullPath) {
        $binaryForLog = ($command === $fullPath) ? $fullPath : "$command ($fullPath)";
        $iniFilePaths = find_all_ini_files(ini_values($fullPath));

        foreach ($iniFilePaths as $iniFilePath) {
            $iniFileSettings = parse_ini_file($iniFilePath, false, INI_SCANNER_RAW);
            $settings = array_intersect_key($iniFileSettings, $indexByName);

            foreach ($settings as $iniFileSetting => $currentValue) {
                $iniSetting = $indexByName[$iniFileSetting];
                $record = new IniRecord();
                $record->setting = $iniFileSetting;
                $record->currentValue = $currentValue;
                $record->defaultValue = $iniSetting['default'];
                $record->iniFile = $iniFilePath;
                $record->binary = $binaryForLog;
                $return[$record->setting] = $record;
            }
        }
    }

    return $return;
}

/**
 * This function will print out all Datadog specific PHP INI settings. The list
 * of Datadog specific settings is retrieved by calling `get_ini_settings()`. It
 * will also show the default and INI file this setting was found.
 * The output will be grouped by PHP binary, example:
 *
 * $ php datadog-setup.php config list --php-bin all
 * Searching for available php binaries, this operation might take a while.
 * datadog.profiling.enabled = On; default: 1, binary: /opt/php/8.2/bin/php, INI file: /opt/php/etc/conf.d/98-ddtrace.ini
 * datadog.profiling.experimental_allocation_enabled = On; default: 1, binary: /opt/php/8.2/bin/php, INI file: /opt/php/etc/conf.d/98-ddtrace.ini
 *
 * @see get_ini_settings
 * @return void
 */
function cmd_config_list(array $options)
{
    foreach (config_list($options) as $record) {
        $record->println();
    }
}

/**
 * This function will print the specified PHP INI settings. It only prints
 * information for Datadog's own settings; it can't be used to parse opcache,
 * xdebug, etc.
 * The output will be grouped by PHP binary, example:
 *
 * $ php datadog-setup.php config get --php-bin all \
 *   -ddatadog.profiling.experimental_allocation_enabled \
 *   -ddatadog.profiling.experimental_cpu_time_enabled \
 *   -dnonexisting \
 *   -dopcache.preload
 * datadog.profiling.experimental_allocation_enabled = On; binary: /opt/php/8.2/bin/php, INI file: /opt/php/etc/conf.d/98-ddtrace.ini
 * datadog.profiling.experimental_cpu_time_enabled = On; binary: /opt/php/8.2/bin/php, INI file: /opt/php/etc/conf.d/98-ddtrace.ini
 * nonexisting = undefined; is missing in INI files
 * opcache.preload = undefined; is missing in INI files
 * @return void
 */
function cmd_config_get(array $options)
{
    if (!isset($options['d'])) {
        print_help();
        return;
    }
    /* A value could be set in multiple places:
     * - /opt/php/8.1/etc/conf.d/98-ddtrace.ini
     * - /opt/php/8.1/etc/conf.d/90-ddtrace-custom.ini
     * So, a given setting like 'datadog.trace.enabled' is not necessarily
     * unique in the iterator's keys.
     */
    $records = [];
    foreach (config_list($options) as $setting => $record) {
        $records[$setting][] = $record;
    }

    foreach ($options['d'] as $iniSetting) {
        if (!isset($records[$iniSetting])) {
            echo '; ', $iniSetting, ' = undefined; is missing in INI files', PHP_EOL;
        } else {
            foreach ($records[$iniSetting] as $record) {
                $record->println();
            }
        }
    }
}

/**
 * This function will set the given INI settings for any given PHP binary
 *
 * 1. Scan all INI files for for this INI setting and update if found (updates
 *    in all INI files in case it finds in multiple places and warns about the
 *    mess it found).
 * 2. In case it could not find, it searches for commented out versions of this
 *    INI setting, uncomments it and updates the value. It first checks this in
 *    the "default" INI file (the one that holds the `extension = ddtrace` line,
 *    then others and only promotes the first commented version found.
 * 3. In case this INI setting is not there yet it creates a new entry in the
 *    "default" INI file (see above).
 *
 * $ php datadog-setup.php config set --php-bin all \
 *   -ddatadog.profiling.experimental_allocation_enabled=On \
 *   -ddatadog.profiling.experimental_cpu_time_enabled=On \
 * Setting configuration for binary: php (/opt/php/8.2/bin/php)
 * Set 'datadog.profiling.experimental_allocation_enabled' to 'On' in INI file: /opt/php/etc/conf.d/98-ddtrace.ini
 * Set 'datadog.profiling.experimental_cpu_time_enabled' to 'On' in INI file: /opt/php/etc/conf.d/98-ddtrace.ini
 */
function cmd_config_set(array $options)
{
    if (!isset($options['d'])) {
        print_help();
        return;
    }
    $selectedBinaries = require_binaries_or_exit($options);
    foreach ($selectedBinaries as $command => $fullPath) {
        $binaryForLog = ($command === $fullPath) ? $fullPath : "$command ($fullPath)";
        echo "Setting configuration for binary: $binaryForLog", PHP_EOL;

        $phpProps = ini_values($fullPath);

        foreach ($options['d'] as $cliIniSetting) {
            if (($setting = parse_ini_setting($cliIniSetting)) === false) {
                echo "The given INI setting '", $cliIniSetting,
                    "' can't be converted to a valid INI setting, skipping.", PHP_EOL;
                continue;
            }

            $allIniFilePaths = find_all_ini_files($phpProps);

            $matchCount = 0;
            // look for INI setting in INI files
            foreach ($allIniFilePaths as $iniFile) {
                $count = update_ini_setting($setting, $iniFile, false);
                if ($count === 0) {
                    continue;
                }
                if ($count === false) {
                    echo "Could not set '", $setting[0], "' to '", $setting[1],
                        "' in INI file: ", $iniFile , PHP_EOL;
                    continue;
                }
                echo "Set '", $setting[0], "' to '", $setting[1], "' in INI file: ", $iniFile, PHP_EOL;
                $matchCount += $count;
            }

            if ($matchCount >= 1) {
                // found and updated
                if ($matchCount >= 2) {
                    echo "Warning: '$setting[0]' was found in multiple places, ",
                        "you might want to remove duplicates.", PHP_EOL;
                }
                continue;
            }

            // If we are here, we could not find the INI setting in any files, so
            // we try and look for commented versions

            $matchCount = 0;
            // look for INI setting in INI files
            foreach ($allIniFilePaths as $iniFile) {
                $count = update_ini_setting($setting, $iniFile, true);
                if ($count === 0) {
                    continue;
                }
                if ($count === false) {
                    echo "Could not set '", $setting[0], "' to '", $setting[1],
                        "' in INI file: ", $iniFile , PHP_EOL;
                    continue;
                }
                echo "Set '", $setting[0], "' to '", $setting[1], "' in INI file: ", $iniFile, PHP_EOL;
                $matchCount += $count;
                break;
            }

            if ($matchCount >= 1) {
                // found, promoted from comment and updated
                continue;
            }

            // Now we are here, meaning we could not find it, not in active nor in
            // commented version, so we just add it to the default INI file(s)

            $mainIniFilePaths = find_main_ini_files($phpProps);

            $set = false;
            foreach ($mainIniFilePaths as $iniFile) {
                if (!file_exists($iniFile)) {
                    echo "File '$iniFile' does not exist. Trying to create it... ";
                    if (file_put_contents($iniFile, '') === false) {
                        echo "Could not set '{$setting[0]}' to '{$setting[1]}' in INI file: {$iniFile}.\n";
                        $directory = dirname($iniFile);
                        if (!is_dir($directory)) {
                            echo "Directory '$directory' doesn't exist. Create it and try again if you want to use '$iniFile'.\n";
                        }
                        continue;
                    } else {
                        echo "Success.\n";
                    }
                }

                $iniFileContent = file_get_contents($iniFile);
                // check for "End of Line" symbol at the end of the file and
                // add in case it is missing
                if (strlen($iniFileContent) > 0 && substr($iniFileContent, -1, 1) !== PHP_EOL) {
                    $iniFileContent .= PHP_EOL;
                }
                $iniFileContent .= implode(' = ', $setting) . PHP_EOL;
                if (file_put_contents($iniFile, $iniFileContent) === false) {
                    echo "Could not set '{$setting[0]}' to '{$setting[1]}' in INI file: {$iniFile}.\n";
                    continue;
                }
                echo "Set '{$setting[0]}' to '{$setting[1]}' in INI file: $iniFile.\n";
                $set = true;
            }

            if (!$set) {
                echo "Unable to set '{$setting[0]}' to '{$setting[1]}' in any INI file for $binaryForLog.\n";
                exit(1);
            }
        }
    }
}

/**
 * Parse a given INI setting (from CLI) into an array and return it. It also tries
 * to parse the new setting with `parse_ini_string()` to validate it is actually
 * working
 *
 * @param string $setting
 * @return false|array{0:string, 1:string}
 */
function parse_ini_setting($setting)
{
    // `trim()` should not be needed, but better safe than sorry
    $setting = array_map(
        'trim',
        explode(
            '=',
            $setting,
            2
        )
    );
    if (count($setting) !== 2) {
        return false;
    }
    // safety: try out if parsing the generated ini setting is actually possible
    if (parse_ini_string($setting[0] . '=' . $setting[1], false, INI_SCANNER_RAW) === false) {
        return false;
    }
    return $setting;
}


/**
 * This function will try and update a given `$setting` in the INI file given in
 * `$iniFile`. First it tries to find an uncommented version of the setting in
 * the file and replace this with the new value. In case no uncommented version
 * was found it tries to find commented versions of this INI setting.
 *
 * In case `$promoteComment` is set to `true`, this function will replace and
 * therefore promote only the first occurrence it finds from a comment to an INI
 * setting in the given `$iniFile`.
 *
 * @param array{0: string, 1: string} $setting
 * @param string $iniFile
 * @param bool $promoteComment
 * @return false|int
 */
function update_ini_setting($setting, $iniFile, $promoteComment)
{
    $iniFileContent = file_get_contents($iniFile);
    if ($promoteComment) {
        $regex = '/^[\h;]*' . preg_quote($setting[0]) . '\h*=\h*?[^;\n\r]*/mi';
    } else {
        $regex = '/^\h*' . preg_quote($setting[0]) . '\h*=\h*?[^;\n\r]*/mi';
    }
    $count = 0;
    $iniFileContent = preg_replace($regex, implode(' = ', $setting), $iniFileContent, $promoteComment ? 1 : -1, $count);
    if ($iniFileContent === null) {
        // something wrong with the regex, the user should see a warning
        // in the form of "Warning: preg_replace(): Compilation failed ..."
        return false;
    }
    if ($count > 0) {
        if (file_put_contents($iniFile, $iniFileContent) === false) {
            return false;
        }
    }
    return $count;
}

function install($options)
{
    $architecture = get_architecture();
    $platform = "$architecture-" . (IS_WINDOWS ? "windows" : "linux-" . (is_alpine() ? 'musl' : 'gnu'));

    // Checking required libraries
    check_library_prerequisite_or_exit('libcurl');
    check_library_prerequisite_or_exit('libgcc_s');
    if (!is_alpine()) {
        if (is_truthy($options[OPT_ENABLE_PROFILING])) {
            check_library_prerequisite_or_exit('libdl.so');
            check_library_prerequisite_or_exit('libpthread');
            check_library_prerequisite_or_exit('librt');
        }
    }

    // Picking the right binaries to install the library
    $selectedBinaries = require_binaries_or_exit($options);
    $interactive = empty($options[OPT_PHP_BIN]);

    $commandExtensionSuffixes = [];
    $downloadVersions = [];
    foreach ($selectedBinaries as $command => $fullPath) {
        $binaryForLog = ($command === $fullPath) ? $fullPath : "$command ($fullPath)";
        echo "Checking for binary: $binaryForLog\n";

        check_php_ext_prerequisite_or_exit($fullPath, 'json');

        $phpProperties = ini_values($fullPath);
        if (!isset($phpProperties[INI_SCANDIR])) {
            if (!isset($phpProperties[INI_MAIN])) {
                if (IS_WINDOWS) {
                    $phpProperties[INI_MAIN] = dirname($fullPath) . "/php.ini";
                } else {
                    print_error_and_exit(
                        "It is not possible to perform installation on this "
                        . "system because there is no scan directory and no "
                        . "configuration file loaded."
                    );
                }
            }

            print_warning(
                "Performing an installation without a scan directory may "
                . "result in fragile installations that are broken by normal "
                . "system upgrades. It is advisable to use the configure "
                . "switch --with-config-file-scan-dir when building PHP."
            );
        }

        // Suffix (zts/debug)
        $extensionSuffix = '';
        if (is_truthy($phpProperties[IS_DEBUG])) {
            $extensionSuffix .= '-debug';
        }
        if (is_truthy($phpProperties[THREAD_SAFETY])) {
            $extensionSuffix .= '-zts';
        }

        $commandExtensionSuffixes[$command] = $extensionSuffix;

        $extensionVersion = $phpProperties[PHP_API];
        $downloadVersions["$extensionVersion$extensionSuffix"] = true;
    }

    $tar_gz_suffix = "";
    if (count($downloadVersions) === 1) {
        $tar_gz_suffix = "-" . key($downloadVersions);
    }

    // Preparing clean tmp folder to extract files
    $tmpDir = sys_get_temp_dir() . '/dd-install';
    $tmpArchiveRoot = $tmpDir . '/dd-library-php';
    $tmpArchiveTraceRoot = $tmpDir . '/dd-library-php/trace';
    $tmpArchiveAppsecRoot = $tmpDir . '/dd-library-php/appsec';
    $tmpArchiveAppsecLib = "{$tmpArchiveAppsecRoot}/lib";
    $tmpArchiveAppsecEtc = "{$tmpArchiveAppsecRoot}/etc";
    $tmpArchiveProfilingRoot = $tmpDir . '/dd-library-php/profiling';
    $tmpSrcDir = $tmpArchiveTraceRoot . '/src';
    if (!file_exists($tmpDir)) {
        execute_or_exit("Cannot create directory '$tmpDir'. Try setting a different temporary directory by setting the sys_temp_dir INI variable. E.g. php -d sys_temp_dir=" . (IS_WINDOWS ? 'C:\path\to\temp\dir' : "/path/to/temp/dir") . (isset($_SERVER["argv"][0]) ? " {$_SERVER["argv"][0]}" : ""), "mkdir " . (IS_WINDOWS ? "" : "-p ") . escapeshellarg($tmpDir));
    }
    register_shutdown_function(function () use ($tmpDir) {
        execute_or_exit("Cannot remove temporary directory '$tmpDir'", (IS_WINDOWS ? "rd /s /q " : "rm -rf ") . escapeshellarg($tmpDir));
    });
    if (!IS_WINDOWS) {
        execute_or_exit(
            "Cannot clean '$tmpDir'",
            "rm -rf " . escapeshellarg($tmpDir) . "/* "
        );
    }

    // Retrieve and extract the archive to a tmp location
    if (isset($options[OPT_FILE])) {
        print_warning('--' . OPT_FILE . ' option is intended for internal usage and can be removed without notice');
        $tmpDirTarGz = $options[OPT_FILE];
    } else {
        for (;;) {
            $url = RELEASE_URL_PREFIX . "dd-library-php-" . RELEASE_VERSION . "-{$platform}{$tar_gz_suffix}.tar.gz";
            $tmpDirTarGz = $tmpDir . "/dd-library-php-{$platform}{$tar_gz_suffix}.tar.gz";
            if (download($url, $tmpDirTarGz, $tar_gz_suffix != "")) {
                break;
            }
            $tar_gz_suffix = ""; // retry with the full archive if the original download failed
        }
    }
    if (!IS_WINDOWS || `where tar 2> nul` !== null) {
        execute_or_exit(
            "Cannot extract the archive",
            "tar -xf " . escapeshellarg($tmpDirTarGz) . " -C " . escapeshellarg($tmpDir)
        );
    } elseif (($defaultPath = `where 7z 2> nul`) !== null || @is_dir($installDir7z = getenv("PROGRAMFILES") . "\\7-Zip")) {
        if ($defaultPath === null) {
            putenv("PATH=" . getenv("PATH") . ";$installDir7z");
        }
        execute_or_exit(
            "Cannot extract the archive",
            "7z x " . escapeshellarg($tmpDirTarGz) . " -so | 7z x -aoa -si -ttar -o" . escapeshellarg($tmpDir)
        );
    } else {
        die("ERROR: neither tar nor 7z are installed and available in %PATH%. Please install either to continue the installation.\n");
    }

    $releaseVersion = trim(file_get_contents("$tmpArchiveRoot/VERSION"));

    $installDir = $options[OPT_INSTALL_DIR] . '/' . $releaseVersion;

    // Tracer sources
    $installDirSourcesDir = $installDir . '/dd-trace-sources';
    $installDirSrcDir = $installDirSourcesDir . '/src';
    // copying sources to the final destination
    if (!file_exists($installDirSourcesDir)) {
        execute_or_exit(
            "Cannot create directory '$installDirSourcesDir'",
            "mkdir " . (IS_WINDOWS ? "" : "-p ") . escapeshellarg($installDirSourcesDir)
        );
    }
    execute_or_exit(
        "Cannot copy files from '$tmpSrcDir' to '$installDirSourcesDir'",
        (IS_WINDOWS ? "echo d | xcopy /s /e /y /g /b /o /h " : "cp -r ") . escapeshellarg("$tmpSrcDir") . ' ' . escapeshellarg($installDirSrcDir)
    );
    echo "Installed required source files to '$installDir'\n";

    // Appsec helper and rules
    if (file_exists($tmpArchiveAppsecRoot)) {
        execute_or_exit(
            "Cannot copy files from '$tmpArchiveAppsecLib' to '$installDir'",
            (IS_WINDOWS ? "xcopy /s /e /y /g /b /o /h " : "cp -rf ") . escapeshellarg("$tmpArchiveAppsecLib") . ' ' . escapeshellarg($installDir)
        );
        execute_or_exit(
            "Cannot copy files from '$tmpArchiveAppsecEtc' to '$installDir'",
            (IS_WINDOWS ? "xcopy /s /e /y /g /b /o /h " : "cp -r ") . escapeshellarg("$tmpArchiveAppsecEtc") . ' ' . escapeshellarg($installDir)
        );
    }
    $appSecRulesPath = $installDir . '/etc/recommended.json';

    // Actual installation
    foreach ($selectedBinaries as $command => $fullPath) {
        $binaryForLog = ($command === $fullPath) ? $fullPath : "$command ($fullPath)";
        echo "Installing to binary: $binaryForLog\n";

        check_php_ext_prerequisite_or_exit($fullPath, 'json');

        $phpProperties = ini_values($fullPath);
        if (!isset($phpProperties[INI_SCANDIR])) {
            if (!isset($phpProperties[INI_MAIN])) {
                if (IS_WINDOWS) {
                    $phpProperties[INI_MAIN] = dirname($fullPath) . "/php.ini";
                } else {
                    print_error_and_exit(
                        "It is not possible to perform installation on this "
                        . "system because there is no scan directory and no "
                        . "configuration file loaded."
                    );
                }
            }

            print_warning(
                "Performing an installation without a scan directory may "
                . "result in fragile installations that are broken by normal "
                . "system upgrades. It is advisable to use the configure "
                . "switch --with-config-file-scan-dir when building PHP."
            );
        }

        // Copying the extension
        $extensionVersion = $phpProperties[PHP_API];
        $extensionSuffix = $commandExtensionSuffixes[$command];

        $extDir = isset($options[OPT_EXTENSION_DIR]) ? $options[OPT_EXTENSION_DIR] : $phpProperties[EXTENSION_DIR];
        echo "Installing extension to $extDir\n";

        // Trace
        $extensionRealPath = "$tmpArchiveTraceRoot/ext/$extensionVersion/"
            . EXTENSION_PREFIX . "ddtrace$extensionSuffix." . EXTENSION_SUFFIX;
        if (!file_exists($extensionRealPath)) {
            print_error_and_exit(substr($extensionSuffix ?: '-nts', 1)
                . ' builds of PHP ' . $phpProperties[PHP_VER] . ' are currently not supported');
        }

        $extensionDestination = $extDir . '/' . EXTENSION_PREFIX . 'ddtrace.' . EXTENSION_SUFFIX;
        safe_copy_extension($extensionRealPath, $extensionDestination);

        // Profiling
        $profilingExtensionRealPath = "$tmpArchiveProfilingRoot/ext/$extensionVersion/"
            . EXTENSION_PREFIX . "datadog-profiling$extensionSuffix." . EXTENSION_SUFFIX;
        $shouldInstallProfiling = file_exists($profilingExtensionRealPath);

        if ($shouldInstallProfiling) {
            $profilingExtensionDestination = $extDir . '/' . EXTENSION_PREFIX . 'datadog-profiling.' . EXTENSION_SUFFIX;
            safe_copy_extension($profilingExtensionRealPath, $profilingExtensionDestination);
        }

        // Appsec
        $appsecExtensionRealPath = "{$tmpArchiveAppsecRoot}/ext/{$extensionVersion}/"
            . EXTENSION_PREFIX . "ddappsec{$extensionSuffix}." . EXTENSION_SUFFIX;
        $shouldInstallAppsec = file_exists($appsecExtensionRealPath);

        if ($shouldInstallAppsec) {
            $appsecExtensionDestination = $extDir . '/' . EXTENSION_PREFIX . 'ddappsec.' . EXTENSION_SUFFIX;
            safe_copy_extension($appsecExtensionRealPath, $appsecExtensionDestination);
        }
        $appSecHelperPath = $installDir . '/lib/libddappsec-helper.so';

        if (isset($options[OPT_PHP_INI])) {
            $iniFilePaths = $options[OPT_PHP_INI];
        } else {
            $iniFilePaths = find_main_ini_files($phpProperties);
        }

        foreach ($iniFilePaths as $iniFilePath) {
            $replacements = [];

            if (!file_exists($iniFilePath)) {
                $iniDir = dirname($iniFilePath);
                if (!file_exists($iniDir)) {
                    execute_or_exit(
                        "Cannot create directory '$iniDir'",
                        "mkdir " . (IS_WINDOWS ? "" : "-p ") . escapeshellarg($iniDir)
                    );
                }

                if (false === file_put_contents($iniFilePath, '')) {
                    print_error_and_exit("Cannot create INI file $iniFilePath");
                }
                echo "Created INI file '$iniFilePath'\n";
            } else {
                echo "Updating existing INI file '$iniFilePath'";
                if (is_link($iniFilePath)) {
                    $iniFilePath = readlink($iniFilePath);
                    echo " which is a symlink to '$iniFilePath'";
                }
                echo "\n";

                $replacements += [
                    // Old name is deprecated
                    '(ddtrace\.request_init_hook)' => 'datadog.trace.sources_path',
                    '(datadog\.trace\.request_init_hook)' => 'datadog.trace.sources_path',
                    '((datadog\.trace\.sources_path)\s*=\s*.*)' => "$1 = $installDirSrcDir",
                ];
            }

            if (isset($options[OPT_EXTENSION_DIR])) {
                $replacements += [
                    '(^\s*;?\s*extension\s*=\s*.*ddtrace.*)m' => "extension = $extensionDestination",
                ];
            } else {
                $replacements += [
                    /* In order to support upgrading from legacy installation method to new installation method, we
                     * replace "extension = /opt/datadog-php/xyz.so" with "extension =  ddtrace.so" honoring trailing
                     * `;`, hence not automatically re-activating the extension if the user had commented it out.
                     */
                    '(^\s*;?\s*extension\s*=\s*.*ddtrace.*)m' => "extension = ddtrace" . (IS_WINDOWS ? "" : "." . EXTENSION_SUFFIX),
                    // Support upgrading from the C based zend_extension.
                    '(zend_extension\s*=\s*.*datadog-profiling.*)' => "extension = datadog-profiling" . (IS_WINDOWS ? "" : "." . EXTENSION_SUFFIX),
                ];
            }

            // Enabling profiling
            if (is_truthy($options[OPT_ENABLE_PROFILING])) {
                // phpcs:disable Generic.Files.LineLength.TooLong
                if ($shouldInstallProfiling) {
                    if (isset($options[OPT_EXTENSION_DIR])) {
                        $replacements['(zend_extension\s*=\s*.*datadog-profiling.*)'] = "extension = $profilingExtensionDestination";
                        $replacements['(^\s*;?\s*extension\s*=\s*.*datadog-profiling.*)m'] = "extension = $profilingExtensionDestination";
                    } else {
                        $replacements['(^\s*;?\s*extension\s*=\s*.*datadog-profiling.*)m'] = "extension = datadog-profiling" . (IS_WINDOWS ? "" : "." . EXTENSION_SUFFIX);
                    }
                } else {
                    $enableProfiling = OPT_ENABLE_PROFILING;
                    print_error_and_exit(
                        "Option --{$enableProfiling} was provided, but it is not supported on this PHP build or version.\n"
                    );
                }
                // phpcs:enable Generic.Files.LineLength.TooLong
            }

            // Load AppSec and enable/disable as required

            // phpcs:disable Generic.Files.LineLength.TooLong
            if ($shouldInstallAppsec) {
                $rulesPathRegex = preg_quote($options[OPT_INSTALL_DIR]) . "/[0-9\.]*/etc/recommended.json";
                $iniAppsecExtension = isset($options[OPT_EXTENSION_DIR])
                    ? $appsecExtensionDestination
                    : ("ddappsec" . (IS_WINDOWS ? "" : "." . EXTENSION_SUFFIX));
                $replacements += [
                    '(^\s*;?\s*extension\s*=\s*.*ddappsec.*)m' => "extension = $iniAppsecExtension",
                    // Update helper path
                    '(datadog.appsec.helper_path\s*=.*)' => "datadog.appsec.helper_path = $appSecHelperPath",
                    // Update and comment rules path
                    '(^[\s;]*datadog.appsec.rules\s*=\s*' . $rulesPathRegex . ')m' => "; datadog.appsec.rules = " . $appSecRulesPath,
                ];
                if (is_truthy($options[OPT_ENABLE_APPSEC])) {
                    $replacements += ['(^[\s;]*datadog.appsec.enabled\s*=.*)m' => 'datadog.appsec.enabled = On'];
                }
            } else {
                // Ensure AppSec isn't loaded if not compatible
                $replacements['(^[\s;]*extension\s*=\s*.*ddappsec.*)m'] = "; extension = ddappsec" . (IS_WINDOWS ? "" : "." . EXTENSION_SUFFIX);

                if (is_truthy($options[OPT_ENABLE_APPSEC])) {
                    $enableAppsec = OPT_ENABLE_APPSEC;
                    print_error_and_exit(
                        "Option --{$enableAppsec} was provided, but it is not supported on this PHP build or version.\n"
                    );
                }
            }

            add_missing_ini_settings(
                $iniFilePath,
                get_ini_settings($installDirSrcDir, $appSecHelperPath, $appSecRulesPath),
                $replacements
            );

            // phpcs:enable Generic.Files.LineLength.TooLong

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

function filter_ssi_ini_paths(array $iniFilePaths)
{
    return array_filter($iniFilePaths, function ($path) { return strpos($path, 'datadog-apm-library-php') === false; });
}

/**
 * Returns a list of all INI files found for the `$phpProperties` given.
 *
 * Does so by scanning the INI scan directory for `.ini` files. Additionally we
 * check if we are running on a Debian based distribution so we assume the INI
 * files are split by SAPI, so we try and add the Apache SAPI INI files as
 * well. In case it exists, we also add the default `php.ini` file to the list.
 *
 * The returned array is somewhat sorted to have the "default" INI file for
 * Datadog (`98-ddtrace.ini`) in the beginning of the array.
 *
 * @see ini_values
 * @return string[]
 */
function find_all_ini_files(array $phpProperties)
{
    $iniFilePaths = [];

    $addIniFiles = function ($path) use (&$iniFilePaths) {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $ini) {
            if (!is_file($path . '/' . $ini) || substr($ini, -4) !== '.ini') {
                continue;
            }
            $iniFile = $path . '/' . $ini;
            if (strpos($ini, '98-ddtrace.ini') !== false) {
                array_unshift($iniFilePaths, $iniFile);
            } else {
                $iniFilePaths[] = $iniFile;
            }
        }
    };

    if ($phpProperties[INI_SCANDIR]) {
        $addIniFiles($phpProperties[INI_SCANDIR]);

        if (strpos($phpProperties[INI_SCANDIR], '/cli/conf.d') !== false) {
            /* debian based distros have INI folders split by SAPI, in a predefined way:
             *   - <...>/cli/conf.d       <-- we know this from php -i
             *   - <...>/apache2/conf.d   <-- we derive this from relative path
             *   - <...>/fpm/conf.d       <-- we derive this from relative path
             */
            $apacheConfd = str_replace('/cli/conf.d', '/apache2/conf.d', $phpProperties[INI_SCANDIR]);
            $addIniFiles($apacheConfd);
        }
    }

    if (isset($phpProperties[INI_MAIN]) && is_file($phpProperties[INI_MAIN])) {
        $iniFilePaths = [$phpProperties[INI_MAIN]];
    }

    return filter_ssi_ini_paths($iniFilePaths);
}

/**
 * This function will find the main INI file(s) for the given `$phpProperties`.
 *
 * The "main" or "default" INI file is either a `98-ddtrace.ini` file or
 * another INI file that loads the extension (or rephrasing this as: the file
 * the has the `extension = ddtrace` line in it).
 *
 * In most cases this function will return an array with exactly one element,
 * only in case we detect a Debian based distribution, it might contain two
 * elements, as debian based distributions split the INI folders based on SAPI
 * and we include those as well.
 *
 * The `$phpProperties` can be retrieved by calling `ini_values($pathToPHPBinary)`.
 *
 * @see ini_values
 * @return string[]
 */
function find_main_ini_files(array $phpProperties)
{
    if (isset($phpProperties[INI_SCANDIR])) {

        $pos = strpos($phpProperties[INI_SCANDIR], \PATH_SEPARATOR);
        if ($pos !== false) {
            // https://www.php.net/manual/en/configuration.file.php#configuration.file.scandir
            $phpProperties[INI_SCANDIR] = current(array_filter(explode(\PATH_SEPARATOR, $phpProperties[INI_SCANDIR])));
        }

        $iniFileName = '98-ddtrace.ini';
        // Search for pre-existing files with extension = ddtrace.so to avoid conflicts
        // See issue https://github.com/DataDog/dd-trace-php/issues/1833
        if (is_dir($phpProperties[INI_SCANDIR])) {
            foreach (scandir($phpProperties[INI_SCANDIR]) as $ini) {
                $path = "{$phpProperties[INI_SCANDIR]}/$ini";
                if (is_file($path)) {
                    // match /path/to/ddtrace.so, plain extension = ddtrace or future extensions like ddtrace.dll
                    if (preg_match("(^\s*extension\s*=\s*(\S*ddtrace)\b)m", file_get_contents($path), $res)) {
                        if (basename($res[1]) == "ddtrace") {
                            $iniFileName = $ini;
                        }
                    }
                }
            }
        }

        $iniFilePaths = [$phpProperties[INI_SCANDIR] . '/' . $iniFileName];

        if (strpos($phpProperties[INI_SCANDIR], '/cli/conf.d') !== false) {
            /* debian based distros have INI folders split by SAPI, in a predefined way:
             *   - <...>/cli/conf.d       <-- we know this from php -i
             *   - <...>/apache2/conf.d   <-- we derive this from relative path
             *   - <...>/fpm/conf.d       <-- we derive this from relative path
             */
            $apacheConfd = str_replace('/cli/conf.d', '/apache2/conf.d', $phpProperties[INI_SCANDIR]);
            if (is_dir($apacheConfd)) {
                $iniFilePaths[] = "$apacheConfd/$iniFileName";
            }
        }
    } else {
        $iniFileName = $phpProperties[INI_MAIN];
        $iniFilePaths = [$iniFileName];
    }

    return filter_ssi_ini_paths($iniFilePaths);
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
    if (IS_WINDOWS && file_exists($destination)) {
        // We have to blackhole it in tempdir because it is likely currently loaded and may not be replaced in place.
        rename($destination, getenv("TEMP") . "\\" . time() . "-" . basename($destination));
    }

    $destinationDir = dirname($destination);
    if (!file_exists($destinationDir)) {
        execute_or_exit(
            "Cannot create directory '$destinationDir'",
            "mkdir " . (IS_WINDOWS ? "" : "-p ") . escapeshellarg($destinationDir)
        );
    }

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
        $extensionDir = isset($options[OPT_EXTENSION_DIR]) ? $options[OPT_EXTENSION_DIR] : $phpProperties[EXTENSION_DIR];

        $extensionDestinations = [
            $extensionDir . '/' . EXTENSION_PREFIX . 'ddtrace.' . EXTENSION_SUFFIX,
            $extensionDir . '/' . EXTENSION_PREFIX . 'datadog-profiling.' . EXTENSION_SUFFIX,
            $extensionDir . '/' . EXTENSION_PREFIX . 'ddappsec.' . EXTENSION_SUFFIX,
        ];

        $iniFileName = '98-ddtrace.ini';
        if (isset($phpProperties[INI_SCANDIR])) {
            $iniFilePaths = [$phpProperties[INI_SCANDIR] . '/' . $iniFileName];

            if (strpos('/cli/conf.d', $phpProperties[INI_SCANDIR]) >= 0) {
                /* debian based distros have INI folders split by SAPI, in a predefined way:
                 *   - <...>/cli/conf.d       <-- we know this from php -i
                 *   - <...>/apache2/conf.d    <-- we derive this from relative path
                 *   - <...>/fpm/conf.d       <-- we derive this from relative path
                 */
                $apacheConfd = str_replace('/cli/conf.d', '/apache2/conf.d', $phpProperties[INI_SCANDIR]);
                if (is_dir($apacheConfd)) {
                    $iniFilePaths[] = "$apacheConfd/$iniFileName";
                }
            }
        } else {
            if (!isset($phpProperties[INI_MAIN])) {
                print_error_and_exit(
                    "It is not possible to perform uninstallation on this "
                    . "system because there is no scan directory and no "
                    . "configuration file loaded."
                );
            }

            $iniFilePaths = [$phpProperties[INI_MAIN]];
        }

        /* Actual uninstall
         *  1) comment out extension=ddtrace.so
         *  2) remove ddtrace.so
         */
        foreach ($iniFilePaths as $iniFilePath) {
            if (is_link($iniFilePath)) {
                $iniFilePath = readlink($iniFilePath);
            }
            if (file_exists($iniFilePath)) {
                $isDatadogIni = basename($iniFilePath) == $iniFileName;
                modify_ini_file($iniFilePath, function ($iniFileContents) use ($isDatadogIni) {
                    // if it's not our own ini, we need to match for datadog extensions specifically
                    if ($isDatadogIni) {
                        return preg_replace("(^\s*?(zend_)?extension\s*=)m", "; $0", $iniFileContents);
                    }
                    return preg_replace("(^\s*?(zend_)?extension\s*=(?=.*(ddtrace|datadog-profiling|ddappsec)))m", "; $0", $iniFileContents);
                });
                echo "Disabled all" . ($isDatadogIni ? "" : " Datadog") . " modules in INI file '$iniFilePath'. "
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
        $selectedBinaries = pick_binaries_interactive($options, search_php_binaries());
    } else {
        foreach ($options[OPT_PHP_BIN] as $command) {
            if ($command == "all") {
                foreach (search_php_binaries() as $command => $binaryinfo) {
                    if (!$binaryinfo["shebang"]) {
                        $selectedBinaries[$command] = $binaryinfo["path"];
                    }
                }
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

function search_for_working_ldconfig()
{
    static $path;

    if ($path) {
        return $path;
    }

    $paths = [
        "/sbin", /* this is most likely path */
        "/usr/sbin",
        "/usr/local/sbin",
        "/bin",
        "/usr/bin",
        "/usr/local/bin",
    ];

    $search = function (&$path) {
        exec("find $path -name ldconfig", $found, $result);

        return $result == 0
            ? ($path = end($found))
            : null;
    };

    /* searching individual paths is much faster than searching
        them all */
    foreach ($paths as $path) {
        if ($search($path)) {
            return $path;
        }
    }

    /* probably won't get this far, but just in case */
    foreach (explode(":", getenv("PATH")) as $path) {
        if (!in_array($path, $paths)) {
            if ($search($path)) {
                return $path;
            }
        }
    }

    /*
        we cannot find a working ldconfig binary on this system,
        fall back on previous behaviour:

        there is a slim outside chance that exec() expands ldconfig
    */
    return $path = "ldconfig";
}

/**
 * Checks if a library is available or not in an OS-independent way.
 *
 * @param string $requiredLibrary E.g. libcurl
 * @return void
 */
function check_library_prerequisite_or_exit($requiredLibrary)
{
    if (IS_WINDOWS) {
        // No particular requirements here
        return;
    }

    if (is_alpine()) {
        $lastLine = execute_or_exit(
            "Error while searching for library '$requiredLibrary'.",
            "find /usr/local/lib /usr/lib -type f -name '*{$requiredLibrary}*.so*'"
        );
    } else {
        $ldconfig = search_for_working_ldconfig();
        $lastLine = execute_or_exit(
            "Cannot find library '$requiredLibrary'",
            "$ldconfig -p | grep $requiredLibrary"
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
 * @param string $extName E.g. json
 * @return void
 */
function check_php_ext_prerequisite_or_exit($binary, $extName)
{
    $extensions = shell_exec(escapeshellarg($binary) . " -m");

    // See: https://github.com/DataDog/dd-trace-php/issues/2787
    if ($extensions === null || $extensions === false || strpos($extensions, '[PHP Modules]') === false) {
        echo "WARNING: The output of '$binary -m' could not be reliably checked. Please make sure you have the PHP extension '$extName' installed.\n";
        return;
    }
    if (!in_array($extName, array_map("trim", explode("\n", $extensions)))) {
        print_error_and_exit("Required PHP extension '$extName' not found.\n");
    }
}

/**
 * @return bool
 */
function is_alpine()
{
    if (IS_WINDOWS) {
        return false;
    }

    $osInfoFile = '/etc/os-release';
    // if /etc/os-release is not readable then assume it's not alpine.
    if (!is_readable($osInfoFile)) {
        return false;
    }
    return false !== stripos(file_get_contents($osInfoFile), 'alpine');
}

/**
 * Returns the host architecture, e.g. x86_64, aarch64
 *
 * @return string
 */
function get_architecture()
{
    if (IS_WINDOWS) {
        if (PHP_INT_SIZE === 4) {
            // we don't support that one, but we include it so that the installer can properly fail
            return "x86";
        } else {
            if ($env = getenv("PROCESSOR_ARCHITECTURE")) {
                return $env === "AMD64" ? "x86_64" : strtolower($env);
            }
            // fallback
            return "x86_64";
        }
    }

    return execute_or_exit(
        "Cannot detect host architecture (uname -m)",
        "uname -m"
    );
}

/**
 * @return array|false
 */
function parse_cli_arguments($argv = null)
{
    if (is_null($argv)) {
        $argv = $_SERVER['argv'];
    }

    // strip the application name
    array_shift($argv);

    $arguments = [
        'cmd' => null,
        'opts' => [],
    ];

    while (null !== $token = array_shift($argv)) {
        if (substr($token, 0, 2) === '--') {
            // parse long option
            $key = substr($token, 2);
            $value = false;
            // --php-bin=php
            if (strpos($key, '=') !== false) {
                list($key, $value) = explode('=', $key, 2);
            } else {
                // look ahead to next $token
                if (isset($argv[0]) && substr($argv[0], 0, 1) !== '-') {
                    $value = array_shift($argv);
                    if ($value === null) {
                        $value = false;
                    }
                }
            }
        } elseif (substr($token, 0, 1) === '-') {
            // parse short option
            $key = $token[1];
            $value = false;
            if (strlen($token) === 2) {
                // -d datadog.profiling.enabled or -h
                // look ahead to next $token
                if (isset($argv[0]) && substr($argv[0], 0, 1) !== '-') {
                    $value = array_shift($argv);
                    if ($value === null) {
                        $value = false;
                    }
                }
            } else {
                // -ddatadog.profiling.enabled
                $value = substr($token, 2);
            }
        } else {
            if (substr($token, 0, 2) === 'DD') {
                // we do support using environment variables as well, all those
                // start with DD_, so we make sure to check this and then map
                // to an INI setting. Example
                // php datadog-setup.php config set --php-bin all DD_ENV=prod
                $key = 'd';
                list($env, $value) = explode('=', $token, 2);
                $ini = map_env_to_ini($env);
                if ($ini === null) {
                    echo "Parse error at token '$token', environment variable not recognized.", PHP_EOL;
                    return false;
                }
                $value = $ini . '=' . $value;
            } else {
                if (count($arguments['opts'])) {
                    // php datadog-setup.php --php-bin=all php6
                    // The "php6" is a problem
                    echo "Parse error at token '$token'", PHP_EOL;
                    return false;
                }
                // parse command
                if ($arguments['cmd'] === null) {
                    $arguments['cmd'] = $token;
                } else {
                    $arguments['cmd'] .= ' ' . $token;
                }
                continue;
            }
        }

        if (!isset($arguments['opts'][$key])) {
            $arguments['opts'][$key] = $value;
        } elseif (is_string($arguments['opts'][$key])) {
            $arguments['opts'][$key] = [
                $arguments['opts'][$key],
                $value,
            ];
        } else {
            $arguments['opts'][$key][] = $value;
        }
    }

    if ($arguments['cmd'] === null) {
        $arguments['cmd'] = 'install';
    }

    return $arguments;
}

/**
 * Parses command line options provided by the user and generate a normalized $options array.
 * @return array
 */
function parse_validate_user_options()
{
    $args = parse_cli_arguments();

    // Help and exit
    if ($args === false || key_exists('h', $args['opts']) || key_exists(OPT_HELP, $args['opts'])) {
        print_help();
        exit(0);
    }

    $options = $args['opts'];
    $normalizedOptions = [];

    $normalizedOptions[OPT_UNINSTALL] = isset($options[OPT_UNINSTALL]);

    if (!$normalizedOptions[OPT_UNINSTALL]) {
        if (isset($options[OPT_FILE])) {
            if (is_array($options[OPT_FILE])) {
                print_error_and_exit('Only one --file can be provided', true);
            }
            $normalizedOptions[OPT_FILE] = $options[OPT_FILE];
        }
    }

    if (isset($options[OPT_PHP_BIN])) {
        if ($options[OPT_PHP_BIN] === false) {
            print_error_and_exit('PHP binary needs to be provided when using --php-bin', true);
        }
        $normalizedOptions[OPT_PHP_BIN] = is_array($options[OPT_PHP_BIN])
            ? $options[OPT_PHP_BIN]
            : [$options[OPT_PHP_BIN]];
    }

    if (isset($options[OPT_PHP_INI])) {
        $normalizedOptions[OPT_PHP_INI] = is_array($options[OPT_PHP_INI])
            ? $options[OPT_PHP_INI]
            : [$options[OPT_PHP_INI]];
    }

    $normalizedOptions[OPT_INSTALL_DIR] = isset($options[OPT_INSTALL_DIR])
        ? rtrim($options[OPT_INSTALL_DIR], '/')
        : DEFAULT_INSTALL_DIR;
    $normalizedOptions[OPT_INSTALL_DIR] = $normalizedOptions[OPT_INSTALL_DIR] . '/dd-library';

    $normalizedOptions[OPT_EXTENSION_DIR] = isset($options[OPT_EXTENSION_DIR])
        ? rtrim($options[OPT_EXTENSION_DIR], '/')
        : null;

    $normalizedOptions[OPT_ENABLE_APPSEC] = isset($options[OPT_ENABLE_APPSEC]);
    $normalizedOptions[OPT_ENABLE_PROFILING] = isset($options[OPT_ENABLE_PROFILING]);

    if (isset($options[OPT_INI_SETTING])) {
        $normalizedOptions[OPT_INI_SETTING] = is_array($options[OPT_INI_SETTING])
            ? $options[OPT_INI_SETTING]
            : [$options[OPT_INI_SETTING]];
    }

    return [
        'cmd' => $args['cmd'],
        'opts' => $normalizedOptions,
    ];
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
 * @param array $options
 * @param array $php_binaries
 * @return array
 */
function pick_binaries_interactive($options, array $php_binaries)
{
    echo sprintf(
        "Multiple PHP binaries detected. Please select the binaries the datadog library will be %s:\n\n",
        $options[OPT_UNINSTALL] ? "uninstalled from" : "installed to"
    );
    $commands = array_keys($php_binaries);
    for ($index = 0; $index < count($commands); $index++) {
        $command = $commands[$index];
        $fullPath = $php_binaries[$command]["path"];
        echo "  "
            . str_pad($index + 1, 2, ' ', STR_PAD_LEFT)
            . ". "
            . ($command !== $fullPath ? "$command --> " : "")
            . $fullPath
            . ($php_binaries[$command]["shebang"] ? " (not a binary)" : "")
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
            return pick_binaries_interactive($options, $php_binaries);
        }
        $command = $commands[$index];
        $pickedBinaries[$command] = $php_binaries[$command]["path"];
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
            $exitMessage
            . "\nFailed command (return code $returnCode): $command\n"
            . "---- Output ----\n"
            . implode("\n", $output)
            . "\n---- End of output ----\n"
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
function download($url, $destination, $retry = false)
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

        if ($retry) {
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($httpCode == 404) {
                return false;
            }
        }

        curl_close($ch);
        fclose($fp);

        if (false !== $return) {
            echo $okMessage;
            return true;
        }
        // Otherwise we attempt other methods
    }

    // curl
    $statusCode = 0;
    $output = [];
    // on Windows curl is an alias for Invoke-WebRequest on powershell
    if (!IS_WINDOWS && false !== exec('curl --version', $output, $statusCode) && $statusCode === 0) {
        $curlInvocationStatusCode = 0;
        system(
            'curl -f -L --output ' . escapeshellarg($destination) . ' ' . escapeshellarg($url),
            $curlInvocationStatusCode
        );

        if ($curlInvocationStatusCode === 0) {
            echo $okMessage;
            return true;
        }
        // Otherwise we attempt other methods
    }

    // file_get_contents
    if (is_truthy(ini_get('allow_url_fopen')) && extension_loaded('openssl')) {
        ini_set("memory_limit", "2G"); // increase memory limit otherwise we may run OOM here.
        $data = @file_get_contents($url);
        // PHP doesn't like too long location headers, and on PHP 7.3 and older they weren't read at all.
        // But this only really matters for CircleCI artifacts, so not too bad.
        if ($data == "") {
            foreach ($http_response_header as $header) {
                if (stripos($header, "location: ") === 0) {
                    $data = file_get_contents(substr($header, 10));
                    goto got_data;
                }
            }
            if (PHP_VERSION_ID < 70400) {
                goto next_method; // location redirects may not be read on 7.3 and older
            }
        }
        got_data: ;
        if ($data == "" || false === file_put_contents($destination, $data)) {
            if ($retry) {
                return false;
            }
            print_error_and_exit("Error while downloading the installable archive from $url\n");
        }

        echo $okMessage;
        return true;

        next_method:
    }

    if (IS_WINDOWS) {
        $proc = proc_open(
            'powershell -',
            [['pipe', 'r'], STDOUT, STDERR],
            $pipes,
            null,
            null,
            ["bypass_shell" => true]
        );
        $input = "[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; Invoke-WebRequest -Out '" . addcslashes($destination, "\\'") . "' '" . addcslashes($url, "\\'") . "'";
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        do {
            $status = proc_get_status($proc);
            usleep(100000);
        } while ($status['running']);
        if ($status['exitcode'] === 0) {
            echo $okMessage;
            return true;
        }
        // Otherwise we attempt other methods
    }

    if ($retry) {
        return false;
    }

    echo "Error: Cannot download the installable archive.\n";
    echo "  One of the following prerequisites must be satisfied:\n";
    echo "    - PHP ext-curl extension is installed\n";
    if (!IS_WINDOWS) {
        echo "    - curl CLI command is available\n";
    } else {
        echo "    - Invoke-WebRequest exists on the powershell\n";
    }
    echo "    - the INI setting 'allow_url_fopen=1' and the ext-openssl extension is installed\n";

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
    $properties = [PHP_VER, INI_MAIN, INI_SCANDIR, EXTENSION_DIR, THREAD_SAFETY, PHP_API, IS_DEBUG];
    $lines = [];
    // Timezone is irrelevant to this script. Quick-and-dirty workaround to the PHP 5 warning with missing timezone
    exec(escapeshellarg($binary) . " -d date.timezone=UTC -i", $lines);
    $found = [];
    foreach ($lines as $line) {
        $parts = explode('=>', $line);
        if (count($parts) === 2 || count($parts) === 3) {
            $key = trim($parts[0]);
            if (in_array($key, $properties)) {
                $value = trim(count($parts) === 2 ? $parts[1] : $parts[2]);

                if ($value === "(none)") {
                    continue;
                }

                $found[$key] = $value;
            }
        }
    }

    if ($found[EXTENSION_DIR] == "") {
        $found[EXTENSION_DIR] = dirname(PHP_BINARY);
    } elseif ($found[EXTENSION_DIR][0] != "/" && (!IS_WINDOWS || !preg_match('~^([A-Z]:[\\\\/]|\\\\{2})~i', $found[EXTENSION_DIR]))) {
        $found[EXTENSION_DIR] = dirname(PHP_BINARY) . '/' . $found[EXTENSION_DIR];
    }

    return $found;
}

function is_truthy($value)
{
    if ($value === null) {
        return false;
    }

    $normalized = trim(strtolower($value));
    return in_array($normalized, ['1', 'true', 'yes', 'enabled']);
}

/**
 * @param string $prefix Default ''. Used for testing purposes only.
 * @return array
 */
function search_php_binaries($prefix = '')
{
    echo "Searching for available php binaries, this operation might take a while.\n";

    $resolvedPaths = [];

    $allPossibleCommands = build_known_command_names_matrix();

    // First, we search in $PATH, for php, php7, php74, php7.4, php7.4-fpm, etc....
    foreach ($allPossibleCommands as $command) {
        if ($resolvedPath = resolve_command_full_path($command)) {
            $resolvedPaths[$command] = $resolvedPath;
        }
    }

    // Then we search in known possible locations for popular installable paths on different systems.
    $pathsFound = [];
    if (IS_WINDOWS) {
        $bootDisk = realpath('/');

        $standardPaths = [
            dirname(PHP_BINARY),
            PHP_BINDIR,
            $bootDisk . 'WINDOWS',
        ];

        foreach (scandir($bootDisk) as $file) {
            if (stripos($file, "php") !== false) {
                $standardPaths[] = "$bootDisk$file";
            }
        }

        $chocolateyDir = getenv("ChocolateyToolsLocation") ?: $bootDisk . 'tools'; // chocolatey tools location
        if (is_dir($chocolateyDir)) {
            foreach (scandir($chocolateyDir) as $file) {
                if (stripos($file, "php") !== false) {
                    $standardPaths[] = "$chocolateyDir/$file";
                }
            }
        }

        // Windows paths are case-insensitive
        $standardPaths = array_intersect_key(array_map('strtolower', $standardPaths), array_unique($standardPaths));

        foreach ($standardPaths as $standardPath) {
            foreach ($allPossibleCommands as $command) {
                $resolvedPath = $standardPath . '\\' . $command;
                if (file_exists($resolvedPath)) {
                    $pathsFound[] = $resolvedPath;
                }
            }
        }
    } else {
        $standardPaths = [
            $prefix . '/usr/bin',
            $prefix . '/usr/sbin',
            $prefix . '/usr/local/bin',
            $prefix . '/usr/local/sbin',
        ];

        $remiSafePaths = array_map(function ($phpVersion) use ($prefix) {
            list($major, $minor) = explode('.', $phpVersion);
            /* php is installed to /usr/bin/php{$major}{$minor} so we do not need to do anything special, while php-fpm
             * is installed to /opt/remi/php{$major}{$minor}/root/usr/sbin and it needs to be added to the searched
             * locations.
             */
            return "{$prefix}/opt/remi/php{$major}{$minor}/root/usr/sbin";
        }, get_supported_php_versions());

        $pleskPaths = array_map(function ($phpVersion) use ($prefix) {
            return "/opt/plesk/php/$phpVersion/bin";
        }, get_supported_php_versions());

        $escapedSearchLocations = implode(
            ' ',
            array_map('escapeshellarg', array_merge($standardPaths, $remiSafePaths, $pleskPaths))
        );
        $escapedCommandNamesForFind = implode(
            ' -o ',
            array_map(
                function ($cmd) {
                    return '-name ' . escapeshellarg($cmd);
                },
                $allPossibleCommands
            )
        );

        exec(
            "find -L $escapedSearchLocations -type f \( $escapedCommandNamesForFind \) 2>/dev/null",
            $pathsFound
        );
    }

    foreach ($pathsFound as $path) {
        $resolved = realpath($path) ?: $path;
        if (in_array($resolved, $resolvedPaths)) {
            continue;
        }
        $resolvedPaths[$path] = $resolved;
    }

    $results = [];
    foreach ($resolvedPaths as $command => $realpath) {
        $hasShebang = file_get_contents($realpath, false, null, 0, 2) === "#!";
        $results[$command] = [
            "shebang" => $hasShebang,
            "path" => $realpath,
        ];
    }

    return $results;
}

/**
 * @param mixed $command
 * @return string|false
 */
function resolve_command_full_path($command)
{
    if (IS_WINDOWS) {
        if (!strpbrk($command, "/\\")) {
            $path = shell_exec("where " . escapeshellarg($command) . " 2>NUL");
            if ($path === null) {
                // command is not defined
                return false;
            }
            $path = trim($path, "\r\n");
        } elseif (!file_exists($command)) {
            return false;
        } else {
            $path = $command;
        }
    } else {
        $path = exec("command -v " . escapeshellarg($command));
        if (empty($path)) {
            // command is not defined
            return false;
        }
    }

    // Resolving symlinks
    return realpath($path) ?: $path;
}

function build_known_command_names_matrix()
{
    $results = ['php', 'php-fpm'];

    foreach (get_supported_php_versions() as $phpVersion) {
        list($major, $minor) = explode('.', $phpVersion);
        array_push(
            $results,
            "php{$major}",
            "php{$major}{$minor}",
            "php{$major}.{$minor}",
            "php{$major}-fpm",
            "php{$major}{$minor}-fpm",
            "php{$major}.{$minor}-fpm",
            "php-fpm{$major}",
            "php-fpm{$major}{$minor}",
            "php-fpm{$major}.{$minor}"
        );
    }

    if (IS_WINDOWS) {
        foreach ($results as &$result) {
            $result .= ".exe";
        }
    }

    return array_unique($results);
}

/**
 * Adds ini entries that are not present in the provided ini file.
 *
 * @param string $iniFilePath
 */
function add_missing_ini_settings($iniFilePath, $settings, $replacements)
{
    modify_ini_file($iniFilePath, function ($iniFileContent) use ($settings, $replacements) {
        $formattedMissingProperties = '';

        // Replace twice, so that some replacements can take effect on newly inserted missing inis too
        // As well as missing ini detection must not find legacy names which we replace
        foreach ($replacements as $from => $to) {
            $iniFileContent = preg_replace($from, $to, $iniFileContent);
        }

        foreach ($settings as $setting) {
            // The extension setting is not unique, so make sure we check that the
            // right extension setting is available.
            $settingRegex = '(' . preg_quote($setting['name']) . '\s?=\s?';
            if ($setting['name'] === 'extension' || $setting['name'] == 'zend_extension') {
                $settingRegex .= ".*".preg_quote($setting['default']);
            }
            $settingRegex .= ')';

            $settingMightExist = 1 === preg_match($settingRegex, $iniFileContent);

            if ($settingMightExist) {
                continue;
            }

            // Formatting the setting to be added.
            $description = is_string($setting['description'])
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

        $iniFileContent .= $formattedMissingProperties;

        foreach ($replacements as $from => $to) {
            $iniFileContent = preg_replace($from, $to, $iniFileContent);
        }

        return $iniFileContent;
    });
}

/**
 * Changes a given ini file
 */
function modify_ini_file($iniFilePath, $callback) {
    $iniFileContent = file_get_contents($iniFilePath);

    $newIniFileContent = $callback($iniFileContent);

    if ($iniFileContent !== $newIniFileContent) {
        if (false === file_put_contents($iniFilePath, $newIniFileContent)) {
            print_error_and_exit("Cannot change the settings of the INI file $iniFilePath");
        }
    }
}

/**
 * Maps a given environment variable name to an ini setting. Returns `null` in
 * case the environment variable does not start with `DD` or is otherwise
 * invalid.
 *
 * @param string $env
 * @return string|null
 */
function map_env_to_ini($env)
{
    $setting = explode('_', $env, 3);
    if (!isset($setting[0]) || $setting[0] !== 'DD' || !isset($setting[1])) {
        return null;
    }
    $ini = 'datadog.';
    switch ($setting[1]) {
        case 'PROFILING':
        case 'TRACE':
        case 'APPSEC':
            if (!isset($setting[2])) {
                // this would mean $env was DD_TRACE or DD_PROFILING or DD_APPSEC
                return null;
            }
            $ini .= strtolower($setting[1]) . '.' . strtolower($setting[2]);
            break;
        default:
            // for cases like DD_ENV or DD_DOGSTATSD_URL, ...
            $ini .= strtolower($setting[1]);
            if (isset($setting[2])) {
                $ini .= '_' . strtolower($setting[2]);
            }
    }
    return $ini;
}

/**
 * Returns array of associative arrays with the following keys:
 *   - name (string): the setting name;
 *   - default (string): the default value;
 *   - commented (bool): whether this setting should be commented or not when added;
 *   - description (string|string[]): A string (or an array of strings, each representing a line) that describes
 *                                    the setting.
 *
 * @param string $sourcesDir
 * @param string $appsecHelperPath
 * @param string $appsecRulesPath
 * @return array
 */
function get_ini_settings($sourcesDir, $appsecHelperPath, $appsecRulesPath)
{
    // phpcs:disable Generic.Files.LineLength.TooLong
    return [
        [
            'name' => 'extension',
            'default' => 'ddtrace' . (IS_WINDOWS ? "" : "." . EXTENSION_SUFFIX),
            'commented' => false,
            'description' => 'Enables or disables tracing (set by the installer, do not change it)',
        ],
        [
            'name' => 'extension',
            'default' => 'datadog-profiling' . (IS_WINDOWS ? "" : "." . EXTENSION_SUFFIX),
            'commented' => true,
            'description' => 'Enables the profiling module',
        ],
        [
            'name' => 'extension',
            'default' => 'ddappsec' . (IS_WINDOWS ? "" : "." . EXTENSION_SUFFIX),
            'commented' => false,
            'description' => 'Enables the appsec module',
        ],

        /* IMPORTANT! These extension ones are shifted off for config commands.
         * If the number of extension= things changes, change the constant
         * CMD_CONFIG_NUM_SHIFT accordingly.
         */

        [
            'name' => 'datadog.profiling.enabled',
            'default' => '1',
            'commented' => true,
            'description' => 'Enable the Datadog profiling module.',
        ],
        [
            'name' => 'datadog.trace.endpoint_collection_enabled',
            'default' => '1',
            'commented' => true,
            'description' => 'Whether to enable the endpoint data collection in profiles.',
        ],
        [
            'name' => 'datadog.profiling.experimental_cpu_time_enabled',
            'default' => '1',
            'commented' => true,
            'description' => 'Enable the CPU profile type.',
        ],
        [
            'name' => 'datadog.profiling.allocation_enabled',
            'default' => '1',
            'commented' => true,
            'description' => 'Enable the allocation profile type.',
        ],
        [
            'name' => 'datadog.profiling.experimental_allocation_enabled',
            'default' => '1',
            'commented' => true,
            'description' => 'Enable the allocation profile type. Superseded by `datadog.profiling.allocation_enabled`.',
        ],
        [
            'name' => 'datadog.profiling.exception_enabled',
            'default' => '1',
            'commented' => true,
            'description' => 'Enable the exception profile type.',
        ],
        [
            'name' => 'datadog.profiling.experimental_exception_enabled',
            'default' => '1',
            'commented' => true,
            'description' => 'Enable the exception profile type. Superseded by `datadog.profiling.exception_enabled`.',
        ],
        [
            'name' => 'datadog.profiling.exception_sampling_distance',
            'default' => '100',
            'commented' => true,
            'description' => 'Sampling distance for exception profiling (the higher the distance, the fewer samples are created).',
        ],
        [
            'name' => 'datadog.profiling.experimental_exception_sampling_distance',
            'default' => '100',
            'commented' => true,
            'description' => 'Sampling distance for exception profiling (the higher the distance, the fewer samples are created). Superseded by `datadog.profiling.exception_sampling_distance`.',
        ],
        [
            'name' => 'datadog.profiling.log_level',
            'default' => 'off',
            'commented' => true,
            'description' => 'Set the profiler’s log level.'
                . ' Acceptable values are off, error, warn, info, debug, and trace.'
                . ' The profiler’s logs are written to the standard error stream of the process.',
        ],
        [
            'name' => 'datadog.profiling.timeline_enabled',
            'default' => '1',
            'commented' => true,
            'description' => 'Enable the timeline profile type.',
        ],

        [
            'name' => 'datadog.trace.sources_path',
            'default' => $sourcesDir,
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
        [
            'name' => 'datadog.appsec.enabled',
            'default' => 'Off',
            'commented' => true,
            'description' => [
                'Enables or disables the loaded dd-appsec extension.',
                'If disabled, the extension will do no work during the requests.',
                'If not present/commented out, appsec will be enabled/disabled by remote config',
            ],
        ],
        [
            'name' => 'datadog.appsec.log_level',
            'default' => 'warn',
            'commented' => true,
            'description' => [
                'Sets the verbosity of the logs of the dd-appsec extension.',
                'The valid values are \'off\', \'error\', \'fatal\', \'warn\' (or \'warning\'), \'info\',',
                '\'debug\' and \'trace\', in increasing order of verbosity',
            ],
        ],
        [
            'name' => 'datadog.appsec.log_file',
            'default' => 'php_error_reporting',
            'commented' => true,
            'description' => [
                'The destination of the log messages. Valid values are \'php_error_reporting\'',
                '(issues PHP notices or warnings), \'syslog\', \'stdout\', \'stderr\', or an',
                'arbitrary file name to which the messages will be appended',
            ],
        ],
        [
            'name' => 'datadog.appsec.helper_path',
            'default' => $appsecHelperPath,
            'commented' => false,
            'description' => [
                'The path to the shared library that the appsec extension loads in the sidecar.',
                'This ini setting is configured by the installer',
            ],
        ],
        [
            'name' => 'datadog.appsec.rules',
            'default' => $appsecRulesPath,
            'commented' => true,
            'description' => [
                'The path to the rules json file. The sidecar process must be able to read the',
                'file. This ini setting is configured by the installer',
            ],
        ],
        [
            'name' => 'datadog.appsec.helper_runtime_path',
            'default' => '/tmp/',
            'commented' => true,
            'description' => [
                'The directory where to place the lock file and the UNIX socket that the',
                'extension uses communicate with the helper inside sidecar. Ultimately,',
                'the paths include the version of the extension and uid/gid.',
            ],
        ],
        [
            'name' => 'datadog.appsec.helper_log_file',
            'default' => '/dev/null',
            'commented' => true,
            'description' => [
                'The location of the log file of the helper. This defaults to /dev/null',
                '(the log messages will be discarded).',
            ],
        ],
        [
            'name' => 'datadog.appsec.helper_log_level',
            'default' => 'info',
            'commented' => true,
            'description' => [
                'The verbosity of the logging of the appsec helper loaded in the sidecar. ',
                'Valid values are trace, debug, info, warn, err, critical and off',
            ],
        ],
        [
            'name' => 'datadog.remote_config_enabled',
            'default' => 'On',
            'commented' => true,
            'description' => 'Enables or disables remote configuration. On by default',
        ],
        [
            'name' => 'datadog.remote_config_poll_interval',
            'default' => '1000',
            'commented' => true,
            'description' => 'In milliseconds, the period at which the agent is polled for new configurations',
        ],
        [
            'name' => 'datadog.appsec.http_blocked_template_html',
            'default' => '',
            'commented' => true,
            'description' => 'Customises the HTML output provided on a blocked request',
        ],
        [
            'name' => 'datadog.appsec.http_blocked_template_json',
            'default' => '',
            'commented' => true,
            'description' => 'Customises the JSON output provided on a blocked request',
        ],
    ];
    // phpcs:enable Generic.Files.LineLength.TooLong
}

/**
 * @return string[]
 */
function get_supported_php_versions()
{
    return ['7.0', '7.1', '7.2', '7.3', '7.4', '8.0', '8.1', '8.2', '8.3', '8.4'];
}

main();

// polyfill for PHP 5.4 where the `array_column()` function did not exist,
// remove whenever we drop support for PHP 5.4
if (!function_exists('array_column')) {
    function array_column(array $input, $columnKey, $indexKey = null)
    {
        $result = array();
        foreach ($input as $subArray) {
            if (!is_array($subArray)) {
                continue;
            } elseif (is_null($indexKey)) {
                $result[] = $subArray[$columnKey];
            } else {
                $result[$subArray[$indexKey]] = $subArray[$columnKey];
            }
        }
        return $result;
    }
}
