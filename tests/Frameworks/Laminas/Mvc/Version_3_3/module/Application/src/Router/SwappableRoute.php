<?php

declare(strict_types=1);

namespace Application\Router;

use Laminas\Router\Http\Literal;
use Laminas\Router\Http\RouteInterface;
use Laminas\Router\Http\Segment;
use Laminas\Stdlib\RequestInterface as Request;

/**
 * Matches its definition as a Literal, or as a Segment when the query has
 * as=segment, returning that route's RouteMatch. One process thus sees one
 * route name and http.route with two structures, as it would after a deploy
 * that changed the route's type without a restart.
 */
class SwappableRoute implements RouteInterface
{
    /** @var string */
    private $route;

    /** @var Literal */
    private $literal;

    /** @var Segment */
    private $segment;

    public function __construct(string $route, array $defaults = [])
    {
        $this->route = $route;
        $this->literal = new Literal($route, $defaults);
        $this->segment = new Segment($route, [], $defaults);
    }

    public static function factory($options = [])
    {
        return new static($options['route'], $options['defaults'] ?? []);
    }

    public function match(Request $request, $pathOffset = null)
    {
        return $this->inner($request)->match($request, $pathOffset);
    }

    public function assemble(array $params = [], array $options = [])
    {
        return $this->literal->assemble($params, $options);
    }

    public function getAssembledParams()
    {
        return $this->literal->getAssembledParams();
    }

    public function getRoute(): string
    {
        return $this->route;
    }

    private function inner(Request $request): RouteInterface
    {
        $asSegment = method_exists($request, 'getQuery') && $request->getQuery('as') === 'segment';

        return $asSegment ? $this->segment : $this->literal;
    }
}
