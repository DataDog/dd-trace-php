<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\Module\ModuleList;

use Magento\Framework\Module\Declaration\Converter\Dom;
use Magento\Framework\Xml\Parser;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Filesystem\DriverInterface;

/**
 * Loader of module list information from the filesystem
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Loader
{
    /**
     * Converter of XML-files to associative arrays (specific to module.xml file format)
     *
     * @var Dom
     */
    private $converter;

    /**
     * Parser
     *
     * @var \Magento\Framework\Xml\Parser
     */
    private $parser;

    /**
     * Module registry
     *
     * @var ComponentRegistrarInterface
     */
    private $moduleRegistry;

    /**
     * Filesystem driver to allow reading of module.xml files which live outside of app/code
     *
     * @var DriverInterface
     */
    private $filesystemDriver;

    /**
     * Constructor
     *
     * @param Dom $converter
     * @param Parser $parser
     * @param ComponentRegistrarInterface $moduleRegistry
     * @param DriverInterface $filesystemDriver
     */
    public function __construct(
        Dom $converter,
        Parser $parser,
        ComponentRegistrarInterface $moduleRegistry,
        DriverInterface $filesystemDriver
    ) {
        $this->converter = $converter;
        $this->parser = $parser;
        $this->parser->initErrorHandler();
        $this->moduleRegistry = $moduleRegistry;
        $this->filesystemDriver = $filesystemDriver;
    }

    /**
     * Loads the full module list information. Excludes modules specified in $exclude.
     *
     * @param array $exclude
     * @throws \Magento\Framework\Exception\LocalizedException
     * @return array
     */
    public function load(array $exclude = [])
    {
        $result = [];
        $excludeSet = array_flip($exclude);

        foreach ($this->getModuleConfigs() as list($file, $contents)) {
            try {
                $this->parser->loadXML($contents);
            } catch (\Magento\Framework\Exception\LocalizedException $e) {
                throw new \Magento\Framework\Exception\LocalizedException(
                    new \Magento\Framework\Phrase(
                        'Invalid Document: %1%2 Error: %3',
                        [$file, PHP_EOL, $e->getMessage()]
                    ),
                    $e
                );
            }

            $data = $this->converter->convert($this->parser->getDom());
            $name = key($data);
            if (!isset($excludeSet[$name])) {
                $result[$name] = $data[$name];
            }
        }
        return $this->sortBySequence($result);
    }

    /**
     * Returns module config data and a path to the module.xml file.
     *
     * Example of data returned by generator:
     * <code>
     *     [ 'vendor/module/etc/module.xml', '<xml>contents</xml>' ]
     * </code>
     *
     * @return \Traversable
     *
     * @author Josh Di Fabio <joshdifabio@gmail.com>
     */
    private function getModuleConfigs()
    {
        $modulePaths = $this->moduleRegistry->getPaths(ComponentRegistrar::MODULE);
        foreach ($modulePaths as $modulePath) {
            $filePath = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, "$modulePath/etc/module.xml");
            yield [$filePath, $this->filesystemDriver->fileGetContents($filePath)];
        }
    }

    /**
     * Sort the list of modules using "sequence" key in meta-information
     *
     * @param array $origList
     * @return array
     * @throws \Exception
     */
    private function sortBySequence(array $origList): array
    {
        ksort($origList);
        $modules = $this->prearrangeModules($origList);

        $expanded = [];
        foreach (array_keys($modules) as $moduleName) {
            $sequence = $this->expandSequence($origList, $moduleName);
            asort($sequence);

            $expanded[] = [
                'name' => $moduleName,
                'sequence_set' => array_flip($sequence),
            ];
        }

        // Use "bubble sorting" because usort does not check each pair of elements and in this case it is important
        $total = count($expanded);
        for ($i = 0; $i < $total - 1; $i++) {
            for ($j = $i; $j < $total; $j++) {
                if (isset($expanded[$i]['sequence_set'][$expanded[$j]['name']])) {
                    $temp = $expanded[$i];
                    $expanded[$i] = $expanded[$j];
                    $expanded[$j] = $temp;
                }
            }
        }

        $result = [];
        foreach ($expanded as $pair) {
            $result[$pair['name']] = $origList[$pair['name']];
        }

        return $result;
    }

    /**
     * Prearrange all modules by putting those from Magento before the others
     *
     * @param array $modules
     * @return array
     */
    private function prearrangeModules(array $modules): array
    {
        $breakdown = ['magento' => [], 'others' => []];

        foreach ($modules as $moduleName => $moduleDetails) {
            if (strpos($moduleName, 'Magento_') !== false) {
                $breakdown['magento'][$moduleName] = $moduleDetails;
            } else {
                $breakdown['others'][$moduleName] = $moduleDetails;
            }
        }

        return array_merge($breakdown['magento'], $breakdown['others']);
    }

    /**
     * Accumulate information about all transitive "sequence" references
     *
     * @param array $list
     * @param string $name
     * @param array $accumulated
     * @return array
     * @throws \Exception
     */
    private function expandSequence($list, $name, $accumulated = [])
    {
        $accumulated[$name] = true;
        $result = $list[$name]['sequence'];
        $allResults = [];
        foreach ($result as $relatedName) {
            if (isset($accumulated[$relatedName])) {
                throw new \LogicException("Circular sequence reference from '{$name}' to '{$relatedName}'.");
            }
            if (!isset($list[$relatedName])) {
                continue;
            }
            $allResults[] = $this->expandSequence($list, $relatedName, $accumulated);
        }
        $allResults[] = $result;
        return array_unique(array_merge(...$allResults));
    }
}
