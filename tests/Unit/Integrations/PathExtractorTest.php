<?php

namespace DDTrace\Tests\Unit\Integrations;

use PHPUnit\Framework\TestCase;
use DDTrace\Integrations\Symfony\PathExtractor;
use DDTrace\Tests\Unit\Integrations\Route;

require __DIR__."/PhpAnnotationClasses.php";

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
            ["/basic-path", "DDTrace\Tests\Unit\Integrations\PhpActionAnnotationsController::basicAction"],
            ["/missing-name", "DDTrace\Tests\Unit\Integrations\PhpActionAnnotationsController::missingName"],
            ["/", "DDTrace\Tests\Unit\Integrations\PhpActionAnnotationsController::nothingButName"],
            ["/dynamic-path/{argument}", "DDTrace\Tests\Unit\Integrations\PhpActionAnnotationsController::actionWithArguments"],
            ["/invokable", "DDTrace\Tests\Unit\Integrations\InvokableController"],
            ["/invokable-en", "DDTrace\Tests\Unit\Integrations\InvokableLocalizedController"],
            ["/invokable-en", "DDTrace\Tests\Unit\Integrations\InvokableLocalizedController", 'en'],
            ["/invokable-nl", "DDTrace\Tests\Unit\Integrations\InvokableLocalizedController", 'nl'],
            ["/invokable-en", "DDTrace\Tests\Unit\Integrations\InvokableLocalizedController", 'es'],
            ["/localized-action-en", "DDTrace\Tests\Unit\Integrations\PhpActionAnnotationsController::localizedAction"],
            ["/localized-action-en", "DDTrace\Tests\Unit\Integrations\PhpActionAnnotationsController::localizedAction", 'en'],
            ["/localized-action-nl", "DDTrace\Tests\Unit\Integrations\PhpActionAnnotationsController::localizedAction", 'nl'],
            ["/localized-action-en", "DDTrace\Tests\Unit\Integrations\PhpActionAnnotationsController::localizedAction", 'es'],
            ["/hello/{name<\w+>}", "DDTrace\Tests\Unit\Integrations\PhpActionAnnotationsController::multipleRoutesAction", 'en', 'hello_without_default'],
            ["/hello/{name<\w+>?Symfony}", "DDTrace\Tests\Unit\Integrations\PhpActionAnnotationsController::multipleRoutesAction", 'en', 'hello_with_default'],
            ["/the/path", "DDTrace\Tests\Unit\Integrations\MethodActionControllers::post"],
            ["/the/path", "DDTrace\Tests\Unit\Integrations\MethodActionControllers::put"],
            ["/the/path", "DDTrace\Tests\Unit\Integrations\LocalizedMethodActionControllers::post"],
            ["/the/path", "DDTrace\Tests\Unit\Integrations\LocalizedMethodActionControllers::post", 'en'],
            ["/the/path", "DDTrace\Tests\Unit\Integrations\LocalizedMethodActionControllers::post", 'es'],
            ["/het/pad", "DDTrace\Tests\Unit\Integrations\LocalizedMethodActionControllers::post", 'nl'],
            ["/the/path", "DDTrace\Tests\Unit\Integrations\LocalizedMethodActionControllers::put"],
            ["/the/path", "DDTrace\Tests\Unit\Integrations\LocalizedMethodActionControllers::put", 'en'],
            ["/the/path", "DDTrace\Tests\Unit\Integrations\LocalizedMethodActionControllers::put", 'es'],
            ["/het/pad", "DDTrace\Tests\Unit\Integrations\LocalizedMethodActionControllers::put", 'nl'],
            ["/defaults/specific-locale", "DDTrace\Tests\Unit\Integrations\GlobalDefaultsClass::locale"],
            ["/defaults/specific-locale", "DDTrace\Tests\Unit\Integrations\GlobalDefaultsClass::locale", 'g_locale'],
            ["/defaults/specific-locale", "DDTrace\Tests\Unit\Integrations\GlobalDefaultsClass::locale", 's_locale'],
            ["/defaults/specific-format", "DDTrace\Tests\Unit\Integrations\GlobalDefaultsClass::format"],
            ["/defaults/specific-format", "DDTrace\Tests\Unit\Integrations\GlobalDefaultsClass::format", 'g_locale'],
            ["/prefix/path", "DDTrace\Tests\Unit\Integrations\PrefixedActionPathController::action"],
            ["/prefix/path", "DDTrace\Tests\Unit\Integrations\PrefixedActionLocalizedRouteController::action"],
            ["/prefix/path", "DDTrace\Tests\Unit\Integrations\PrefixedActionLocalizedRouteController::action", 'es'],
            ["/prefix/path", "DDTrace\Tests\Unit\Integrations\PrefixedActionLocalizedRouteController::action", 'en'],
            ["/prefix/pad", "DDTrace\Tests\Unit\Integrations\PrefixedActionLocalizedRouteController::action", 'nl'],
            ["/en/action", "DDTrace\Tests\Unit\Integrations\LocalizedPrefixLocalizedActionController::action"],
            ["/en/action", "DDTrace\Tests\Unit\Integrations\LocalizedPrefixLocalizedActionController::action", 'en'],
            ["/nl/actie", "DDTrace\Tests\Unit\Integrations\LocalizedPrefixLocalizedActionController::action", 'es'],
            ["/nl/actie", "DDTrace\Tests\Unit\Integrations\LocalizedPrefixLocalizedActionController::action", 'nl'],
            ["/1", "DDTrace\Tests\Unit\Integrations\BazClass", 'en', 'route1'],
            ["/2", "DDTrace\Tests\Unit\Integrations\BazClass", 'en', 'route2'],
            ["/en/suffix", "DDTrace\Tests\Unit\Integrations\LocalizedPrefixWithRouteWithoutLocale::action"],
            ["/en/suffix", "DDTrace\Tests\Unit\Integrations\LocalizedPrefixWithRouteWithoutLocale::action", 'en'],
            ["/en/suffix", "DDTrace\Tests\Unit\Integrations\LocalizedPrefixWithRouteWithoutLocale::action", 'es'],
            ["/nl/suffix", "DDTrace\Tests\Unit\Integrations\LocalizedPrefixWithRouteWithoutLocale::action", 'nl'],
        ];
    }

    /**
    * @dataProvider scenarios
     */
    public function testExtractions($url, $classMethod, $locale = 'en', $name = '')
    {
          $this->assertEquals($url, $this->extractor->extract($classMethod, $name, $locale));
    }
}
