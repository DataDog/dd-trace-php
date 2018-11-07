<?php

namespace DDTrace\Tests\Integration\Frameworks\Symfony;

use DDTrace\Tests\Integration\Common\SpanAssertion;
use DDTrace\Tests\Integration\Frameworks\Util\ExpectationProvider;


class Symfony4ExpectationsProvider implements ExpectationProvider
{
    /**
     * @return SpanAssertion[]
     */
    public function provide()
    {
        return [
            'A simple GET request' => [],
            'A simple GET request with a view' => [
                SpanAssertion::exists('symfony.view'),
            ],
        ];
    }
}
