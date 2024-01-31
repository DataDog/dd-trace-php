<?php

namespace DDTrace\Integrations\Symfony;

use ReflectionClass;
use ReflectionAttribute;
use ReflectionException;

class PathExtractor
{
    protected $defaultRouteIndex = 0;
    protected $reader;
    protected $routeAnnotationClass = \Symfony\Component\Routing\Annotation\Route::class;

    public function __construct()
    {
        $annotationReaderClass = '\Doctrine\Common\Annotations\AnnotationReader';
        $docParserClass = '\Doctrine\Common\Annotations\DocParser';
        if (class_exists($annotationReaderClass) && class_exists($docParserClass)) {
            $this->reader = new $annotationReaderClass(new $docParserClass());
        }

        if (!class_exists('\Symfony\Component\Routing\Annotation\Route') &&
            class_exists('\Sensio\Bundle\FrameworkExtraBundle\Configuration\Route')
        ) {
            $this->routeAnnotationClass = Sensio\Bundle\FrameworkExtraBundle\Configuration\Route::class;
        }
    }

    public function extract($classMethod, $routeName, $locale)
    {
        if (!$routeName) {
            return;
        }

        $className = strtok($classMethod, "::");
        $methodName = strtok("::");
        $invoke = false;

        try {
            if ($methodName === false) {
                $invoke = true;
                $methodName = '__invoke';
            }
            $method = new \ReflectionMethod($className, $methodName);
            $class = $method->getDeclaringClass();

            if ($invoke) {
                $globals = $this->resetGlobals();
            } else {
                $globals = $this->getGlobals($class);
            }
        } catch (ReflectionException $e) {
            return;
        }

        $annotationsTarget = $invoke ? $class: $method;

        foreach ($this->getAnnotations($annotationsTarget) as $annot) {
            $path = $this->getPath($annot, $globals, $className, $methodName, $routeName, $locale);
            if ($path !== null) {
                return $path;
            }
        }

        return;
    }

    private function getAnnotations($reflection)
    {
        if (method_exists($reflection, 'getAttributes')) {
            foreach ($reflection->getAttributes($this->routeAnnotationClass, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                yield $attribute->newInstance();
            }
        }

        if (!$this->reader) {
            return;
        }

        $annotations = $reflection instanceof \ReflectionClass
            ? $this->reader->getClassAnnotations($reflection)
            : $this->reader->getMethodAnnotations($reflection);

        foreach ($annotations as $annotation) {
            if ($annotation instanceof $this->routeAnnotationClass) {
                yield $annotation;
            }
        }
    }

    protected function getPath($annot, array $globals, $className, $methodName, $routeName, $receivedLocale)
    {
        $name = $annot->getName() ? $annot->getName(): $this->getDefaultRouteName($className, $methodName);
        $name = $globals['name'].$name;

        if ($routeName !== $name) {
            return;
        }

        $path = \method_exists($annot, 'getLocalizedPaths') && $annot->getLocalizedPaths() ?
            $annot->getLocalizedPaths():
            $annot->getPath();
        $prefix = $globals['localized_paths'] ? $globals['localized_paths']: $globals['path'];

        if (\is_array($path)) {
            if (!\is_array($prefix)) {
                if (isset($path[$receivedLocale])) {
                    return $prefix.$path[$receivedLocale];
                }
                return $prefix.reset($path);
            } elseif (array_diff_key($prefix, $path)) {
                return;
            } else {
                if (isset($prefix[$receivedLocale]) && isset($path[$receivedLocale])) {
                    return $prefix[$receivedLocale].$path[$receivedLocale];
                } elseif (isset($prefix[$receivedLocale])) {
                    return $prefix[$receivedLocale].reset($path);
                } elseif (isset($path[$receivedLocale])) {
                    return reset($prefix).$path[$receivedLocale];
                }
                return reset($prefix).reset($path);
            }
        } elseif (\is_array($prefix)) {
            if (isset($prefix[$receivedLocale])) {
                return $prefix[$receivedLocale].$path;
            }
            return reset($prefix).$path;
        }

        $path = $prefix.$path;
        return empty($path) ? '/': $path;
    }

    private function resetGlobals(): array
    {
        return [
            'path' => null,
            'localized_paths' => [],
            'name' => '',
        ];
    }

    protected function getGlobals(ReflectionClass $class)
    {
        $globals = $this->resetGlobals();

        $annot = null;
        if (
            method_exists($class, 'getAttributes') &&
            $attribute = $class->getAttributes($this->routeAnnotationClass, ReflectionAttribute::IS_INSTANCEOF)[0] ?? null
        ) {
            $annot = $attribute->newInstance();
        }
        if (!$annot && $this->reader) {
            $annot = $this->reader->getClassAnnotation($class, $this->routeAnnotationClass);
        }

        if ($annot) {
            if (null !== $annot->getName()) {
                $globals['name'] = $annot->getName();
            }
            if (null !== $annot->getPath()) {
                $globals['path'] = $annot->getPath();
            }

            //Old versions of Symfony dont have this
            if (\method_exists($annot, 'getLocalizedPaths')) {
                $globals['localized_paths'] = $annot->getLocalizedPaths();
            }
        }

        return $globals;
    }

    protected function getDefaultRouteName($className, $methodName)
    {
        $name = str_replace('\\', '_', $className).'_'.$methodName;
        $name = \function_exists('mb_strtolower') && preg_match('//u', $name) ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        if ($this->defaultRouteIndex > 0) {
            $name .= '_'.$this->defaultRouteIndex;
        }
        ++$this->defaultRouteIndex;

        $name = preg_replace('/(bundle|controller)_/', '_', $name);

        if (str_ends_with($methodName, 'Action') || str_ends_with($methodName, '_action')) {
            $name = preg_replace('/action(_\d+)?$/', '\\1', $name);
        }

        return str_replace('__', '_', $name);
    }
}
