<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Setup\Module\Dependency;

use Magento\Framework\Data\Graph;

/**
 * Build circular dependencies by modules map
 */
class Circular
{
    /**
     * Map where the key is the vertex and the value are the adjacent vertices(dependencies) of this vertex
     *
     * @var array
     */
    protected $dependencies = [];

    /**
     * Modules circular dependencies map
     *
     * @var array
     */
    protected $circularDependencies = [];

    /**
     * Graph object
     *
     * @var \Magento\Framework\Data\Graph
     */
    protected $graph;

    /**
     * Build modules dependencies
     *
     * @param array $dependencies Key is the vertex and the value are the adjacent vertices(dependencies) of this vertex
     * @return array
     */
    public function buildCircularDependencies($dependencies)
    {
        $this->init($dependencies);

        foreach (array_keys($this->dependencies) as $vertex) {
            $this->expandDependencies($vertex);
        }

        $circulars = $this->graph->findCycle(null, false);
        foreach ($circulars as $circular) {
            array_shift($circular);
            $this->buildCircular($circular);
        }

        return $this->divideByModules($this->circularDependencies);
    }

    /**
     * Init data before building
     *
     * @param array $dependencies
     * @return void
     */
    protected function init($dependencies)
    {
        $this->dependencies = $dependencies;
        $this->circularDependencies = [];
        $this->graph = new Graph(array_keys($this->dependencies), []);
    }

    /**
     * Expand modules dependencies from chain
     *
     * @param string $vertex
     * @param array $path nesting path
     * @return void
     */
    protected function expandDependencies($vertex, $path = [])
    {
        if (!$this->dependencies[$vertex]) {
            return;
        }

        $path[] = $vertex;
        foreach ($this->dependencies[$vertex] as $dependency) {
            if (!isset($this->dependencies[$dependency])) {
                // dependency vertex is not described in basic definition
                continue;
            }
            $relations = $this->graph->getRelations();
            if (isset($relations[$vertex][$dependency])) {
                continue;
            }
            $this->graph->addRelation($vertex, $dependency);

            $searchResult = array_search($dependency, $path);

            if (false !== $searchResult) {
                $this->buildCircular(array_slice($path, $searchResult));
                break;
            } else {
                $this->expandDependencies($dependency, $path);
            }
        }
    }

    /**
     * Build all circular dependencies based on chain
     *
     * @param array $modules
     * @return void
     */
    protected function buildCircular($modules)
    {
        $path = '/' . implode('/', $modules);
        if (isset($this->circularDependencies[$path])) {
            return;
        }
        $this->circularDependencies[$path] = $modules;
        $modules[] = array_shift($modules);
        $this->buildCircular($modules);
    }

    /**
     * Divide dependencies by modules
     *
     * @param array $circularDependencies
     * @return array
     */
    protected function divideByModules($circularDependencies)
    {
        $dependenciesByModule = [];
        foreach ($circularDependencies as $circularDependency) {
            $module = $circularDependency[0];
            $circularDependency[] = $module;
            $dependenciesByModule[$module][] = $circularDependency;
        }

        return $dependenciesByModule;
    }
}
