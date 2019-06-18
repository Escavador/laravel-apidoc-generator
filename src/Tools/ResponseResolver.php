<?php

namespace Mpociot\ApiDoc\Tools;

use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Mpociot\ApiDoc\Tools\ResponseStrategies\ResponseTagStrategy;
use Mpociot\ApiDoc\Tools\ResponseStrategies\ResponseCallStrategy;
use Mpociot\ApiDoc\Tools\ResponseStrategies\ResponseFileStrategy;
use Mpociot\ApiDoc\Tools\ResponseStrategies\TransformerTagsStrategy;
use Mpociot\ApiDoc\Tools\ResponseStrategies\ResponsePdfFileStrategy;


class ResponseResolver
{
    /**
     * @var array
     */
    public static $strategies = [
        ResponseTagStrategy::class,
        TransformerTagsStrategy::class,
        ResponseFileStrategy::class,
        ResponseCallStrategy::class,
        ResponsePdfFileStrategy::class,
    ];

    /**
     * @var Route
     */
    private $route;

    /**
     * @param Route $route
     */
    public function __construct(Route $route)
    {
        $this->route = $route;
    }

    /**
     * @param array $tags
     * @param array $routeProps
     *
     * @return array|null
     */
    private function resolve(array $tags, array $routeProps)
    {
        foreach (static::$strategies as $strategy) {
            $strategy = new $strategy();

            /** @var Response[]|null $response */
            $responses = $strategy($this->route, $tags, $routeProps);

            if (! is_null($responses)) {
                return array_map(function (Response $response) {
                    return ['status' => $response->getStatusCode(), 'content' => $this->getResponseContent($response), 'comment' => $this->getResponseComment($response), 'content-type' => $this->getResponseContentType($response),];
                }, $responses);
            }
        }
    }

    /**
     * @param $route
     * @param $tags
     * @param $routeProps
     *
     * @return array
     */
    public static function getResponse($route, $tags, $routeProps)
    {
        return (new static($route))->resolve($tags, $routeProps);
    }

    /**
     * @param $response
     *
     * @return mixed
     */
    private function getResponseContent($response)
    {
        return $response ? $response->getContent() : '';
    }

    /**
     * @param $response
     *
     * @return mixed
     */
    private function getResponseContentType($response)
    {
        return $response ? $response->headers->get('content-type') : '';
    }
  
    /**
     * @param $response
     *
     * @return mixed
     */
    private function getResponseComment($response)
    {
        return $response ? $response->headers->get('comment') : '';
    }
}
