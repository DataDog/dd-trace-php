<?php

namespace DDTrace\Tests\Unit\Integrations;

use PHPUnit\Framework\TestCase;
use DDTrace\Integrations\Symfony\PathExtractor;
use DDTrace\Tests\Unit\Integrations\Route;

require __DIR__.'/PhpAnnotationClasses.php';

/**
* @requires PHP >= 8.0
*/
class PathExtractorTest extends TestCase
{
    private $extractor;

    protected function setUp(): void
    {
        $this->extractor = new PathExtractor();
        $this->extractor->setRouteAnnotationClass(Route::class);
    }

    public function scenarios()
    {
        return [
            ['/basic-path', 'DDTrace\Tests\Unit\Integrations\PhpActionAnnotationsController::basicAction', 'basic action'],
            ['/missing-name', 'DDTrace\Tests\Unit\Integrations\PhpActionAnnotationsController::missingName', 'ddtrace_tests_unit_integrations_phpactionannotationscontroller_missingname'],
            ['/', 'DDTrace\Tests\Unit\Integrations\PhpActionAnnotationsController::nothingButName', 'only the name'],
            ['/dynamic-path/{argument}', 'DDTrace\Tests\Unit\Integrations\PhpActionAnnotationsController::actionWithArguments', 'action with dynamic arguments'],
            ['/invokable', 'DDTrace\Tests\Unit\Integrations\InvokableController', 'lol'],
            ['/invokable-en', 'DDTrace\Tests\Unit\Integrations\InvokableLocalizedController', 'invokable localized'],
            ['/invokable-en', 'DDTrace\Tests\Unit\Integrations\InvokableLocalizedController', 'invokable localized', 'en'],
            ['/invokable-nl', 'DDTrace\Tests\Unit\Integrations\InvokableLocalizedController', 'invokable localized', 'nl'],
            ['/invokable-en', 'DDTrace\Tests\Unit\Integrations\InvokableLocalizedController', 'invokable localized', 'es'],
            ['/localized-action-en', 'DDTrace\Tests\Unit\Integrations\PhpActionAnnotationsController::localizedAction', 'localized method'],
            ['/localized-action-en', 'DDTrace\Tests\Unit\Integrations\PhpActionAnnotationsController::localizedAction', 'localized method','en'],
            ['/localized-action-nl', 'DDTrace\Tests\Unit\Integrations\PhpActionAnnotationsController::localizedAction', 'localized method','nl'],
            ['/localized-action-en', 'DDTrace\Tests\Unit\Integrations\PhpActionAnnotationsController::localizedAction', 'localized method','es'],
            ['/hello/{name<\w+>}', 'DDTrace\Tests\Unit\Integrations\PhpActionAnnotationsController::multipleRoutesAction', 'hello_without_default', 'en'],
            ['/hello/{name<\w+>?Symfony}', 'DDTrace\Tests\Unit\Integrations\PhpActionAnnotationsController::multipleRoutesAction', 'hello_with_default', 'en'],
            ['/the/path', 'DDTrace\Tests\Unit\Integrations\MethodActionControllers::post', 'post'],
            ['/the/path', 'DDTrace\Tests\Unit\Integrations\MethodActionControllers::put', 'put'],
            ['/the/path', 'DDTrace\Tests\Unit\Integrations\LocalizedMethodActionControllers::post', 'post'],
            ['/the/path', 'DDTrace\Tests\Unit\Integrations\LocalizedMethodActionControllers::post', 'post','en'],
            ['/the/path', 'DDTrace\Tests\Unit\Integrations\LocalizedMethodActionControllers::post', 'post', 'es'],
            ['/het/pad', 'DDTrace\Tests\Unit\Integrations\LocalizedMethodActionControllers::post', 'post', 'nl'],
            ['/the/path', 'DDTrace\Tests\Unit\Integrations\LocalizedMethodActionControllers::put', 'put'],
            ['/the/path', 'DDTrace\Tests\Unit\Integrations\LocalizedMethodActionControllers::put', 'put', 'en'],
            ['/the/path', 'DDTrace\Tests\Unit\Integrations\LocalizedMethodActionControllers::put', 'put', 'es'],
            ['/het/pad', 'DDTrace\Tests\Unit\Integrations\LocalizedMethodActionControllers::put', 'put', 'nl'],
            ['/defaults/specific-locale', 'DDTrace\Tests\Unit\Integrations\GlobalDefaultsClass::locale', 'specific_locale'],
            ['/defaults/specific-locale', 'DDTrace\Tests\Unit\Integrations\GlobalDefaultsClass::locale', 'specific_locale', 'g_locale'],
            ['/defaults/specific-locale', 'DDTrace\Tests\Unit\Integrations\GlobalDefaultsClass::locale', 'specific_locale', 's_locale'],
            ['/defaults/specific-format', 'DDTrace\Tests\Unit\Integrations\GlobalDefaultsClass::format', 'specific_format'],
            ['/defaults/specific-format', 'DDTrace\Tests\Unit\Integrations\GlobalDefaultsClass::format', 'specific_format', 'g_locale'],
            ['/prefix/path', 'DDTrace\Tests\Unit\Integrations\PrefixedActionPathController::action', 'action'],
            ['/prefix/path', 'DDTrace\Tests\Unit\Integrations\PrefixedActionLocalizedRouteController::action', 'action'],
            ['/prefix/path', 'DDTrace\Tests\Unit\Integrations\PrefixedActionLocalizedRouteController::action', 'action', 'es'],
            ['/prefix/path', 'DDTrace\Tests\Unit\Integrations\PrefixedActionLocalizedRouteController::action', 'action', 'en'],
            ['/prefix/pad', 'DDTrace\Tests\Unit\Integrations\PrefixedActionLocalizedRouteController::action', 'action', 'nl'],
            ['/en/action', 'DDTrace\Tests\Unit\Integrations\LocalizedPrefixLocalizedActionController::action', 'action'],
            ['/en/action', 'DDTrace\Tests\Unit\Integrations\LocalizedPrefixLocalizedActionController::action', 'action', 'en'],
            ['/nl/actie', 'DDTrace\Tests\Unit\Integrations\LocalizedPrefixLocalizedActionController::action', 'action', 'es'],
            ['/nl/actie', 'DDTrace\Tests\Unit\Integrations\LocalizedPrefixLocalizedActionController::action', 'action', 'nl'],
            ['/1', 'DDTrace\Tests\Unit\Integrations\BazClass', 'route1'],
            ['/2', 'DDTrace\Tests\Unit\Integrations\BazClass', 'route2'],
            ['/en/suffix', 'DDTrace\Tests\Unit\Integrations\LocalizedPrefixWithRouteWithoutLocale::action', 'action'],
            ['/en/suffix', 'DDTrace\Tests\Unit\Integrations\LocalizedPrefixWithRouteWithoutLocale::action', 'action', 'en'],
            ['/en/suffix', 'DDTrace\Tests\Unit\Integrations\LocalizedPrefixWithRouteWithoutLocale::action', 'action', 'es'],
            ['/nl/suffix', 'DDTrace\Tests\Unit\Integrations\LocalizedPrefixWithRouteWithoutLocale::action', 'action', 'nl'],
        ];
    }

    /**
    * @dataProvider scenarios
     */
    public function testExtractions($url, $classMethod, $name = '', $locale = 'en')
    {
        $this->assertEquals($url, $this->extractor->extract($classMethod, $name, $locale));
    }
}
