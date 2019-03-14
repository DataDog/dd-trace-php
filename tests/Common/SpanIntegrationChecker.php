<?php

namespace DDTrace\Tests\Common;

use DDTrace\Contracts\Span;
use PHPUnit\Framework\TestCase;

class SpanIntegrationChecker
{
    public function defineIntegrationsByPattern()
    {
        // <regex pattern> => <integration class>
        return [
            // Prepend your operation names with custom for custom spans
            '/custom.*/' => null,
            // Officially supported integrations
            '/curl_.*/' => 'DDTrace\Integrations\Curl\CurlIntegration',
            '/Elasticsearch(.).*/' => 'DDTrace\Integrations\ElasticSearch\V1\ElasticSearchIntegration',
            '/GuzzleHttp.*/' => 'DDTrace\Integrations\Guzzle\GuzzleIntegration',
            '/Memcached.*/' => 'DDTrace\Integrations\Memcached\MemcachedIntegration',
            '/Mongo(Client)|(DB)|(Collection).*/' => 'DDTrace\Integrations\Mongo\MongoIntegration',
            '/mysqli.*/' => 'DDTrace\Integrations\Mysqli\MysqliIntegration',
            '/PDO(.)|(Statement).*/' => 'DDTrace\Integrations\PDO\PDOIntegration',
            '/Predis.*/' => 'DDTrace\Integrations\Predis\PredisIntegration',
        ];
    }

    public function checkIntegration(TestCase $test, Span $span)
    {
        $here = get_class($this) . '::defineOwners()';
        $operationName = $span->getOperationName();
        $definitionFound = false;

        foreach ($this->defineIntegrationsByPattern() as $operationNameRegex => $integrationClass)
        {
            if (preg_match($operationNameRegex, $operationName)) {
                $definitionFound = true;

                if (null === $integrationClass && null !== $span->getIntegration()) {
                    $message = "Unexpected integration found in span: $operationName.";
                    $test->fail($message);
                    break;
                } elseif (null !== $integrationClass && null === $span->getIntegration()) {
                    $message = "No integration defined in span for operation '$operationName'. " .
                               "Expected '$integrationClass'";
                    $test->fail($message);
                    break;
                } elseif (null !== $integrationClass && null !== $span->getIntegration()) {
                    $test->assertInstanceOf($integrationClass, $span->getIntegration());
                }
            }
        }

        if (false === $definitionFound) {
            $message = "Span's integration check is enabled but no matching pattern found for operation " .
                       "'$operationName' in '$here'. If you are testing an integration where spans are " .
                       "recreated from a request re-player, e.g. web tests, you can disable integration check " .
                       "using the param '\$checkIntegration = false'. If you are testing custom " .
                       "spans you can use operation name 'custom.*' or register the name in '$here'";
            $test->fail($message);
        }
    }
}
