<?php

namespace DDTrace\Tests\Common;

use DDTrace\Configuration;
use DDTrace\Contracts\Span;
use PHPUnit\Framework\TestCase;

/**
 * Checks that spans are assigned the proper integration.
 */
final class SpanIntegrationChecker
{
    /**
     * Returns the known matching patterns operation ==> integration class.
     */
    public function defineIntegrationsByPattern()
    {
        // <regex pattern> => <integration class>
        $pdoIntegration = 'DDTrace\Integrations\PDO\PDOIntegration';
        if (Configuration::get()->isSandboxEnabled()) {
            $pdoIntegration = 'DDTrace\Integrations\PDO\PDOSandboxedIntegration';
        }
        return [
            // Prepend your operation names with custom for custom spans to have the span check disabled
            '/custom.*/' => null,
            // Officially supported integrations
            '/curl_.*/' => 'DDTrace\Integrations\Curl\CurlIntegration',
            '/Elasticsearch(.).*/' => 'DDTrace\Integrations\ElasticSearch\V1\ElasticSearchIntegration',
            '/GuzzleHttp.*/' => 'DDTrace\Integrations\Guzzle\GuzzleIntegration',
            '/laravel.*/' => 'DDTrace\Integrations\Laravel\LaravelIntegration',
            '/Memcached.*/' => 'DDTrace\Integrations\Memcached\MemcachedIntegration',
            '/Mongo(Client)|(DB)|(Collection).*/' => 'DDTrace\Integrations\Mongo\MongoIntegration',
            '/mysqli.*/' => 'DDTrace\Integrations\Mysqli\MysqliIntegration',
            '/PDO(.)|(Statement).*/' => $pdoIntegration,
            '/Predis.*/' => 'DDTrace\Integrations\Predis\PredisIntegration',
            '/symfony.*/' => 'DDTrace\Integrations\Symfony\SymfonyIntegration',
            '/web.*/' => 'DDTrace\Integrations\Web\WebIntegration',
            '/zf.*/' => 'DDTrace\Integrations\ZendFramework\ZendFrameworkIntegration',
        ];
    }

    /**
     * Checks that $span belongs to the proper integration.
     *
     * @param TestCase $test
     * @param Span $span
     */
    public function checkIntegration(TestCase $test, Span $span)
    {
        $here = get_class($this) . '::defineOwners()';
        $operationName = $span->getOperationName();
        $definitionFound = false;

        foreach ($this->defineIntegrationsByPattern() as $operationNameRegex => $integrationClass) {
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
            $message = "No matching pattern found for operation " .
                        "'$operationName' in '$here'. If you are testing custom " .
                        "spans you can use operation name 'custom.*' or register the name in '$here'";
            $test->fail($message);
        }
    }
}
