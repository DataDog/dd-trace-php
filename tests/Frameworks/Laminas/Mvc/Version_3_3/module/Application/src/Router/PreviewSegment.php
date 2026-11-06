<?php

declare(strict_types=1);

namespace Application\Router;

use Laminas\Router\Http\RouteMatch;
use Laminas\Router\Http\Segment;
use Laminas\Stdlib\RequestInterface as Request;

/**
 * A Segment that serves a trailing /preview as a flag rather than as part of
 * its definition: /posts/preview matches the definition /posts[/:id] with id
 * absent, not with id=preview.
 */
class PreviewSegment extends Segment
{
    private const SUFFIX = '/preview';

    public function match(Request $request, $pathOffset = null, array $options = [])
    {
        if (!method_exists($request, 'getUri')) {
            return null;
        }
        $path = $request->getUri()->getPath();
        if (substr($path, -\strlen(self::SUFFIX)) !== self::SUFFIX) {
            return parent::match($request, $pathOffset, $options);
        }

        $stripped = clone $request;
        $stripped->setUri((clone $request->getUri())->setPath(substr($path, 0, -\strlen(self::SUFFIX))));
        $match = parent::match($stripped, $pathOffset, $options);
        if (!$match instanceof RouteMatch) {
            return null;
        }
        $match->setParam('preview', true);

        return new RouteMatch($match->getParams(), $match->getLength() + \strlen(self::SUFFIX));
    }
}
