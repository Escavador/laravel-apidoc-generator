<?php

namespace Mpociot\ApiDoc\Matching\RouteMatcher;

use Illuminate\Routing\Route;

class MatchedRoute implements \ArrayAccess
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
     * MatchedRoute constructor.
     *
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

    /**
     * {@inheritdoc}
     */
    public function offsetExists(mixed $offset): bool
    {
        return is_callable([$this, 'get' . ucfirst($offset)]);
    }

    /**
     * {@inheritdoc}
     */
    public function offsetGet(mixed $offset): mixed
    {
        return call_user_func([$this, 'get' . ucfirst($offset)]);
    }

    /**
     * {@inheritdoc}
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->$offset = $value;
    }

    /**
     * {@inheritdoc}
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->$offset = null;
    }
}
