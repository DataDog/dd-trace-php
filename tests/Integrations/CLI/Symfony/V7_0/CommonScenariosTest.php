<?php

namespace DDTrace\Tests\Integrations\CLI\Symfony\V7_0;

class CommonScenariosTest extends \DDTrace\Tests\Integrations\CLI\Symfony\V6_2\CommonScenariosTest
{
    protected static function getConsoleScript()
    {
        return __DIR__ . '/../../../../Frameworks/Symfony/Version_7_0/bin/console';
    }

    public function testHttpRoutes()
    {
        $routesPath = str_replace('bin/console', '', $this->getConsoleScript()).'var/cache/dev/url_matching_routes.php';
        if (file_exists($routesPath)) {
            unlink($routesPath); //Lets ensure it's created on this tests
        }
        $this->inCli(self::getConsoleScript(), [
                    'DD_TRACE_CLI_ENABLED' => 'true',
                    'DD_TRACE_GENERATE_ROOT_SPAN' => 'false',
                    'DD_TRACE_AUTO_FLUSH_ENABLED' => 'true',
                    'DD_TRACE_EXEC_ENABLED' => 'false',
                ], [], 'cache:clear');
        $routes = include $routesPath;
        $this->assertEquals(5, count($routes));
        $staticRoutes = $routes[1];
        $dynamicRoutes = $routes[3];
        $this->assertNotEmpty($staticRoutes);
        $this->assertNotEmpty($dynamicRoutes);

        $this->assertEquals('/staticUrl', $staticRoutes['/staticUrl'][0][0]['_path']);
        $this->assertEquals('/locale-en', $staticRoutes['/locale-en'][0][0]['_path']);
        $this->assertEquals('/locale-nl', $staticRoutes['/locale-nl'][0][0]['_path']);
        $dynamicPath = '';
        foreach($dynamicRoutes as $route) {
            if ($route[0][0]['_route'] == 'app_commonscenarios_dynamicurl') {
                $dynamicPath = $route[0][0]['_path'];
            }
        }
        $this->assertEquals('/dynamicUrl/{someParam}', $dynamicPath);
    }
}
