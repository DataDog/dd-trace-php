<?php

namespace DDTrace\Tests\Integrations\CLI\Laravel\V8_X;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\CLITestCase;

class CommonScenariosTest extends CLITestCase
{
    protected function getScriptLocation()
    {
        return __DIR__ . '/../../../../Frameworks/Laravel/Version_8_x/artisan';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'APP_NAME' => 'artisan_test_app',
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
                Tag::SPAN_KIND => 'server',
            ])->withChildren([
                SpanAssertion::exists(
                    'laravel.provider.load',
                    'Illuminate\Foundation\ProviderRepository::load'
                ),
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
                Tag::SPAN_KIND => 'server',
            ])->withChildren([
                SpanAssertion::exists(
                    'laravel.provider.load',
                    'Illuminate\Foundation\ProviderRepository::load'
                ),
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
                Tag::SPAN_KIND => 'server',
            ])->withExistingTagsNames([
                'error.msg',
                'error.stack'
            ])->withChildren([
                SpanAssertion::exists(
                    'laravel.provider.load',
                    'Illuminate\Foundation\ProviderRepository::load'
                ),
            ])->setError(),
        ]);
    }
}
