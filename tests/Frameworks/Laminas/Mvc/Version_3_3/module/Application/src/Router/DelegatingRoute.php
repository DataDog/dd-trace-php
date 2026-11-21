<?php

declare(strict_types=1);

namespace Application\Router;

use Laminas\Router\Http\RouteInterface;
use Laminas\Router\Http\Segment;
use Laminas\Stdlib\RequestInterface as Request;

/**
 * A route type laminas-router does not ship: it exposes its definition through
 * getRoute() and delegates matching to a Segment it wraps, returning that
 * Segment's RouteMatch.
 */
class DelegatingRoute implements RouteInterface
{
    /** @var string */
    private $route;

    /** @var Segment */
    private $inner;

    public function __construct(string $route, array $defaults = [])
    {
        $this->route = $route;
        $this->inner = new Segment($route, [], $defaults);
    }

    public static function factory($options = [])
    {
        return new static($options['route'], $options['defaults'] ?? []);
    }

    public function match(Request $request, $pathOffset = null)
    {
        return $this->inner->match($request, $pathOffset);
    }

    public function assemble(array $params = [], array $options = [])
    {
        return $this->inner->assemble($params, $options);
    }

    public function getAssembledParams()
    {
        return $this->inner->getAssembledParams();
    }

    public function getRoute(): string
    {
        return $this->route;
    }
}
