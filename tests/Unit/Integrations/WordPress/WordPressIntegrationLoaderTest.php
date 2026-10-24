<?php

namespace DDTrace\Tests\Unit\Integrations\WordPress;

use DDTrace\Integrations\WordPress\WordPressIntegrationLoader;
use DDTrace\Tests\Common\BaseTestCase;

/**
 * matchSubjectMayDiverge decides whether WP::parse_request may have matched
 * rewrite rules against "$requested_file/$requested_path" instead of
 * WP::$request. True omits _dd.appsec.normalized_route, since the subject
 * WordPress used is not kept and the replay cannot be shown to be exact.
 */
class WordPressIntegrationLoaderTest extends BaseTestCase
{
    // No path info: WP::$request came from REQUEST_URI, no swap possible.

    public function testAbsentPathInfoKeepsTheRoute()
    {
        $this->assertFalse(
            WordPressIntegrationLoader::matchSubjectMayDiverge(null, '/hello-world', 'hello-world')
        );
    }

    public function testEmptyPathInfoKeepsTheRoute()
    {
        $this->assertFalse(
            WordPressIntegrationLoader::matchSubjectMayDiverge('', '/hello-world', 'hello-world')
        );
    }

    public function testPathInfoOfOnlySeparatorsKeepsTheRoute()
    {
        $this->assertFalse(
            WordPressIntegrationLoader::matchSubjectMayDiverge('/', '/hello-world', 'hello-world')
        );
    }

    // index.php/$1 rewrites: the path info consumes the whole request URI, so
    // the requested file is empty and WP's swap is guarded out. Omitting these
    // lost the tag on every request of such a site (review finding U-4).

    public function testPathInfoConsumingTheWholeRequestUriKeepsTheRoute()
    {
        $this->assertFalse(
            WordPressIntegrationLoader::matchSubjectMayDiverge('/hello-world', '/hello-world', 'hello-world')
        );
    }

    public function testPathInfoConsumingAMultiSegmentRequestUriKeepsTheRoute()
    {
        $this->assertFalse(WordPressIntegrationLoader::matchSubjectMayDiverge(
            '/2026/10/hello-world',
            '/2026/10/hello-world',
            '2026/10/hello-world'
        ));
    }

    public function testQueryStringIsNotPartOfEitherValue()
    {
        $this->assertFalse(WordPressIntegrationLoader::matchSubjectMayDiverge(
            '/hello-world?preview=true',
            '/hello-world?preview=true',
            'hello-world'
        ));
    }

    public function testPercentInThePathInfoIsReEncodedAsWordPressDoes()
    {
        // class-wp.php re-encodes '%' as '%25' before removing the path info
        // from the request URI, so a decoded path info still cancels out.
        $this->assertFalse(
            WordPressIntegrationLoader::matchSubjectMayDiverge('/100%', '/100%25', '100%25')
        );
    }

    // A non-empty requested file is exactly the case WP's swap is guarded for.

    public function testFrontControllerInTheRequestUriOmitsTheRoute()
    {
        $this->assertTrue(
            WordPressIntegrationLoader::matchSubjectMayDiverge('/hello-world', '/index.php/hello-world', 'hello-world')
        );
    }

    public function testSubdirectoryInstallOmitsTheRoute()
    {
        $this->assertTrue(WordPressIntegrationLoader::matchSubjectMayDiverge(
            '/hello-world',
            '/blog/index.php/hello-world',
            'hello-world'
        ));
    }

    public function testPathInfoNotFoundInTheRequestUriOmitsTheRoute()
    {
        // Nothing cancels out, so the requested file cannot be proven empty.
        $this->assertTrue(
            WordPressIntegrationLoader::matchSubjectMayDiverge('/hello-world', '/app/other', 'hello-world')
        );
    }

    public function testAbsentRequestUriOmitsTheRoute()
    {
        $this->assertTrue(
            WordPressIntegrationLoader::matchSubjectMayDiverge('/hello-world', null, 'hello-world')
        );
    }

    public function testEmptyRequestAlongsideAnEmptyRequestedFileOmitsTheRoute()
    {
        // Both come from the same variable unless WP took the permalink from
        // the path info; an empty request proves nothing either way, so this
        // stays omitted even though WP would not have swapped.
        $this->assertTrue(
            WordPressIntegrationLoader::matchSubjectMayDiverge('/blog', '/blog', '')
        );
    }
}
