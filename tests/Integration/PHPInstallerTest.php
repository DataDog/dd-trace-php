<?php

namespace DDTrace\Tests\Integration;

use DDTrace\Tests\Common\BaseTestCase;

final class PHPInstallerTest extends BaseTestCase
{
    public static function ddSetUpBeforeClass()
    {
        require_once __DIR__ . '/../../datadog-setup.php';

        // Setting up a dummy remi repo
        $rootPath = self::getTmpRootPath();
        exec("rm -rf {$rootPath}");
        exec("mkdir -p {$rootPath}/opt/remi/php74/root/usr/sbin");

        // should not be included: not a recognized pattern
        exec("cd {$rootPath}/opt/remi/php74/root/usr/sbin; touch some_bin; chmod a+x some_bin");
        exec("cd {$rootPath}/opt/remi/php74/root/usr/sbin; touch php773; chmod a+x php773");
        exec("cd {$rootPath}/opt/remi/php74/root/usr/sbin; touch php_7; chmod a+x php_7");
        exec("cd {$rootPath}/opt/remi/php74/root/usr/sbin; touch php-fpm77.3; chmod a+x php-fpm77.3");

        // should be included
        exec("cd {$rootPath}/opt/remi/php74/root/usr/sbin; touch php; chmod a+x php");
        exec("cd {$rootPath}/opt/remi/php74/root/usr/sbin; touch php7; chmod a+x php7");
        exec("cd {$rootPath}/opt/remi/php74/root/usr/sbin; touch php74; chmod a+x php74");
        exec("cd {$rootPath}/opt/remi/php74/root/usr/sbin; touch php7.4; chmod a+x php7.4");
        exec("cd {$rootPath}/opt/remi/php74/root/usr/sbin; touch php-fpm; chmod a+x php-fpm");
        exec("cd {$rootPath}/opt/remi/php74/root/usr/sbin; touch php56-fpm; chmod a+x php56-fpm");
        exec("cd {$rootPath}/opt/remi/php74/root/usr/sbin; touch php5.6-fpm; chmod a+x php5.6-fpm");
        exec("cd {$rootPath}/opt/remi/php74/root/usr/sbin; touch php-fpm56; chmod a+x php-fpm56");
        exec("cd {$rootPath}/opt/remi/php74/root/usr/sbin; touch php-fpm5.6; chmod a+x php-fpm5.6");
        // php72 should be included as a symlink to will_be_linked
        exec("cd {$rootPath}/opt/remi/php74/root/usr/sbin; touch will_be_linked; chmod a+x will_be_linked");
        // phpcs:disable Generic.Files.LineLength.TooLong
        exec("cd {$rootPath}/opt/remi/php74/root/usr/sbin; ln -s {$rootPath}/opt/remi/php74/root/usr/sbin/will_be_linked {$rootPath}/opt/remi/php74/root/usr/sbin/php72");
        // phpcs:enable Generic.Files.LineLength.TooLong
    }

    public function testIsTruthy()
    {
        $this->assertTrue(\is_truthy('1'));
        $this->assertTrue(\is_truthy('true'));
        $this->assertTrue(\is_truthy('TrUe'));
        $this->assertTrue(\is_truthy('yes'));
        $this->assertTrue(\is_truthy('yEs'));
        $this->assertTrue(\is_truthy('enabled'));
        $this->assertTrue(\is_truthy('EnAbLeD'));

        $this->assertFalse(\is_truthy(null));
        $this->assertFalse(\is_truthy('0'));
        $this->assertFalse(\is_truthy('false'));
        $this->assertFalse(\is_truthy('fAlSe'));
        $this->assertFalse(\is_truthy('no'));
        $this->assertFalse(\is_truthy('nO'));
        $this->assertFalse(\is_truthy('disabled'));
        $this->assertFalse(\is_truthy('dIsAbLeD'));
    }

    public function testBuildCommandNamesMatrix()
    {
        $names = \build_known_command_names_matrix();

        $this->assertContains('php', $names);
        $this->assertContains('php-fpm', $names);

        // We test one version all combinations
        $this->assertContains('php8', $names);
        $this->assertContains('php80', $names);
        $this->assertContains('php8.0', $names);
        $this->assertContains('php8-fpm', $names);
        $this->assertContains('php80-fpm', $names);
        $this->assertContains('php8.0-fpm', $names);
        $this->assertContains('php-fpm8', $names);
        $this->assertContains('php-fpm80', $names);
        $this->assertContains('php-fpm8.0', $names);

        // We test only that all versions are present with at least one combination
        $this->assertContains('php54', $names);
        $this->assertContains('php55', $names);
        $this->assertContains('php56', $names);
        $this->assertContains('php70', $names);
        $this->assertContains('php71', $names);
        $this->assertContains('php72', $names);
        $this->assertContains('php73', $names);
        $this->assertContains('php74', $names);
        $this->assertContains('php80', $names);
        $this->assertContains('php81', $names);
    }

