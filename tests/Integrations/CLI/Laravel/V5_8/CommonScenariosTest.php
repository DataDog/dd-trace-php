<?php

namespace DDTrace\Tests\Integrations\CLI\Laravel\V5_8;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\CLITestCase;

class CommonScenariosTest extends CLITestCase
{
    protected function getScriptLocation()
    {
        return __DIR__ . '/../../../../Frameworks/Laravel/Version_5_8/artisan';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'APP_NAME' => 'artisan_test_app',
            'DD_TRACE_GENERATE_ROOT_SPAN' => 'true',
        ]);
    }

    public function testCommandWithNoArguments()
    {
        $traces = $this->getTracesFromCommand();

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                'laravel.artisan',
                'artisan_test_app',
                'cli',
                'artisan'
            )->withExactTags([
                Tag::COMPONENT => 'laravel',
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
            ]),
        ]);
    }

    public function testCommandWithArgument()
    {
        $traces = $this->getTracesFromCommand('route:list');

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                'laravel.artisan',
                'artisan_test_app',
                'cli',
                'artisan route:list'
            )->withExactTags([
                Tag::COMPONENT => 'laravel',
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
            ]),
        ]);
    }

    public function testCommandWithError()
    {
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