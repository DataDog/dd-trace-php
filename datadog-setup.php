<?php

// Tests for the installer are in 'dockerfiles/verify_packages/installer'

const INI_SCANDIR = 'Scan this dir for additional .ini files';
const INI_MAIN = 'Loaded Configuration File';
const EXTENSION_DIR = 'extension_dir';
const THREAD_SAFETY = 'Thread Safety';
const PHP_API = 'PHP API';
const IS_DEBUG = 'Debug Build';

// Commands
const CMD_CONFIG_GET = 'config get';
const CMD_CONFIG_SET = 'config set';
const CMD_CONFIG_LIST = 'config list';

// Options
const OPT_HELP = 'help';
const OPT_INSTALL_DIR = 'install-dir';
const OPT_PHP_BIN = 'php-bin';
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
    --install-dir <path>        Install to a specific directory. Default: '/opt/datadog'
    --uninstall                 Uninstall the library from the specified binaries.
    --enable-appsec             Enable the application security monitoring module.
    --enable-profiling          Enable the BETA profiling module.
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
}

/**
 * @param array $options
 * @return Iterator<string, IniRecord>
 */
function config_list(array $options)
{
    $iniSettings = get_ini_settings('', '', '');

    // The first 3 are 'extension' type of settings.
    $iniSettings = array_slice($iniSettings, CMD_CONFIG_NUM_SHIFT);

    // Build an index by unique names for filtering.
    $indexByName = array_column($iniSettings, null, 'name');

    foreach (require_binaries_or_exit($options) as $command => $fullPath) {
        $binaryForLog = ($command === $fullPath) ? $fullPath : "$command ($fullPath)";
        echo "Datadog configuration for binary: $binaryForLog", PHP_EOL;

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
                yield $record->setting => $record;
            }
        }
    }
}

/**
 * This function will print out all Datadog specific PHP INI settings. The list
 * of Datadog specific settings is retrieved by calling `get_ini_settings()`. It
 * will also show the default and INI file this setting was found.
 * The output will be grouped by PHP binary, example:
 *
 * $ php datadog-setup.php config list --php-bin all
 * Searching for available php binaries, this operation might take a while.
 * Datadog configuration for binary: php (/opt/php/8.2/bin/php)
 * datadog.profiling.enabled = On ; default: 1, INI file: /opt/php/etc/conf.d/98-ddtrace.ini
 * datadog.profiling.experimental_allocation_enabled = On ; default: 1, INI file: /opt/php/etc/conf.d/98-ddtrace.ini
 *
 * @see get_ini_settings
 * @return void
 */
function cmd_config_list(array $options)
{
    foreach (config_list($options) as $record) {
        echo "$record->setting = $record->currentValue ; default: $record->defaultValue, INI file: $record->iniFile\n";
    }
}

/**
 * This function will print the specified PHP INI settings. It only prints
 * information for Datadog's own settings; it can't be used to parse opache,
 * xdebug, etc.
 * The output will be grouped by PHP binary, example:
 *
 * $ php datadog-setup.php config get --php-bin all \
 *   -ddatadog.profiling.experimental_allocation_enabled \
 *   -ddatadog.profiling.experimental_cpu_time_enabled \
 *   -dnonexisting \
 *   -dopcache.preload
 * Datadog configuration for binary: php (/opt/php/8.2/bin/php)
 * datadog.profiling.experimental_allocation_enabled = On ; INI file: /opt/php/etc/conf.d/98-ddtrace.ini
 * datadog.profiling.experimental_cpu_time_enabled = On ; INI file: /opt/php/etc/conf.d/98-ddtrace.ini
 * nonexisting => undefined // is missing in INI files
 * opcache.preload => undefined // is missing in INI files
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
            echo '; ', $iniSetting, ' = undefined ; is missing in INI files', PHP_EOL;
        } else {
            foreach ($records[$iniSetting] as $record) {
                // phpcs:disable Generic.Files.LineLength.TooLong
                echo "$record->setting = $record->currentValue ; default: $record->defaultValue, INI file: $record->iniFile\n";
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
function cmd_config_set(array $options): void
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

            foreach ($mainIniFilePaths as $iniFile) {
                $iniFileContent = file_get_contents($iniFile);
                // check for "End of Line" symbol at the end of the file and
                // add in case it is missing
                if (strlen($iniFileContent) > 0 && substr($iniFileContent, -1, 1) !== PHP_EOL) {
                    $iniFileContent .= PHP_EOL;
                }
                $iniFileContent .= implode(' = ', $setting) . PHP_EOL;
                if (file_put_contents($iniFile, $iniFileContent) === false) {
                    echo "Could not set '", $setting[0], "' to '", $setting[1],
                    "' in INI file: ", $iniFile , PHP_EOL;
                    continue;
                }
                echo "Set '", $setting[0], "' to '", $setting[1], "' in INI file: ", $iniFile, PHP_EOL;
            }
        }
    }
}

/**
 * Parse a given INI setting (from CLI) into an array and return it. It also tries
 * to parse the new setting with `parse_ini_string()` to validate it is actually
 * working
 *
 * @return false|array{0:string, 1:string}
 */
