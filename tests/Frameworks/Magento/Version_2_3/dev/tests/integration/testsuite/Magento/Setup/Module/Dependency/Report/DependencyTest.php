<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Setup\Module\Dependency\Report;

use Magento\Setup\Module\Dependency\ServiceLocator;

class DependencyTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var string
     */
    protected $fixtureDir;

    /**
     * @var string
     */
    protected $sourceFilename;

    /**
     * @var BuilderInterface
     */
    protected $builder;

    protected function setUp(): void
    {
        $this->fixtureDir = realpath(__DIR__ . '/../_files') . '/';
        $this->sourceFilename = $this->fixtureDir . 'dependencies.csv';

        $this->builder = ServiceLocator::getDependenciesReportBuilder();
    }

    public function testBuild()
    {
        $this->builder->build(
            [
                'parse' => [
                    'files_for_parse' => [$this->fixtureDir . 'composer1.json', $this->fixtureDir . 'composer2.json'],
                ],
                'write' => ['report_filename' => $this->sourceFilename],
            ]
        );

        $this->assertFileEquals($this->fixtureDir . 'expected/dependencies.csv', $this->sourceFilename);
    }

    public function testBuildWithoutDependencies()
    {
        $this->builder->build(
            [
                'parse' => ['files_for_parse' => [$this->fixtureDir . 'composer3.json']],
                'write' => ['report_filename' => $this->sourceFilename],
            ]
        );

        $this->assertFileEquals($this->fixtureDir . 'expected/without-dependencies.csv', $this->sourceFilename);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->sourceFilename)) {
            unlink($this->sourceFilename);
        }
    }
}
