<?php

namespace DDTrace\Tests\Integrations\CLI\Laravel\Latest;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\CLITestCase;

class CommonScenariosTest extends \DDTrace\Tests\Integrations\CLI\Laravel\V10_X\CommonScenariosTest
{
    public function testCommandWithNoArguments()
    {
       $this->retrieveDumpedData();

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
               SpanAssertion::exists('laravel.event.handle'),
           ]),
       ]);
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
               SpanAssertion::exists('laravel.event.handle'),
           ]),
       ]);
    }
}