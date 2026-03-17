<?php

namespace Mpociot\ApiDoc\Matching\RouteMatcher;

use Illuminate\Routing\Route;

class RouteMatch implements \ArrayAccess
{
    /**
     * @var Route
     */
    protected $route;

    /**
     * @var array
     */
    protected $rules;

    /**
     * @param Route $route
     * @param array $applyRules
     */
    public function __construct(Route $route, array $applyRules)
    {
        $this->route = $route;
        $this->rules = $applyRules;
    }

    /**
     * @return Route
     */
    public function getRoute()
    {
        return $this->route;
    }

    /**
     * @return array
     */
    public function getRules()
    {
        return $this->rules;
    }

    public function offsetExists($offset)
    {
        return is_callable([$this, 'get' . ucfirst($offset)]);
    }

    public function offsetGet($offset)
    {
        return call_user_func([$this, 'get' . ucfirst($offset)]);
    }

    public function offsetSet($offset, $value)
    {
        return $this->$offset = $value;
    }

    public function offsetUnset($offset)
    {
        $this->$offset = null;
    }
}