    /**
     * This test assumes that is a `php` binary available in the path
     */
    public function testSearchPhpBinaries()
    {
        $found = \search_php_binaries(sys_get_temp_dir() . '/dd-php-setup-tests');
        $this->assertStringContains('/', $found['php']["path"]);

        $shouldBeFound = [
            'php',
            'php7',
            'php74',
            'php7.4',
            'php-fpm',
            'php56-fpm',
            'php5.6-fpm',
            'php-fpm56',
            'php-fpm5.6',
            'php72',
        ];

        $shouldNotBeFound = [
            'some_bin',
            'php773',
            'php_7',
            'php-fpm77.3',
        ];

        $rootPath = self::getTmpRootPath() . "/opt/remi/php74/root/usr/sbin";
        foreach ($shouldBeFound as $binary) {
            $this->assertArrayHasKey("{$rootPath}/{$binary}", $found);
            $this->assertSame(realpath("{$rootPath}/{$binary}"), $found["{$rootPath}/{$binary}"]["path"]);
        }
        foreach ($shouldNotBeFound as $binary) {
            $this->assertTrue(empty($found["{$rootPath}/{$binary}"]));
        }
    }

    public function testIniValues()
    {
        $values = \ini_values(\PHP_BINARY);

        foreach ($values as $value) {
            /* we drop (none) values */
            $this->assertNotEquals($value, "(none)");
        }

        /* we rely on these being some sensible value */
        $this->assertNotEmpty($values[EXTENSION_DIR]);
        $this->assertNotEmpty($values[THREAD_SAFETY]);
        $this->assertNotEmpty($values[PHP_API]);
        $this->assertNotEmpty($values[IS_DEBUG]);
    }

    public function cliArguments()
    {
        return [
            [
                'datadog-setup.php config set DD_PROFILING_ENABLED=1 -ddatadog.profiling.log_level=trace',
                [
                    'cmd' => 'config set',
                    'opts' => [
                        'd' => [
                            'datadog.profiling.enabled=1',
                            'datadog.profiling.log_level=trace'
                        ]
                    ]
                ]
            ], [
                'datadog-setup.php config set -d datadog.profiling.enabled=1 -ddatadog.profiling.log_level=trace',
                [
                    'cmd' => 'config set',
                    'opts' => [
                        'd' => [
                            'datadog.profiling.enabled=1',
                            'datadog.profiling.log_level=trace'
                        ]
                    ]
                ]
            ], [
                'datadog-setup.php config get -d datadog.profiling.enabled -dfoobar',
                [
                    'cmd' => 'config get',
                    'opts' => [
                        'd' => [
                            'datadog.profiling.enabled',
                            'foobar',
                        ]
                    ]
                ]
            ], [
                'datadog-setup.php --php-bin php --php-bin php-cli --install-dir /opt/ --enable-profiling',
                [
                    'cmd' => 'install',
                    'opts' => [
                        'php-bin' => [
                            'php',
                            'php-cli',
                        ],
                        'install-dir' => '/opt/',
                        'enable-profiling' => false,
                    ]
                ]
            ], [
                'datadog-setup.php --php-bin=php --php-bin=php-cli --install-dir /opt/ --enable-profiling',
                [
                    'cmd' => 'install',
                    'opts' => [
                        'php-bin' => [
                            'php',
                            'php-cli',
                        ],
                        'install-dir' => '/opt/',
                        'enable-profiling' => false,
                    ]
                ]
            ], [
                'datadog-setup.php --help',
                [
                    'cmd' => 'install',
                    'opts' => [
                        'help' => false
                    ]
                ]
            ], [
                'datadog-setup.php -h --enable-profiling',
                [
                    'cmd' => 'install',
                    'opts' => [
                        'h' => false,
                        'enable-profiling' => false,
                    ]
                ]
            ]
        ];
    }

    /**
     * @dataProvider cliArguments
     */
    public function testCliArgumentParsing(string $cli, array $expect)
    {
        $command = explode(' ', $cli);
        $opts = parse_cli_arguments($command);
        $this->assertSame(
            $expect,
            $opts
        );
    }

    public function testFailingCliArgumentParsing()
    {
        $this->expectOutputString("Parse error at token 'php6'" . PHP_EOL);
        $command = explode(' ', 'datadog-setup.php config get --php-bin=all php6 -ddatadog.profiling.enabled');
        $opts = parse_cli_arguments($command);
        $this->assertFalse(
            $opts
        );
    }

    public function iniFileContents()
    {
        return [
            [
                ';foo.bar=On;comment',
                ';foo.bar=On;comment',
                false,
                0
            ], [
                ';foo.bar=On;comment',
                'foo.bar = Off;comment',
                true,
                1
            ], [
                'foo.bar=On;comment

foo.bar=baz',
                'foo.bar = Off;comment

foo.bar = Off',
                false,
                2
            ]
        ];
    }

    /**
     * @dataProvider iniFileContents
     */
    public function testUpdateIniSetting(string $before, string $after, bool $promoteComment, int $expCount)
    {
        // Create a temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tempFile, $before);

        $count = update_ini_setting(['foo.bar', 'Off'], $tempFile, $promoteComment);

        $output = file_get_contents($tempFile);
        $this->assertSame($after, $output);
        $this->assertSame($count, $expCount);

        unlink($tempFile);
    }

    private static function getTmpRootPath()
    {
        return sys_get_temp_dir() . '/dd-php-setup-tests';
    }
}
