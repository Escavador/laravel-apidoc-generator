<?php

namespace Mpociot\ApiDoc\Matching;

use Mpociot\ApiDoc\Matching\RouteMatcher\RouteMatch;

interface RouteMatcherInterface
{
    /**
     * Resolve matched routes that should be documented.
     *
     * @param array $routeRules Route rules defined under the "routes" section in config
     * @param string $router
     *
     * @return RouteMatch[]
     */
    public function getRoutes(array $routeRules = [], string $router = 'laravel');
}
