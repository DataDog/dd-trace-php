<?php

namespace DDTrace\Tests\Unit\Integrations;

use PHPUnit\Framework\TestCase;
use DDTrace\Integrations\Symfony\PathExtractor;
use Symfony\Component\Routing\Annotation\Route;

require __DIR__.'/PhpAnnotationClasses.php';

/**
* @requires PHP >= 8.0
*/
class PathExtractorTest extends TestCase
{
    public function scenarios()
    {
        return [
            ['/basic-path', 'DDTrace\Tests\Unit\Integrations\Annotations\PhpActionAnnotationsController::basicAction', 'basic action'],
            ['/missing-name', 'DDTrace\Tests\Unit\Integrations\Annotations\PhpActionAnnotationsController::missingName', 'ddtrace_tests_unit_integrations_annotations_phpactionannotations_missingname'],
            ['/', 'DDTrace\Tests\Unit\Integrations\Annotations\PhpActionAnnotationsController::nothingButName', 'only the name'],
            ['/dynamic-path/{argument}', 'DDTrace\Tests\Unit\Integrations\Annotations\PhpActionAnnotationsController::actionWithArguments', 'action with dynamic arguments'],
            ['/invokable', 'DDTrace\Tests\Unit\Integrations\Annotations\InvokableController', 'lol'],
            ['/invokable-en', 'DDTrace\Tests\Unit\Integrations\Annotations\InvokableLocalizedController', 'invokable localized'],
            ['/invokable-en', 'DDTrace\Tests\Unit\Integrations\Annotations\InvokableLocalizedController', 'invokable localized', 'en'],
            ['/invokable-nl', 'DDTrace\Tests\Unit\Integrations\Annotations\InvokableLocalizedController', 'invokable localized', 'nl'],
            ['/invokable-en', 'DDTrace\Tests\Unit\Integrations\Annotations\InvokableLocalizedController', 'invokable localized', 'es'],
            ['/localized-action-en', 'DDTrace\Tests\Unit\Integrations\Annotations\PhpActionAnnotationsController::localizedAction', 'localized method'],
            ['/localized-action-en', 'DDTrace\Tests\Unit\Integrations\Annotations\PhpActionAnnotationsController::localizedAction', 'localized method','en'],
            ['/localized-action-nl', 'DDTrace\Tests\Unit\Integrations\Annotations\PhpActionAnnotationsController::localizedAction', 'localized method','nl'],
            ['/localized-action-en', 'DDTrace\Tests\Unit\Integrations\Annotations\PhpActionAnnotationsController::localizedAction', 'localized method','es'],
            ['/hello/{name<\w+>}', 'DDTrace\Tests\Unit\Integrations\Annotations\PhpActionAnnotationsController::multipleRoutesAction', 'hello_without_default', 'en'],
            ['/hello/{name<\w+>?Symfony}', 'DDTrace\Tests\Unit\Integrations\Annotations\PhpActionAnnotationsController::multipleRoutesAction', 'hello_with_default', 'en'],
            ['/the/path', 'DDTrace\Tests\Unit\Integrations\Annotations\MethodActionControllers::post', 'post'],
            ['/the/path', 'DDTrace\Tests\Unit\Integrations\Annotations\MethodActionControllers::put', 'put'],
            ['/the/path', 'DDTrace\Tests\Unit\Integrations\Annotations\LocalizedMethodActionControllers::post', 'post'],
            ['/the/path', 'DDTrace\Tests\Unit\Integrations\Annotations\LocalizedMethodActionControllers::post', 'post','en'],
            ['/the/path', 'DDTrace\Tests\Unit\Integrations\Annotations\LocalizedMethodActionControllers::post', 'post', 'es'],
            ['/het/pad', 'DDTrace\Tests\Unit\Integrations\Annotations\LocalizedMethodActionControllers::post', 'post', 'nl'],
            ['/the/path', 'DDTrace\Tests\Unit\Integrations\Annotations\LocalizedMethodActionControllers::put', 'put'],
            ['/the/path', 'DDTrace\Tests\Unit\Integrations\Annotations\LocalizedMethodActionControllers::put', 'put', 'en'],
            ['/the/path', 'DDTrace\Tests\Unit\Integrations\Annotations\LocalizedMethodActionControllers::put', 'put', 'es'],
            ['/het/pad', 'DDTrace\Tests\Unit\Integrations\Annotations\LocalizedMethodActionControllers::put', 'put', 'nl'],
            ['/defaults/specific-locale', 'DDTrace\Tests\Unit\Integrations\Annotations\GlobalDefaultsClass::locale', 'specific_locale'],
            ['/defaults/specific-locale', 'DDTrace\Tests\Unit\Integrations\Annotations\GlobalDefaultsClass::locale', 'specific_locale', 'g_locale'],
            ['/defaults/specific-locale', 'DDTrace\Tests\Unit\Integrations\Annotations\GlobalDefaultsClass::locale', 'specific_locale', 's_locale'],
            ['/defaults/specific-format', 'DDTrace\Tests\Unit\Integrations\Annotations\GlobalDefaultsClass::format', 'specific_format'],
            ['/defaults/specific-format', 'DDTrace\Tests\Unit\Integrations\Annotations\GlobalDefaultsClass::format', 'specific_format', 'g_locale'],
            ['/prefix/path', 'DDTrace\Tests\Unit\Integrations\Annotations\PrefixedActionPathController::action', 'action'],
            ['/prefix/path', 'DDTrace\Tests\Unit\Integrations\Annotations\PrefixedActionLocalizedRouteController::action', 'action'],
            ['/prefix/path', 'DDTrace\Tests\Unit\Integrations\Annotations\PrefixedActionLocalizedRouteController::action', 'action', 'es'],
            ['/prefix/path', 'DDTrace\Tests\Unit\Integrations\Annotations\PrefixedActionLocalizedRouteController::action', 'action', 'en'],
            ['/prefix/pad', 'DDTrace\Tests\Unit\Integrations\Annotations\PrefixedActionLocalizedRouteController::action', 'action', 'nl'],
            ['/en/action', 'DDTrace\Tests\Unit\Integrations\Annotations\LocalizedPrefixLocalizedActionController::action', 'action'],
            ['/en/action', 'DDTrace\Tests\Unit\Integrations\Annotations\LocalizedPrefixLocalizedActionController::action', 'action', 'en'],
            ['/nl/actie', 'DDTrace\Tests\Unit\Integrations\Annotations\LocalizedPrefixLocalizedActionController::action', 'action', 'es'],
            ['/nl/actie', 'DDTrace\Tests\Unit\Integrations\Annotations\LocalizedPrefixLocalizedActionController::action', 'action', 'nl'],
            ['/1', 'DDTrace\Tests\Unit\Integrations\Annotations\BazClass', 'route1'],
            ['/2', 'DDTrace\Tests\Unit\Integrations\Annotations\BazClass', 'route2'],
            ['/en/suffix', 'DDTrace\Tests\Unit\Integrations\Annotations\LocalizedPrefixWithRouteWithoutLocale::action', 'action'],
            ['/en/suffix', 'DDTrace\Tests\Unit\Integrations\Annotations\LocalizedPrefixWithRouteWithoutLocale::action', 'action', 'en'],
            ['/en/suffix', 'DDTrace\Tests\Unit\Integrations\Annotations\LocalizedPrefixWithRouteWithoutLocale::action', 'action', 'es'],
            ['/nl/suffix', 'DDTrace\Tests\Unit\Integrations\Annotations\LocalizedPrefixWithRouteWithoutLocale::action', 'action', 'nl'],
            ['/basic-path', 'DDTrace\Tests\Unit\Integrations\DocBlocks\PhpActionAnnotationsController::basicAction', 'basic action'],
            ['/missing-name', 'DDTrace\Tests\Unit\Integrations\DocBlocks\PhpActionAnnotationsController::missingName', 'ddtrace_tests_unit_integrations_docblocks_phpactionannotations_missingname'],
            ['/', 'DDTrace\Tests\Unit\Integrations\DocBlocks\PhpActionAnnotationsController::nothingButName', 'only the name'],
            ['/dynamic-path/{argument}', 'DDTrace\Tests\Unit\Integrations\DocBlocks\PhpActionAnnotationsController::actionWithArguments', 'action with dynamic arguments'],
            ['/invokable', 'DDTrace\Tests\Unit\Integrations\DocBlocks\InvokableController', 'lol'],
            ['/invokable-en', 'DDTrace\Tests\Unit\Integrations\DocBlocks\InvokableLocalizedController', 'invokable localized'],
            ['/invokable-en', 'DDTrace\Tests\Unit\Integrations\DocBlocks\InvokableLocalizedController', 'invokable localized', 'en'],
            ['/invokable-nl', 'DDTrace\Tests\Unit\Integrations\DocBlocks\InvokableLocalizedController', 'invokable localized', 'nl'],
            ['/invokable-en', 'DDTrace\Tests\Unit\Integrations\DocBlocks\InvokableLocalizedController', 'invokable localized', 'es'],
            ['/localized-action-en', 'DDTrace\Tests\Unit\Integrations\DocBlocks\PhpActionAnnotationsController::localizedAction', 'localized method'],
            ['/localized-action-en', 'DDTrace\Tests\Unit\Integrations\DocBlocks\PhpActionAnnotationsController::localizedAction', 'localized method','en'],
            ['/localized-action-nl', 'DDTrace\Tests\Unit\Integrations\DocBlocks\PhpActionAnnotationsController::localizedAction', 'localized method','nl'],
            ['/localized-action-en', 'DDTrace\Tests\Unit\Integrations\DocBlocks\PhpActionAnnotationsController::localizedAction', 'localized method','es'],
            ['/hello/{name<\w+>}', 'DDTrace\Tests\Unit\Integrations\DocBlocks\PhpActionAnnotationsController::multipleRoutesAction', 'hello_without_default', 'en'],
            ['/hello/{name<\w+>?Symfony}', 'DDTrace\Tests\Unit\Integrations\DocBlocks\PhpActionAnnotationsController::multipleRoutesAction', 'hello_with_default', 'en'],
            ['/the/path', 'DDTrace\Tests\Unit\Integrations\DocBlocks\MethodActionControllers::post', 'post'],
            ['/the/path', 'DDTrace\Tests\Unit\Integrations\DocBlocks\MethodActionControllers::put', 'put'],
            ['/the/path', 'DDTrace\Tests\Unit\Integrations\DocBlocks\LocalizedMethodActionControllers::post', 'post'],
            ['/the/path', 'DDTrace\Tests\Unit\Integrations\DocBlocks\LocalizedMethodActionControllers::post', 'post','en'],
            ['/the/path', 'DDTrace\Tests\Unit\Integrations\DocBlocks\LocalizedMethodActionControllers::post', 'post', 'es'],
            ['/het/pad', 'DDTrace\Tests\Unit\Integrations\DocBlocks\LocalizedMethodActionControllers::post', 'post', 'nl'],
            ['/the/path', 'DDTrace\Tests\Unit\Integrations\DocBlocks\LocalizedMethodActionControllers::put', 'put'],
            ['/the/path', 'DDTrace\Tests\Unit\Integrations\DocBlocks\LocalizedMethodActionControllers::put', 'put', 'en'],
            ['/the/path', 'DDTrace\Tests\Unit\Integrations\DocBlocks\LocalizedMethodActionControllers::put', 'put', 'es'],
            ['/het/pad', 'DDTrace\Tests\Unit\Integrations\DocBlocks\LocalizedMethodActionControllers::put', 'put', 'nl'],
            ['/defaults/specific-locale', 'DDTrace\Tests\Unit\Integrations\DocBlocks\GlobalDefaultsClass::locale', 'specific_locale'],
            ['/defaults/specific-locale', 'DDTrace\Tests\Unit\Integrations\DocBlocks\GlobalDefaultsClass::locale', 'specific_locale', 'g_locale'],
            ['/defaults/specific-locale', 'DDTrace\Tests\Unit\Integrations\DocBlocks\GlobalDefaultsClass::locale', 'specific_locale', 's_locale'],
            ['/defaults/specific-format', 'DDTrace\Tests\Unit\Integrations\DocBlocks\GlobalDefaultsClass::format', 'specific_format'],
            ['/defaults/specific-format', 'DDTrace\Tests\Unit\Integrations\DocBlocks\GlobalDefaultsClass::format', 'specific_format', 'g_locale'],
            ['/prefix/path', 'DDTrace\Tests\Unit\Integrations\DocBlocks\PrefixedActionPathController::action', 'action'],
            ['/prefix/path', 'DDTrace\Tests\Unit\Integrations\DocBlocks\PrefixedActionLocalizedRouteController::action', 'action'],
            ['/prefix/path', 'DDTrace\Tests\Unit\Integrations\DocBlocks\PrefixedActionLocalizedRouteController::action', 'action', 'es'],
            ['/prefix/path', 'DDTrace\Tests\Unit\Integrations\DocBlocks\PrefixedActionLocalizedRouteController::action', 'action', 'en'],
            ['/prefix/pad', 'DDTrace\Tests\Unit\Integrations\DocBlocks\PrefixedActionLocalizedRouteController::action', 'action', 'nl'],
            ['/en/action', 'DDTrace\Tests\Unit\Integrations\DocBlocks\LocalizedPrefixLocalizedActionController::action', 'action'],
            ['/en/action', 'DDTrace\Tests\Unit\Integrations\DocBlocks\LocalizedPrefixLocalizedActionController::action', 'action', 'en'],
            ['/nl/actie', 'DDTrace\Tests\Unit\Integrations\DocBlocks\LocalizedPrefixLocalizedActionController::action', 'action', 'es'],
            ['/nl/actie', 'DDTrace\Tests\Unit\Integrations\DocBlocks\LocalizedPrefixLocalizedActionController::action', 'action', 'nl'],
            ['/1', 'DDTrace\Tests\Unit\Integrations\DocBlocks\BazClass', 'route1'],
            ['/2', 'DDTrace\Tests\Unit\Integrations\DocBlocks\BazClass', 'route2'],
            ['/en/suffix', 'DDTrace\Tests\Unit\Integrations\DocBlocks\LocalizedPrefixWithRouteWithoutLocale::action', 'action'],
            ['/en/suffix', 'DDTrace\Tests\Unit\Integrations\DocBlocks\LocalizedPrefixWithRouteWithoutLocale::action', 'action', 'en'],
            ['/en/suffix', 'DDTrace\Tests\Unit\Integrations\DocBlocks\LocalizedPrefixWithRouteWithoutLocale::action', 'action', 'es'],
            ['/nl/suffix', 'DDTrace\Tests\Unit\Integrations\DocBlocks\LocalizedPrefixWithRouteWithoutLocale::action', 'action', 'nl'],
        ];
    }

    /**
    * @dataProvider scenarios
    */
    public function testExtractions($url, $classMethod, $name = '', $locale = 'en')
    {
        $extractor = new PathExtractor();
        $this->assertEquals($url, $extractor->extract($classMethod, $name, $locale));
    }
}
