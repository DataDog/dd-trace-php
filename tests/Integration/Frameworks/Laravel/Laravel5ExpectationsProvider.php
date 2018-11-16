<?php

namespace DDTrace\Tests\Integration\Frameworks\Laravel;

use DDTrace\Tests\Integration\Common\SpanAssertion;
use DDTrace\Tests\Integration\Frameworks\Util\ExpectationProvider;


class Laravel5ExpectationsProvider implements ExpectationProvider
{
    /**
     * @return SpanAssertion[]
     */
    public function provide()
    {
        return [
            'A simple GET request returning a string' => [],
            'A simple GET request with a view' => [
                SpanAssertion::exists('laravel.view'),
            ],
        ];
    }
}
