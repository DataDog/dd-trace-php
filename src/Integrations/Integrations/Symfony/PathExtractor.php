<?php

namespace DDTrace\Integrations\Symfony;

use Symfony\Component\Routing\Annotation\Route as RouteAnnotation;

class PathExtractor
{
    protected $routeAnnotationClass = RouteAnnotation::class;
    protected $defaultRouteIndex = 0;


    /**
     * Sets the annotation class to read route properties from.
     */
    public function setRouteAnnotationClass(string $class)
    {
        $this->routeAnnotationClass = $class;
    }

    public function extract($classMethod, $routeName, $locale)
    {
        $className = $classMethod; //It may not come with method when __invoke
        $methodName = null;
        if (str_contains($classMethod, "::")) {
            $exploded = explode("::", $classMethod);
            $className = $exploded[0];
            $methodName = $exploded[1];
        }

        if (!class_exists($className)) {
            return;
        }
        $class = new \ReflectionClass($className);
        if ($class->isAbstract()) {
            return;
        }

        if ($methodName != null) {
            $globals = $this->getGlobals($class);
            try {
                $method = $class->getMethod($methodName);
            } catch (\ReflectionException $e) {
                return;
            }

            $paths = [];
            foreach ($this->getAnnotations($method) as $annot) {
                $path = $this->getPath($annot, $globals, $class, $method, $routeName);
                if ($path !== null) {
                    $paths = array_merge($paths, $path);
                }
            }
            if (!empty($paths) && is_array($paths)) {
                if (isset($paths[$locale])) {
                    return $paths[$locale];
                } else if (isset($paths[$this->defaultRouteIndex])) {
                    return $paths[$this->defaultRouteIndex];
                }
                return reset($paths);
            }
        }

        if ($class->hasMethod('__invoke')) {
            $globals = $this->resetGlobals();
            $paths = [];
            foreach ($this->getAnnotations($class) as $annot) {
                $path = $this->getPath($annot, $globals, $class, $class->getMethod('__invoke'), $routeName);
                if ($path !== null) {
                    $paths = array_merge($paths, $path);
                }
            }
            if (!empty($paths) && is_array($paths)) {
                if (isset($paths[$locale])) {
                    return $paths[$locale];
                } else if (isset($paths[$this->defaultRouteIndex])) {
                    return $paths[$this->defaultRouteIndex];
                }
                return reset($paths);
            }
        }

        return;
    }

    private function getAnnotations(object $reflection): iterable
    {
        foreach ($reflection->getAttributes($this->routeAnnotationClass, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            yield $attribute->newInstance();
        }
    }

    protected function getPath(object $annot, array $globals, \ReflectionClass $class, \ReflectionMethod $method, $routeName)
    {
        $name = $annot->getName() ?? $this->getDefaultRouteName($class, $method);
        $name = $globals['name'].$name;

        if (!empty($routeName) && $name !== $routeName) {
            return;
        }

        $path = $annot->getLocalizedPaths() ?: $annot->getPath();
        $prefix = $globals['localized_paths'] ?: $globals['path'];
        $paths = [];
        if (\is_array($path)) {
            if (!\is_array($prefix)) {
                foreach ($path as $locale => $localePath) {
                    $paths[$locale] = $prefix.$localePath;
                }
            } elseif (array_diff_key($prefix, $path)) {
                return;
            } else {
                foreach ($path as $locale => $localePath) {
                    if (!isset($prefix[$locale])) {
                        return;
                    }

                    $paths[$locale] = $prefix[$locale].$localePath;
                }
            }
        } elseif (\is_array($prefix)) {
            foreach ($prefix as $locale => $localePrefix) {
                $paths[$locale] = $localePrefix.$path;
            }
        } else {
            $path = $prefix.$path;
            $paths[] = empty($path) ? '/': $path;
        }

        return $paths;
    }

    private function resetGlobals(): array
    {
        return [
            'path' => null,
            'localized_paths' => [],
            'name' => '',
        ];
    }

    protected function getGlobals(\ReflectionClass $class)
    {
        $globals = $this->resetGlobals();

        $annot = null;
        if ($attribute = $class->getAttributes($this->routeAnnotationClass, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null) {
            $annot = $attribute->newInstance();
        }

        if ($annot) {
            if (null !== $annot->getPath()) {
                $globals['path'] = $annot->getPath();
            }

            $globals['localized_paths'] = $annot->getLocalizedPaths();
        }

        return $globals;
    }

    protected function getDefaultRouteName(\ReflectionClass $class, \ReflectionMethod $method)
    {
        $name = str_replace('\\', '_', $class->name).'_'.$method->name;
        $name = \function_exists('mb_strtolower') && preg_match('//u', $name) ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        if ($this->defaultRouteIndex > 0) {
            $name .= '_'.$this->defaultRouteIndex;
        }
        ++$this->defaultRouteIndex;

        return $name;
    }

}
