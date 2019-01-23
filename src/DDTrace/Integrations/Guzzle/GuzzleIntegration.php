<?php


namespace DDTrace\Integrations\Guzzle;

use DDTrace\Integrations\Integration;
use DDTrace\Util\CodeTracer;
use DDTrace\Util\Versions;
use DDTrace\Integrations\Guzzle\V5\GuzzleIntegrationLoader as V5Loader;
use DDTrace\Integrations\Guzzle\V6\GuzzleIntegrationLoader as V6Loader;

final class GuzzleIntegration
{
    const NAME = 'guzzle';

    public static function load()
    {
        if (Versions::phpVersionMatches('5.4')) {
            return Integration::NOT_AVAILABLE;
        }

        if (!defined('GuzzleHttp\ClientInterface::VERSION')) {
            return Integration::NOT_LOADED;
        }

        $version = \GuzzleHttp\ClientInterface::VERSION;

        if (Versions::versionMatches('5', $version)) {
            return (new V5Loader(CodeTracer::getInstance()))->load(self::NAME);
        } elseif (Versions::versionMatches('6', $version)) {
            return (new V6Loader(CodeTracer::getInstance()))->load(self::NAME);
        }

        return Integration::NOT_LOADED;
    }
}
