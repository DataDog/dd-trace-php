<?php

namespace DDTrace\Tests\Integrations\CLI\Laravel\V10_X;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\CLITestCase;

class CommonScenariosTest extends \DDTrace\Tests\Integrations\CLI\Laravel\V9_X\CommonScenariosTest
{
    protected function getScriptLocation()
    {
        return __DIR__ . '/../../../../Frameworks/Laravel/Version_10_x/artisan';
    }

    public function testCommandWithError()
    {
        $this->retrieveDumpedData();

        $traces = $this->getTracesFromCommand('foo:error');

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                'laravel.artisan',
                'artisan_test_app',
                'cli',
                'artisan foo:error'
            )->withExactTags([
                Tag::COMPONENT => 'laravel',
            ])->withExistingTagsNames([
                Tag::ERROR_MSG,
                'error.stack'
            ])->withChildren([
                SpanAssertion::exists(
                    'laravel.provider.load',
                    'Illuminate\Foundation\ProviderRepository::load'
                ),
                SpanAssertion::exists('laravel.event.handle'),
                SpanAssertion::exists('laravel.event.handle'),
                SpanAssertion::exists('laravel.event.handle'),
                SpanAssertion::exists('laravel.event.handle'),
                SpanAssertion::exists('laravel.event.handle'),
                SpanAssertion::exists('laravel.event.handle'),
                SpanAssertion::exists('laravel.event.handle'),
                SpanAssertion::exists('laravel.event.handle'),
                SpanAssertion::exists('laravel.event.handle'),
                SpanAssertion::exists('laravel.event.handle'),
                SpanAssertion::exists('laravel.event.handle'),
                SpanAssertion::exists('laravel.event.handle'),
                SpanAssertion::exists('laravel.event.handle'),
                SpanAssertion::exists('laravel.event.handle'),
                SpanAssertion::exists('laravel.event.handle'),
                SpanAssertion::exists('laravel.event.handle'),
                SpanAssertion::exists('laravel.event.handle'),
            ])->setError(),
        ]);
    }
}