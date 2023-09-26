<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\App\Router;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\State;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Serialize\Serializer\Serialize;
use Magento\Framework\Module\Dir\Reader as ModuleReader;

/**
 * Class to retrieve action class.
 */
class ActionList
{
    /**
     * Not allowed string in route's action path to avoid disclosing admin url
     */
    const NOT_ALLOWED_IN_NAMESPACE_PATH = 'adminhtml';

    /**
     * List of application actions
     *
     * @var array
     */
    protected $actions;

    /**
     * @var array
     */
    protected $reservedWords = [
        'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch', 'class', 'clone', 'const',
        'continue', 'declare', 'default', 'die', 'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare',
        'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'eval', 'exit', 'extends', 'final',
        'for', 'foreach', 'function', 'global', 'goto', 'if', 'implements', 'include', 'instanceof',
        'insteadof','interface', 'isset', 'list', 'namespace', 'new', 'or', 'print', 'private', 'protected',
        'public', 'require', 'return', 'static', 'switch', 'throw', 'trait', 'try', 'unset', 'use', 'var',
        'while', 'xor', 'void',
    ];

    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var string
     */
    private $actionInterface;

    /**
     * ActionList constructor
     *
     * @param \Magento\Framework\Config\CacheInterface $cache
     * @param ModuleReader $moduleReader
     * @param string $actionInterface
     * @param string $cacheKey
     * @param array $reservedWords
     * @param SerializerInterface|null $serializer
     */
    public function __construct(
        \Magento\Framework\Config\CacheInterface $cache,
        ModuleReader $moduleReader,
        $actionInterface = \Magento\Framework\App\ActionInterface::class,
        $cacheKey = 'app_action_list',
        $reservedWords = [],
        SerializerInterface $serializer = null
    ) {
        $this->reservedWords = array_merge($reservedWords, $this->reservedWords);
        $this->actionInterface = $actionInterface;
        $this->serializer = $serializer ?: \Magento\Framework\App\ObjectManager::getInstance()->get(Serialize::class);
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $state = $objectManager->get(State::class);

        if ($state->getMode() === State::MODE_PRODUCTION) {
            $directoryList = $objectManager->get(DirectoryList::class);
            $file = $directoryList->getPath(DirectoryList::GENERATED_METADATA) . '/' . $cacheKey . '.' . 'php';

            if (file_exists($file)) {
                $this->actions = (include $file) ?? $moduleReader->getActionFiles();
            } else {
                $this->actions = $moduleReader->getActionFiles();
            }
        } else {
            $data = $cache->load($cacheKey);
            if (!$data) {
                $this->actions = $moduleReader->getActionFiles();
                $cache->save($this->serializer->serialize($this->actions), $cacheKey);
            } else {
                $this->actions = $this->serializer->unserialize($data);
            }
        }
    }

    /**
     * Retrieve action class
     *
     * @param string $module
     * @param string $area
     * @param string $namespace
     * @param string $action
     * @return null|string
     */
    public function get($module, $area, $namespace, $action)
    {
        if ($area) {
            $area = '\\' . $area;
        }
        $namespace = strtolower($namespace);
        if (strpos($namespace, self::NOT_ALLOWED_IN_NAMESPACE_PATH) !== false) {
            return null;
        }
        if (in_array(strtolower($action), $this->reservedWords)) {
            $action .= 'action';
        }
        $fullPath = str_replace(
            '_',
            '\\',
            strtolower(
                $module . '\\controller' . $area . '\\' . $namespace . '\\' . $action
            )
        );
        if (isset($this->actions[$fullPath])) {
            return is_subclass_of($this->actions[$fullPath], $this->actionInterface) ? $this->actions[$fullPath] : null;
        }
        return null;
    }
}