function parse_ini_setting(string $setting)
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
 * @return false|int
 */
function update_ini_setting(array $setting, string $iniFile, bool $promoteComment)
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
    $platform = "$architecture-linux-" . (is_alpine() ? 'musl' : 'gnu');

    // Checking required libraries
    check_library_prerequisite_or_exit('libcurl');
    if (is_alpine()) {
        if (is_truthy($options[OPT_ENABLE_PROFILING])) {
            check_library_prerequisite_or_exit('libgcc_s');
        }
    } else {
        if (is_truthy($options[OPT_ENABLE_PROFILING])) {
            check_library_prerequisite_or_exit('libdl.so');
            check_library_prerequisite_or_exit('libgcc_s');
            check_library_prerequisite_or_exit('libpthread');
            check_library_prerequisite_or_exit('librt');
        }
    }

    // Picking the right binaries to install the library
    $selectedBinaries = require_binaries_or_exit($options);
    $interactive = empty($options[OPT_PHP_BIN]);

    // Preparing clean tmp folder to extract files
    $tmpDir = sys_get_temp_dir() . '/dd-install';
    $tmpDirTarGz = $tmpDir . "/dd-library-php-{$platform}.tar.gz";
    $tmpArchiveRoot = $tmpDir . '/dd-library-php';
    $tmpArchiveTraceRoot = $tmpDir . '/dd-library-php/trace';
    $tmpArchiveAppsecRoot = $tmpDir . '/dd-library-php/appsec';
    $tmpArchiveAppsecBin = "{$tmpArchiveAppsecRoot}/bin";
    $tmpArchiveAppsecEtc = "{$tmpArchiveAppsecRoot}/etc";
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
        $url = RELEASE_URL_PREFIX . "dd-library-php-" . RELEASE_VERSION . "-{$platform}.tar.gz";
        download($url, $tmpDirTarGz);
    }
    execute_or_exit(
        "Cannot extract the archive",
        "tar -xf " . escapeshellarg($tmpDirTarGz) . " -C " . escapeshellarg($tmpDir)
    );

    $releaseVersion = trim(file_get_contents("$tmpArchiveRoot/VERSION"));

    $installDir = $options[OPT_INSTALL_DIR] . '/' . $releaseVersion;

    // Tracer sources
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

    // Appsec helper and rules
    if (file_exists($tmpArchiveAppsecRoot)) {
        execute_or_exit(
            "Cannot copy files from '$tmpArchiveAppsecBin' to '$installDir'",
            "cp -rf " . escapeshellarg("$tmpArchiveAppsecBin") . ' ' . escapeshellarg($installDir)
        );
        execute_or_exit(
            "Cannot copy files from '$tmpArchiveAppsecEtc' to '$installDir'",
            "cp -r " . escapeshellarg("$tmpArchiveAppsecEtc") . ' ' . escapeshellarg($installDir)
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
                print_error_and_exit(
                    "It is not possible to perform installation on this "
                    . "system because there is no scan directory and no "
                    . "configuration file loaded."
                );
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

        // Suffix (zts/debug/alpine)
        $extensionSuffix = '';
        if (is_truthy($phpProperties[IS_DEBUG])) {
            $extensionSuffix .= '-debug';
        }
        if (is_truthy($phpProperties[THREAD_SAFETY])) {
            $extensionSuffix .= '-zts';
        }

        // Trace
        $extensionRealPath = "$tmpArchiveTraceRoot/ext/$extensionVersion/ddtrace$extensionSuffix.so";
        if (!file_exists($extensionRealPath)) {
            print_error_and_exit(substr($extensionSuffix ?: '-nts', 1) . ' builds of PHP are currently not supported');
        }

        $extensionDestination = $phpProperties[EXTENSION_DIR] . '/ddtrace.so';
        safe_copy_extension($extensionRealPath, $extensionDestination);

        // Profiling
        // phpcs:disable Generic.Files.LineLength.TooLong
        $profilingExtensionRealPath = "$tmpArchiveProfilingRoot/ext/$extensionVersion/datadog-profiling$extensionSuffix.so";
        // phpcs:enable Generic.Files.LineLength.TooLong
        $shouldInstallProfiling = file_exists($profilingExtensionRealPath);

        if ($shouldInstallProfiling) {
            $profilingExtensionDestination = $phpProperties[EXTENSION_DIR] . '/datadog-profiling.so';
            safe_copy_extension($profilingExtensionRealPath, $profilingExtensionDestination);
        }

        // Appsec
        $appsecExtensionRealPath = "{$tmpArchiveAppsecRoot}/ext/{$extensionVersion}/ddappsec{$extensionSuffix}.so";
        $shouldInstallAppsec = file_exists($appsecExtensionRealPath);

        if ($shouldInstallAppsec) {
            $appsecExtensionDestination = $phpProperties[EXTENSION_DIR] . '/ddappsec.so';
            safe_copy_extension($appsecExtensionRealPath, $appsecExtensionDestination);
        }
        $appSecHelperPath = $installDir . '/bin/ddappsec-helper';

        $iniFilePaths = find_main_ini_files($phpProperties);

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
                    "sed -i 's|ddtrace.request_init_hook|datadog.trace.request_init_hook|g' "
                    . escapeshellarg($iniFilePath)
                );
                execute_or_exit(
                    'Impossible to update the INI settings file.',
                    "sed -i 's@datadog\.trace\.request_init_hook \?= \?\(.*\)@datadog.trace.request_init_hook = '"
                    . escapeshellarg($installDirWrapperPath)
                    . "'@g' " . escapeshellarg($iniFilePath)
                );
                // phpcs:enable Generic.Files.LineLength.TooLong

                /* In order to support upgrading from legacy installation method to new installation method, we replace
                 * "extension = /opt/datadog-php/xyz.so" with "extension =  ddtrace.so" honoring trailing `;`, hence not
                 * automatically re-activating the extension if the user had commented it out.
                 */
                execute_or_exit(
                    'Impossible to update the INI settings file.',
                    "sed -i 's@ \?;\? \?extension \?= \?.*ddtrace.*\(.*\)@extension = ddtrace.so@g' "
                    . escapeshellarg($iniFilePath)
                );


                // Support upgrading from the C based zend_extension.
                execute_or_exit(
                    'Impossible to update the INI settings file.',
                    "sed -i 's@zend_extension \?= \?.*datadog-profiling.*\(.*\)@extension = datadog-profiling.so@g' "
                    . escapeshellarg($iniFilePath)
                );
            }

            add_missing_ini_settings(
                $iniFilePath,
                get_ini_settings($installDirWrapperPath, $appSecHelperPath, $appSecRulesPath)
            );

            // Enabling profiling
            if (is_truthy($options[OPT_ENABLE_PROFILING])) {
                // phpcs:disable Generic.Files.LineLength.TooLong
                if ($shouldInstallProfiling) {
                    execute_or_exit(
                        'Impossible to update the INI settings file.',
                        "sed -i 's@ \?; \?extension \?= \?datadog-profiling.so@extension = datadog-profiling.so@g' "
                        . escapeshellarg($iniFilePath)
                    );
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
                execute_or_exit(
                    'Impossible to update the INI settings file.',
                    "sed -i 's@ \?; \?extension \?= \?ddappsec.so@extension = ddappsec.so@g' "
                    . escapeshellarg($iniFilePath)
                );

                // Update helper path
                execute_or_exit(
                    'Impossible to update the INI settings file.',
                    "sed -i 's@datadog.appsec.helper_path \?= \?.*@datadog.appsec.helper_path = " . $appSecHelperPath . "@g' "
                    . escapeshellarg($iniFilePath)
                );

                // Update and comment rules path
                $rulesPathRegex = $options[OPT_INSTALL_DIR] . "/[0-9\.]*/etc/recommended.json";
                execute_or_exit(
                    'Impossible to update the INI settings file.',
                    "sed -i 's@^[ ;]*datadog.appsec.rules \?= \?" . $rulesPathRegex . "@;datadog.appsec.rules = " . $appSecRulesPath . "@g' "
                    . escapeshellarg($iniFilePath)
                );

                if (is_truthy($options[OPT_ENABLE_APPSEC])) {
                    execute_or_exit(
                        'Impossible to update the INI settings file.',
                        "sed -i 's@;\? \?datadog.appsec.enabled \?=.*$\?@datadog.appsec.enabled = On@g' "
                        . escapeshellarg($iniFilePath)
                    );
                } else {
                    execute_or_exit(
                        'Impossible to update the INI settings file.',
                        "sed -i 's@datadog.appsec.enabled \?=.*$\?@datadog.appsec.enabled = Off@g' "
                        . escapeshellarg($iniFilePath)
                    );
                }
            } else {
                // Ensure AppSec isn't loaded if not compatible
                execute_or_exit(
                    'Impossible to update the INI settings file.',
                    "sed -i 's@extension \?= \?ddappsec.so@;extension = ddappsec.so@g' "
                    . escapeshellarg($iniFilePath)
                );

                if (is_truthy($options[OPT_ENABLE_APPSEC])) {
                    $enableAppsec = OPT_ENABLE_APPSEC;
                    print_error_and_exit(
                        "Option --{$enableAppsec} was provided, but it is not supported on this PHP build or version.\n"
                    );
                }
            }
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
function find_all_ini_files(array $phpProperties): array
{
    $iniFilePaths = [];

    $addIniFiles = function (string $path) use (&$iniFilePaths) {
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

    if ($phpProperties[INI_MAIN] && is_file($phpProperties[INI_MAIN])) {
        $iniFilePaths = [$phpProperties[INI_MAIN]];
    }

    return $iniFilePaths;
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
function find_main_ini_files(array $phpProperties): array
{
    $iniFilePaths = [];
    if ($phpProperties[INI_SCANDIR]) {
        $iniFileName = '98-ddtrace.ini';
        // Search for pre-existing files with extension = ddtrace.so to avoid conflicts
        // See issue https://github.com/DataDog/dd-trace-php/issues/1833
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
    return $iniFilePaths;
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
            $phpProperties[EXTENSION_DIR] . '/ddappsec.so',
        ];

        if (isset($phpProperties[INI_SCANDIR])) {
            $iniFileName = '98-ddtrace.ini';
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
    return execute_or_exit(
        "Cannot detect host architecture (uname -m)",
        "uname -m"
    );
}

/**
 * @return array|false
 */
function parse_cli_arguments(array $argv = null)
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

    $normalizedOptions[OPT_INSTALL_DIR] = isset($options[OPT_INSTALL_DIR])
        ? rtrim($options[OPT_INSTALL_DIR], '/')
        : '/opt/datadog';
    $normalizedOptions[OPT_INSTALL_DIR] = $normalizedOptions[OPT_INSTALL_DIR] . '/dd-library';

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
    $properties = [INI_MAIN, INI_SCANDIR, EXTENSION_DIR, THREAD_SAFETY, PHP_API, IS_DEBUG];
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

    $pathsFound = [];
    exec(
        "find -L $escapedSearchLocations -type f \( $escapedCommandNamesForFind \) 2>/dev/null",
        $pathsFound
    );

    foreach ($pathsFound as $path) {
        $resolved = realpath($path);
        if (in_array($resolved, array_values($resolvedPaths))) {
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
    $path = exec("command -v " . escapeshellarg($command));
    if (empty($path)) {
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
        // The extension setting is not unique, so make sure we check that the
        // right extension setting is available.
        $settingRegex = '/' . str_replace('.', '\.', $setting['name']) . '\s?=\s?';
        if ($setting['name'] === 'extension' || $setting['name'] == 'zend_extension') {
            $settingRegex .= str_replace('.', '\.', $setting['default']);
        }
        $settingRegex .= '/';

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

    if ($formattedMissingProperties !== '') {
        if (false === file_put_contents($iniFilePath, $iniFileContent . $formattedMissingProperties)) {
            print_error_and_exit("Cannot add additional settings to the INI file $iniFilePath");
        }
    }
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
 * @param string $appsecHelperPath
 * @param string $appsecRulesPath
 * @return array
 */
function get_ini_settings($requestInitHookPath, $appsecHelperPath, $appsecRulesPath)
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
            'name' => 'extension',
            'default' => 'datadog-profiling.so',
            'commented' => true,
            'description' => 'Enables the profiling module',
        ],
        [
            'name' => 'extension',
            'default' => 'ddappsec.so',
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
            'name' => 'datadog.profiling.experimental_allocation_enabled',
            'default' => '1',
            'commented' => true,
            'description' => 'Enable the allocation profile type.',
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
            'name' => 'datadog.appsec.helper_launch',
            'default' => 'On',
            'commented' => true,
            'description' => [
                'The dd-appsec extension communicates with a helper process via UNIX sockets.',
                'This setting determines whether the extension should try to launch the daemon',
                'in case it cannot obtain a connection.',
                'If this is disabled, the helper should be launched through some other method.',
                'The extension expects the helper to run under the same user as the process',
                'where PHP is running, and will verify it.',
            ],
        ],
        [
            'name' => 'datadog.appsec.helper_path',
            'default' => $appsecHelperPath,
            'commented' => false,
            'description' => [
                'If ddappsec.helper_launch is enabled, this setting determines which binary',
                'the extension should try to execute.',
                'Only relevant if ddappsec.helper_launch is enabled.',
                'This ini setting is configured by the installer',
            ],
        ],
        [
            'name' => 'datadog.appsec.helper_extra_args',
            'default' => '',
            'commented' => true,
            'description' => [
                'Additional arguments that should be used when attempting to launch the helper',
                'process. The extension always passes \'--lock_path - --socket_path fd:<int>\'',
                'The arguments should be space separated. Both single and double quotes can',
                'be used should an argument contain spaces. The backslash (\) can be used to',
                'escape spaces, quotes, and the backslash itself.',
                'Only relevant if ddappsec.helper_launch is enabled',
            ],
        ],
        [
            'name' => 'datadog.appsec.rules',
            'default' => $appsecRulesPath,
            'commented' => true,
            'description' => [
                'The path to the rules json file. The helper process must be able to read the',
                'file. This ini setting is configured by the installer',
            ],
        ],
        [
            'name' => 'datadog.appsec.helper_runtime_path',
            'default' => '/tmp/',
            'commented' => true,
            'description' => [
                'The location to the UNIX socket that extension uses to communicate with the',
                'helper and the lock file that the extension processes will use to',
                'synchronize the launching of the helper.',
                'Only relevant if datadog.appsec.helper_launch is enabled',
            ],
        ],
        [
            'name' => 'datadog.appsec.helper_log_file',
            'default' => '/dev/null',
            'commented' => true,
            'description' => [
                'The location of the log file of the helper. This default to /dev/null (the log',
                'messages will be discarded). This file is opened by the extension just before',
                'launching the daemon and the file descriptor is passed to the helper as its',
                'stderr, to which it will write its messages; this setting is therefore only',
                'relevant if ddappsec.helper_launch is enabled',
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
    return ['5.4', '5.5', '5.6', '7.0', '7.1', '7.2', '7.3', '7.4', '8.0', '8.1', '8.2'];
}

main();
