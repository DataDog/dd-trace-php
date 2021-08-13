<?php

namespace DDTrace\Tests\Integrations\WordPress\V5_5;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;
use Exception;

final class CommonScenariosTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/WordPress/Version_5_5/index.php';
    }

    public static function ddSetUpBeforeClass()
    {
        parent::ddSetUpBeforeClass();
        $pdo = new \PDO('mysql:host=mysql_integration;dbname=test', 'test', 'test');
        $pdo->exec(file_get_contents(__DIR__ . '/../../../Frameworks/WordPress/Version_5_5/wp_2020-10-21.sql'));
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE' => 'wordpress_55_test_app',
            'DD_TRACE_DEBUG' => 'true',
        ]);
    }

    /**
     * @dataProvider provideSpecs
     * @param RequestSpec $spec
     * @param array $spanExpectations
     * @throws \Exception
     */
    public function testScenario(RequestSpec $spec, array $spanExpectations)
    {
        $traces = $this->tracesFromWebRequest(function () use ($spec) {
            $this->call($spec);
        });

        $this->assertFlameGraph($traces, $spanExpectations);
    }

    public function provideSpecs()
    {
        return $this->buildDataProvider(
            [
                'A simple GET request returning a string' => [
                    SpanAssertion::build(
                        'wordpress.request',
                        'wordpress_55_test_app',
                        'web',
                        'GET /simple'
                    )->withExactTags([
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost:9999/simple',
                        'http.status_code' => '200',
                    ])->withChildren([
                        SpanAssertion::exists(
                            'wpdb.query',
                            "SELECT option_value FROM wp55_options WHERE option_name = 'WPLANG' LIMIT 1"
                        )->withChildren([
                            SpanAssertion::exists('mysqli_query'),
                        ]),
                        SpanAssertion::exists(
                            'wpdb.query',
                            "SELECT option_value FROM wp55_options WHERE option_name = 'theme_switched' LIMIT 1"
                        )->withChildren([
                            SpanAssertion::exists('mysqli_query'),
                        ]),
                        SpanAssertion::exists('WP.init'),
                        SpanAssertion::exists('WP_Widget_Factory._register_widgets')->withChildren([
                            SpanAssertion::exists('wpdb.query')->withChildren([
                                SpanAssertion::exists('mysqli_query'),
                            ]),
                        ]),
                        SpanAssertion::exists('create_initial_taxonomies'),
                        SpanAssertion::exists('create_initial_post_types'),
                        SpanAssertion::exists('_wp_customize_include'),
                        SpanAssertion::exists('wp_maybe_load_embeds'),
                        SpanAssertion::exists('wp_maybe_load_widgets'),
                        SpanAssertion::exists('create_initial_post_types'),
                        SpanAssertion::exists('create_initial_taxonomies'),

                        SpanAssertion::exists('mysqli_query'),
                        SpanAssertion::exists('mysqli_query'),
                        SpanAssertion::exists('mysqli_query'),
                        SpanAssertion::exists('mysqli_query'),
                        SpanAssertion::exists('mysqli_real_connect'),
                        SpanAssertion::exists('WP.main')->withChildren([
                            SpanAssertion::exists('WP.init'),
                            SpanAssertion::exists('WP.parse_request')->withChildren([
                                SpanAssertion::exists(
                                    'wpdb.query',
                                    "SELECT ID, post_name, post_parent, post_type
                                    FROM wp55_posts
                                    WHERE post_name IN ('simple')
                                    AND post_type IN ('page','attachment')"
                                )->withChildren([
                                    SpanAssertion::exists('mysqli_query'),
                                ]),
                            ]),
                        ]),
                    ]),
                ],
                'A simple GET request with a view' => [
                    SpanAssertion::build(
                        'wordpress.request',
                        'wordpress_55_test_app',
                        'web',
                        'GET /simple_view'
                    )->withExactTags([
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost:9999/simple_view',
                        'http.status_code' => '200',
                    ])->withChildren([
                        SpanAssertion::exists('WP.init'),
                        SpanAssertion::exists('WP.main')
                            ->withChildren([
                                SpanAssertion::exists('WP.init'),
                                SpanAssertion::exists('WP.register_globals'),
                                SpanAssertion::exists('WP.handle_404'),
                                SpanAssertion::exists('WP.query_posts')
                                    ->withChildren([
                                        SpanAssertion::exists('wpdb.query')
                                            ->withChildren([
                                                SpanAssertion::exists('mysqli_query'),
                                            ]),
                                        SpanAssertion::exists('wpdb.query')
                                            ->withChildren([
                                                SpanAssertion::exists('mysqli_query'),
                                            ]),
                                        SpanAssertion::exists('wpdb.query')
                                            ->withChildren([
                                                SpanAssertion::exists('mysqli_query'),
                                            ]),
                                    ]),
                                SpanAssertion::exists('WP.send_headers'),
                                SpanAssertion::exists('WP.parse_request')
                                    ->withChildren([
                                        SpanAssertion::exists('wpdb.query')
                                            ->withChildren([
                                                SpanAssertion::exists('mysqli_query'),
                                            ]),
                                    ]),
                            ]),
                        SpanAssertion::exists('WP_Widget_Factory._register_widgets')->withChildren([
                            SpanAssertion::exists('wpdb.query')->withChildren([
                                SpanAssertion::exists('mysqli_query'),
                            ]),
                        ]),
                        SpanAssertion::exists('_wp_customize_include'),
                        SpanAssertion::exists('create_initial_taxonomies'),
                        SpanAssertion::exists('create_initial_post_types'),
                        SpanAssertion::exists('create_initial_post_types'),
                        SpanAssertion::exists('create_initial_taxonomies'),
                        SpanAssertion::exists('get_footer')
                            ->withChildren([
                                SpanAssertion::exists('load_template')
                                    ->withChildren([
                                        SpanAssertion::exists('wp_print_footer_scripts'),
                                    ]),
                            ]),
                        SpanAssertion::exists('load_template')->withChildren([
                            SpanAssertion::exists('comments_template')->withChildren([
                                SpanAssertion::exists('wpdb.query')->withChildren([
                                    SpanAssertion::exists('mysqli_query'),
                                ]),
                            ]),
                        ]),
                        SpanAssertion::exists('mysqli_query'),
                        SpanAssertion::exists('mysqli_query'),
                        SpanAssertion::exists('mysqli_query'),
                        SpanAssertion::exists('mysqli_query'),
                        SpanAssertion::exists('load_template')->withChildren([
                            SpanAssertion::exists('comments_template')->withChildren([
                                SpanAssertion::exists('wpdb.query')->withChildren([
                                    SpanAssertion::exists('mysqli_query'),
                                ]),
                            ]),
                        ]),
                        SpanAssertion::exists('mysqli_real_connect'),
                        SpanAssertion::exists('get_header')
                            ->withChildren([
                                SpanAssertion::exists('load_template')
                                    ->withChildren([
                                        SpanAssertion::exists('wpdb.query')->withChildren([
                                            SpanAssertion::exists('mysqli_query'),
                                        ]),
                                        SpanAssertion::exists('body_class')
                                            ->withChildren([
                                            ]),
                                        SpanAssertion::exists('wp_head')
                                            ->withChildren([
                                                SpanAssertion::exists('wp_print_head_scripts'),
                                                SpanAssertion::exists('wpdb.query')
                                                    ->withChildren([
                                                        SpanAssertion::exists('mysqli_query'),
                                                    ]),
                                                SpanAssertion::exists('wpdb.query')
                                                    ->withChildren([
                                                        SpanAssertion::exists('mysqli_query'),
                                                    ]),
                                                SpanAssertion::exists('wpdb.query')
                                                    ->withChildren([
                                                        SpanAssertion::exists('mysqli_query'),
                                                    ]),
                                            ]),
                                    ]),
                            ]),
                        SpanAssertion::exists('wp_maybe_load_embeds'),
                        SpanAssertion::exists('wp_maybe_load_widgets'),
                        SpanAssertion::exists('wpdb.query')
                            ->withChildren([
                                SpanAssertion::exists('mysqli_query'),
                            ]),
                        SpanAssertion::exists('wpdb.query')
                            ->withChildren([
                                SpanAssertion::exists('mysqli_query'),
                            ]),
                        SpanAssertion::exists('wpdb.query')
                            ->withChildren([
                                SpanAssertion::exists('mysqli_query'),
                            ]),
                        SpanAssertion::exists('wpdb.query')
                            ->withChildren([
                                SpanAssertion::exists('mysqli_query'),
                            ]),
                    ]),
                ],
                'A GET request with an exception' => [
                    SpanAssertion::build(
                        'wordpress.request',
                        'wordpress_55_test_app',
                        'web',
                        'GET /error'
                    )->withExactTags([
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost:9999/error',
                        // On php 5 WordPress returns 500 on error, as expected, while on 7.x it returns 200
                        // regardless of the extension being installed.
                        'http.status_code' => $this->matchesPhpVersion('5') ? '500' : '200',
                    ])->ifPhpVersionNotMatch('5.4', function (SpanAssertion $assertion) {
                        // Automatic error attachment to root span in case of PHP 5.4 is still under development.
                        $message = PHP_MAJOR_VERSION >= 7
                            ? "Uncaught Exception: Oops! in %s:%d"
                            : "Uncaught exception 'Exception' with message 'Oops!' in %s:%d";
                        $assertion
                            ->setError(PHP_VERSION_ID >= 70000 ? "Exception" : "E_ERROR", $message)
                            ->withExistingTagsNames(['error.stack']);
                    })->withChildren([
                        SpanAssertion::exists('WP.main')
                            // There's no way to propagate this to the root span in userland yet
                            ->setError('Exception', 'Oops!')
                            ->withChildren([
                                SpanAssertion::exists('WP.parse_request')
                                    ->withChildren([
                                        SpanAssertion::exists('wpdb.query')
                                            ->withChildren([
                                                SpanAssertion::exists('mysqli_query'),
                                            ]),
                                    ]),
                                SpanAssertion::exists('WP.init'),
                            ]),
                        SpanAssertion::exists('wpdb.query')
                            ->withChildren([
                                SpanAssertion::exists('mysqli_query'),
                            ]),
                        SpanAssertion::exists('wpdb.query')
                            ->withChildren([
                                SpanAssertion::exists('mysqli_query'),
                            ]),
                        SpanAssertion::exists('WP_Widget_Factory._register_widgets')->withChildren([
                            SpanAssertion::exists('wpdb.query')->withChildren([
                                SpanAssertion::exists('mysqli_query'),
                            ]),
                        ]),
                        SpanAssertion::exists('create_initial_taxonomies'),
                        SpanAssertion::exists('create_initial_post_types'),
                        SpanAssertion::exists('WP.init'),
                        SpanAssertion::exists('_wp_customize_include'),
                        SpanAssertion::exists('wp_maybe_load_embeds'),
                        SpanAssertion::exists('wp_maybe_load_widgets'),
                        SpanAssertion::exists('create_initial_post_types'),
                        SpanAssertion::exists('create_initial_taxonomies'),
                        SpanAssertion::exists('mysqli_query'),
                        SpanAssertion::exists('mysqli_query'),
                        SpanAssertion::exists('mysqli_query'),
                        SpanAssertion::exists('mysqli_query'),
                        SpanAssertion::exists('mysqli_real_connect'),
                    ]),
                ],
                'A GET request to a missing route' => [
                    SpanAssertion::build(
                        'wordpress.request',
                        'wordpress_55_test_app',
                        'web',
                        'GET /does_not_exist'
                    )->withExactTags([
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost:9999/does_not_exist',
                        'http.status_code' => '404',
                    ])->withChildren([
                        SpanAssertion::exists('WP.init'),
                        SpanAssertion::exists('WP.main')
                            ->withChildren([
                                SpanAssertion::exists('WP.init'),
                                SpanAssertion::exists('WP.register_globals'),
                                SpanAssertion::exists('WP.handle_404'),
                                SpanAssertion::exists('WP.query_posts')
                                    ->withChildren([
                                        SpanAssertion::exists('wpdb.query')
                                            ->withChildren([
                                                SpanAssertion::exists('mysqli_query'),
                                            ]),
                                    ]),
                                SpanAssertion::exists('WP.send_headers'),
                                SpanAssertion::exists('WP.parse_request')
                                    ->withChildren([
                                        SpanAssertion::exists('wpdb.query')
                                            ->withChildren([
                                                SpanAssertion::exists('mysqli_query'),
                                            ]),
                                    ]),
                            ]),
                        SpanAssertion::exists('WP_Widget_Factory._register_widgets')->withChildren([
                            SpanAssertion::exists('wpdb.query')->withChildren([
                                SpanAssertion::exists('mysqli_query'),
                            ]),
                        ]),
                        SpanAssertion::exists('_wp_customize_include'),
                        SpanAssertion::exists('create_initial_taxonomies'),
                        SpanAssertion::exists('create_initial_post_types'),
                        SpanAssertion::exists('create_initial_post_types'),
                        SpanAssertion::exists('create_initial_taxonomies'),
                        SpanAssertion::exists('get_footer')
                            ->withChildren([
                                SpanAssertion::exists('load_template')
                                    ->withChildren([
                                        SpanAssertion::exists('wp_print_footer_scripts'),
                                    ]),
                            ]),
                        SpanAssertion::exists('mysqli_query'),
                        SpanAssertion::exists('mysqli_query'),
                        SpanAssertion::exists('mysqli_query'),
                        SpanAssertion::exists('mysqli_query'),
                        SpanAssertion::exists('load_template')->withChildren([
                            SpanAssertion::exists('dynamic_sidebar')
                                ->withChildren([
                                    SpanAssertion::exists('WP_Widget.display_callback'),
                                    SpanAssertion::exists('WP_Widget.display_callback'),
                                    SpanAssertion::exists('WP_Widget.display_callback'),
                                ]),
                            SpanAssertion::exists('dynamic_sidebar')
                                ->withChildren([
                                    SpanAssertion::exists('WP_Widget.display_callback'),
                                    SpanAssertion::exists('WP_Widget.display_callback'),
                                    SpanAssertion::exists('WP_Widget.display_callback'),
                                ]),
                        ]),
                        SpanAssertion::exists('mysqli_real_connect'),
                        SpanAssertion::exists('get_header')
                            ->withChildren([
                                SpanAssertion::exists('load_template')
                                    ->withChildren([
                                        SpanAssertion::exists('wpdb.query')->withChildren([
                                            SpanAssertion::exists('mysqli_query'),
                                        ]),
                                        SpanAssertion::exists('body_class')
                                            ->withChildren([
                                            ]),
                                        SpanAssertion::exists('wp_head')
                                            ->withChildren([
                                                SpanAssertion::exists('wp_print_head_scripts'),
                                            ]),
                                    ]),
                            ]),
                        SpanAssertion::exists('wp_maybe_load_embeds'),
                        SpanAssertion::exists('wp_maybe_load_widgets'),
                        SpanAssertion::exists('wpdb.query')
                            ->withChildren([
                                SpanAssertion::exists('mysqli_query'),
                            ]),
                        SpanAssertion::exists('wpdb.query')
                            ->withChildren([
                                SpanAssertion::exists('mysqli_query'),
                            ]),
                        SpanAssertion::exists('wpdb.query')
                            ->withChildren([
                                SpanAssertion::exists('mysqli_query'),
                            ]),
                        SpanAssertion::exists('wpdb.query')
                            ->withChildren([
                                SpanAssertion::exists('mysqli_query'),
                            ]),
                    ]),
                ],
            ]
        );
    }
}
