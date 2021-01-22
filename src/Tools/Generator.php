<?php

namespace Mpociot\ApiDoc\Tools;

use Escavador\Vespa\Models\DocumentDefinition;
use Faker\Factory;
use ReflectionClass;
use ReflectionMethod;
use Illuminate\Support\Str;
use Illuminate\Routing\Route;
use Mpociot\Reflection\DocBlock;
use Mpociot\Reflection\DocBlock\Tag;
use Mpociot\ApiDoc\Tools\Traits\ParamHelpers;

class Generator
{
    use ParamHelpers;

    /**
     * @var DocumentationConfig
     */
    private $config;

    public function __construct(DocumentationConfig $config = null)
    {
        // If no config is injected, pull from global
        $this->config = $config ?: new DocumentationConfig(config('apidoc'));
    }

    /**
     * @param Route $route
     *
     * @return mixed
     */
    public function getUri(Route $route)
    {
        return $route->uri();
    }

    /**
     * @param Route $route
     *
     * @return mixed
     */
    public function getMethods(Route $route)
    {
        return array_diff($route->methods(), ['HEAD']);
    }

    /**
     * @param \Illuminate\Routing\Route $route
     * @param array $rulesToApply Rules to apply when generating documentation for this route
     *
     * @return array
     */
    public function processRoute(Route $route, array $rulesToApply = [])
    {
        list($class, $method) = Utils::getRouteActionUses($route->getAction());
        $controller = new ReflectionClass($class);
        $method = $controller->getMethod($method);

        $docBlock = $this->parseDocBlock($method);
        list($routeGroupName, $routeGroupDescription, $routeTitle) = $this->getRouteGroup($controller, $docBlock);
        $uriParameters = $this->getUriParameters($method, $docBlock['tags']);
        $bodyParameters = $this->getBodyParameters($method, $docBlock['tags']);
        $queryParameters = $this->getQueryParameters($method, $docBlock['tags']);
        $content = ResponseResolver::getResponse($route, $docBlock['tags'], [
            'rules' => $rulesToApply,
            'body' => $bodyParameters,
            'query' => $queryParameters,
        ]);

        $parts = explode('#@footer@#', $docBlock['long']);
        if (count($parts) > 1) {
            list($description, $footerDescription) = $parts;
        } else {
            $description = $parts[0];
            $footerDescription = '';
        }

        $parsedRoute = [
            'id' => md5($this->getUri($route) . ':' . implode($this->getMethods($route))),
            'groupName' => $routeGroupName,
            'groupDescription' => $routeGroupDescription,
            'title' => $routeTitle ?: $docBlock['short'],
            'description' => $description ?: $docBlock['long'],
            'footerDescription' => $footerDescription,
            'methods' => $this->getMethods($route),
            'uri' => $this->getUri($route),
            'boundUri' => Utils::getFullUrl($route, $rulesToApply['bindings'] ?? ($rulesToApply['response_calls']['bindings'] ?? [])),
            'uriParameters' => $uriParameters,
            'queryParameters' => $queryParameters,
            'bodyParameters' => $bodyParameters,
            'cleanUriParameters' => $this->cleanParams($uriParameters),
            'cleanBodyParameters' => $this->cleanParams($bodyParameters),
            'cleanQueryParameters' => $this->cleanParams($queryParameters),
            'authenticated' => $this->getAuthStatusFromDocBlock($docBlock['tags']),
            'response' => $content,
            'showresponse' => !empty($content),
        ];
        $parsedRoute['headers'] = $rulesToApply['headers'] ?? [];

        return $parsedRoute;
    }

    /**
     * @param ReflectionMethod $method
     * @param array $tags
     *
     * @return array
     */
    protected function getUriParameters(ReflectionMethod $method, array $tags)
    {
        foreach ($method->getParameters() as $param) {
            $paramType = $param->getType();
            if ($paramType === null) {
                continue;
            }

            $parameterClassName = version_compare(phpversion(), '7.1.0', '<')
                ? $paramType->__toString()
                : $paramType->getName();

            try {
                $parameterClass = new ReflectionClass($parameterClassName);
            } catch (\ReflectionException $e) {
                continue;
            }

            if (class_exists('\Illuminate\Foundation\Http\FormRequest') && $parameterClass->isSubclassOf(\Illuminate\Foundation\Http\FormRequest::class) || class_exists('\Dingo\Api\Http\FormRequest') && $parameterClass->isSubclassOf(\Dingo\Api\Http\FormRequest::class)) {
                $formRequestDocBlock = new DocBlock($parameterClass->getDocComment());
                $uriParametersFromDocBlock = $this->getUriParametersFromDocBlock($formRequestDocBlock->getTags());

                if (count($uriParametersFromDocBlock)) {
                    return $uriParametersFromDocBlock;
                }
            }
        }

        return $this->getUriParametersFromDocBlock($tags);
    }

    /**
     * @param array $tags
     *
     * @return array
     */
    protected function getUriParametersFromDocBlock(array $tags)
    {
        $parameters = collect($tags)
            ->filter(function ($tag) {
                return $tag instanceof Tag && $tag->getName() === 'uriParam';
            })
            ->mapWithKeys(function ($tag) {
                preg_match('/(.+?)\s+(.+?)\s+?(.*)/', $tag->getContent(), $content);
                if (empty($content)) {
                    // this means only name and type were supplied
                    list($name, $type) = preg_split('/\s+/', $tag->getContent());
                    $required = false;
                    $description = '';
                } else {
                    list($_, $name, $type, $description) = $content;
                    $description = trim($description);
                }

                $type = $this->normalizeParameterType($type);
                list($description, $example) = $this->parseDescription($description, $type);
                $value = is_null($example) ? $this->generateDummyValue($type) : $example;

                return [$name => compact('type', 'description', 'value')];
            })->toArray();

        return $parameters;
    }

    protected function getBodyParameters(ReflectionMethod $method, array $tags)
    {
        foreach ($method->getParameters() as $param) {
            $paramType = $param->getType();
            if ($paramType === null) {
                continue;
            }

            $parameterClassName = version_compare(phpversion(), '7.1.0', '<')
                ? $paramType->__toString()
                : $paramType->getName();

            try {
                $parameterClass = new ReflectionClass($parameterClassName);
            } catch (\ReflectionException $e) {
                continue;
            }

            if (class_exists('\Illuminate\Foundation\Http\FormRequest') && $parameterClass->isSubclassOf(\Illuminate\Foundation\Http\FormRequest::class) || class_exists('\Dingo\Api\Http\FormRequest') && $parameterClass->isSubclassOf(\Dingo\Api\Http\FormRequest::class)) {
                $formRequestDocBlock = new DocBlock($parameterClass->getDocComment());
                $bodyParametersFromDocBlock = $this->getBodyParametersFromDocBlock($formRequestDocBlock->getTags());

                if (count($bodyParametersFromDocBlock)) {
                    return $bodyParametersFromDocBlock;
                }
            }
        }

        return $this->getBodyParametersFromDocBlock($tags);
    }

    /**
     * @param array $tags
     *
     * @return array
     */
    protected function getBodyParametersFromDocBlock(array $tags)
    {
        $parameters = collect($tags)
            ->filter(function ($tag) {
                return $tag instanceof Tag && $tag->getName() === 'bodyParam';
            })
            ->mapWithKeys(function ($tag) {
                preg_match('/(.+?)\s+(.+?)\s+(required\s+)?(.*)/s', $tag->getContent(), $content);
                $content = preg_replace('/\s?No-example.?/', '', $content);
                if (empty($content)) {
                    // this means only name and type were supplied
                    list($name, $type) = preg_split('/\s+/', $tag->getContent());
                    $required = false;
                    $description = '';
                } else {
                    list($_, $name, $type, $required, $description) = $content;
                    $description = str_replace(["\n", "\r"], ' ', $description);
                    $description = trim($description);
                    if ($description == 'required' && empty(trim($required))) {
                        $required = $description;
                        $description = '';
                    }
                    $required = trim($required) == 'required' ? true : false;
                }

                $type = $this->normalizeParameterType($type);
                list($description, $example) = $this->parseDescription($description, $type);
                $value = is_null($example) && !$this->shouldExcludeExample($tag) ? $this->generateDummyValue($type) : $example;

                return [$name => compact('type', 'description', 'required', 'value')];
            })->toArray();

        return $parameters;
    }

    /**
     * @param ReflectionMethod $method
     * @param array $tags
     *
     * @return array
     */
    protected function getQueryParameters(ReflectionMethod $method, array $tags)
    {
        foreach ($method->getParameters() as $param) {
            $paramType = $param->getType();
            if ($paramType === null) {
                continue;
            }

            $parameterClassName = version_compare(phpversion(), '7.1.0', '<')
                ? $paramType->__toString()
                : $paramType->getName();

            try {
                $parameterClass = new ReflectionClass($parameterClassName);
            } catch (\ReflectionException $e) {
                continue;
            }

            if (class_exists('\Illuminate\Foundation\Http\FormRequest') && $parameterClass->isSubclassOf(\Illuminate\Foundation\Http\FormRequest::class) || class_exists('\Dingo\Api\Http\FormRequest') && $parameterClass->isSubclassOf(\Dingo\Api\Http\FormRequest::class)) {
                $formRequestDocBlock = new DocBlock($parameterClass->getDocComment());
                $queryParametersFromDocBlock = $this->getQueryParametersFromDocBlock($formRequestDocBlock->getTags());

                if (count($queryParametersFromDocBlock)) {
                    return $queryParametersFromDocBlock;
                }
            }
        }

        return $this->getQueryParametersFromDocBlock($tags);
    }

    /**
     * @param array $tags
     *
     * @return array
     */
    protected function getQueryParametersFromDocBlock(array $tags)
    {
        $parameters = collect($tags)
            ->filter(function ($tag) {
                return $tag instanceof Tag && $tag->getName() === 'queryParam';
            })
            ->mapWithKeys(function ($tag) {
                preg_match('/(.+?)\s+(required\s+)?(.*)/', $tag->getContent(), $content);
                $content = preg_replace('/\s?No-example.?/', '', $content);
                if (empty($content)) {
                    // this means only name was supplied
                    list($name) = preg_split('/\s+/', $tag->getContent());
                    $required = false;
                    $description = '';
                } else {
                    list($_, $name, $required, $description) = $content;
                    $description = trim($description);
                    if ($description == 'required' && empty(trim($required))) {
                        $required = $description;
                        $description = '';
                    }
                    $required = trim($required) == 'required' ? true : false;
                }

                list($description, $value) = $this->parseDescription($description, 'string');
                if (is_null($value) && !$this->shouldExcludeExample($tag)) {
                    $value = Str::contains($description, ['number', 'count', 'page'])
                        ? $this->generateDummyValue('integer')
                        : $this->generateDummyValue('string');
                }

                return [$name => compact('description', 'required', 'value')];
            })->toArray();

        return $parameters;
    }

    /**
     * @param array $tags
     *
     * @return bool
     */
    protected function getAuthStatusFromDocBlock(array $tags)
    {
        $authTag = collect($tags)
            ->first(function ($tag) {
                return $tag instanceof Tag && strtolower($tag->getName()) === 'authenticated';
            });

        return (bool) $authTag;
    }

    /**
     * @param ReflectionMethod $method
     *
     * @return array
     */
    protected function parseDocBlock(ReflectionMethod $method)
    {
        $comment = $method->getDocComment();
        $phpdoc = new DocBlock($comment);

        return [
            'short' => $phpdoc->getShortDescription(),
            'long' => $phpdoc->getLongDescription()->getContents(),
            'tags' => $phpdoc->getTags(),
        ];
    }

    /**
     * @param ReflectionClass $controller
     * @param array $methodDocBlock
     *
     * @return array The route group name, the group description, ad the route title
     */
    protected function getRouteGroup(ReflectionClass $controller, array $methodDocBlock)
    {
        // @group tag on the method overrides that on the controller
        if (!empty($methodDocBlock['tags'])) {
            foreach ($methodDocBlock['tags'] as $tag) {
                if ($tag->getName() === 'group') {
                    $routeGroupParts = explode("\n", trim($tag->getContent()));
                    $routeGroupName = array_shift($routeGroupParts);
                    $routeGroupDescription = trim(implode("\n", $routeGroupParts));

                    // If the route has no title (aka "short"),
                    // we'll assume the routeGroupDescription is actually the title
                    // Something like this:
                    // /**
                    //   * Fetch cars. <-- This is route title.
                    //   * @group Cars <-- This is group name.
                    //   * APIs for cars. <-- This is group description (not required).
                    //   **/
                    // VS
                    // /**
                    //   * @group Cars <-- This is group name.
                    //   * Fetch cars. <-- This is route title, NOT group description.
                    //   **/

                    // BTW, this is a spaghetti way of doing this.
                    // It shall be refactored soon. Deus vult!💪
                    if (empty($methodDocBlock['short'])) {
                        return [$routeGroupName, '', $routeGroupDescription];
                    }

                    return [$routeGroupName, $routeGroupDescription, $methodDocBlock['short']];
                }
            }
        }

        $docBlockComment = $controller->getDocComment();
        if ($docBlockComment) {
            $phpdoc = new DocBlock($docBlockComment);
            foreach ($phpdoc->getTags() as $tag) {
                if ($tag->getName() === 'group') {
                    $routeGroupParts = explode("\n", trim($tag->getContent()));
                    $routeGroupName = array_shift($routeGroupParts);
                    $routeGroupDescription = implode("\n", $routeGroupParts);

                    return [$routeGroupName, $routeGroupDescription, $methodDocBlock['short']];
                }
            }
        }

        return [$this->config->get(('default_group')), '', $methodDocBlock['short']];
    }

    private function normalizeParameterType($type)
    {
        $typeMap = [
            'int' => 'integer',
            'bool' => 'boolean',
            'double' => 'float',
        ];

        return $type ? ($typeMap[$type] ?? $type) : 'string';
    }

    private function generateDummyValue(string $type)
    {
        $faker = Factory::create();
        if ($this->config->get('faker_seed')) {
            $faker->seed($this->config->get('faker_seed'));
        }
        $fakeFactories = [
            'integer' => function () use ($faker) {
                return $faker->numberBetween(1, 20);
            },
            'number' => function () use ($faker) {
                return $faker->randomFloat();
            },
            'float' => function () use ($faker) {
                return $faker->randomFloat();
            },
            'boolean' => function () use ($faker) {
                return $faker->boolean();
            },
            'string' => function () use ($faker) {
                return $faker->word;
            },
            'array' => function () {
                return [];
            },
            'object' => function () {
                return new \stdClass;
            },
        ];

        $fakeFactory = $fakeFactories[$type] ?? $fakeFactories['string'];

        return $fakeFactory();
    }

    /**
     * Allows users to specify an example for the parameter by writing 'Example: the-example',
     * to be used in example requests and response calls.
     *
     * @param string $description
     * @param string $type The type of the parameter. Used to cast the example provided, if any.
     *
     * @return array The description and included example.
     */
    private function parseDescription(string $description, string $type)
    {
        $example = null;
        if (preg_match('/(.*)\s+Example:\s*(.*)\s*/', $description, $content)) {
            $description = $content[1];

            // examples are parsed as strings by default, we need to cast them properly
            $example = $this->castToType($content[2], $type);
        }

        return [$description, $example];
    }

    /**
     * Allows users to specify that we shouldn't generate an example for the parameter
     * by writing 'No-example'.
     *
     * @param Tag $tag
     *
     * @return bool Whether no example should be generated
     */
    private function shouldExcludeExample(Tag $tag)
    {
        return strpos($tag->getContent(), ' No-example') !== false;
    }

    /**
     * Cast a value from a string to a specified type.
     *
     * @param string $value
     * @param string $type
     *
     * @return mixed
     */
    private function castToType(string $value, string $type)
    {
        $casts = [
            'int' => 'intval',
            'integer' => 'intval',
            'number' => 'floatval',
            'float' => 'floatval',
            'boolean' => 'boolval',
        ];

        $type = str_replace(' ', '', $type);

        // First, we handle booleans. We can't use a regular cast,
        //because PHP considers string 'false' as true.
        if ($value == 'false' && $type == 'boolean') {
            return false;
        }

        if (isset($casts[$type])) {
            return $casts[$type]($value);
        }
        // In case of type be an array, converts the string in an array,
        // preserving the type.
        elseif (strrpos($type, '[]') > 0) {
            $type = substr($type, 0, strlen($type) - 2);
            return $this->stringToArray($type, $value);
        }

        return "$value";
    }

    private static function isTypeArray($type)
    {
        if (strrpos($type, '[]') > 0) {
            return true;
        }

        return false;
    }

    private function normalizeStringToArray($value)
    {
        $value = str_replace(' => ', '=>', $value);
        $value = str_replace(' [', '[', $value);
        $value = str_replace('[ ', '[', $value);
        $value = str_replace(' \'', '\'', $value);
        $value = str_replace(' \"', '\"', $value);
        $value = str_replace(', ', ',', $value);
        $value = str_replace(' ,', ',', $value);
        $value = str_replace(' ]', ']', $value);
        $value = str_replace('] ', ']', $value);

        return $value;
    }

    private function stringToArray(string $type, $value)
    {
        $result = [];

        $value = $this->normalizeStringToArray($value);
        $type = str_replace(' ', '', $type);

        if (Generator::isTypeArray($type)) {
            $value = substr($value, 0, strlen($value) - 1);
            $value = substr($value, 1, strlen($value));
            $type = substr($type, 0, strlen($type) - 2);

            $pieces = explode('],', $value);
            $index = 0;
            foreach ($pieces as $piece) {
                // remove um [] do type
                if (strpos($type, "[]") !== false) {
                    $type = substr($type, 0, strlen($type) - 2);
                }

                // remove [
                if ($index == 0 && $piece[0] = "[") {
                    $piece = substr($piece, 1, strlen($piece));
                }
                // remove ]
                elseif ($index == count($pieces) - 1 && $piece[strlen($piece) - 1] = "]") {
                    $piece = substr($piece, 0, strlen($piece) - 1);
                }

                $result[] = Generator::stringToArray($type, $piece);
            }
        } else {
            $pieces = explode(',', $value);

            foreach ($pieces as $index => $piece) {
                if ($piece[0] == "[") {
                    $piece = substr($piece, 1, strlen($piece));
                } elseif ($piece[strlen($piece) - 1] == "]") {
                    $piece = substr($piece, 0, strlen($piece) - 1);
                }

                if (strpos($piece, "=>") !== false) {
                    $keyValue = explode("=>", $piece);
                    $key = $keyValue[0];
                    $content = $keyValue[1];


                    if ($key[0] == "'" && $key[strlen($key) - 1] == "'") {
                        $key = substr($key, 1, strlen($key) - 2);
                    }

                    if ($content[0] == "'" && $content[strlen($content) - 1] == "'") {
                        $content = substr($content, 1, strlen($content) - 2);
                    }

                    if ($content[strlen($content) - 1] == "]") {
                        $content = substr($content, 0, strlen($content) - 1);
                    }
                } else {
                    $key = $index;
                    if ($piece[0] == "'" && $piece[strlen($piece) - 1] == "'") {
                        $content = substr($piece, 1, strlen($piece) - 2);
                    } else {
                        $content = $piece;
                    }
                }

                $result[$key] = $this->castToType($content, $type);
            }
        }

        return $result;
    }

    public static function printArray($array)
    {
        $result = "[";
        $index = 0;

        foreach ($array as $key => $item) {
            $type = gettype($item);
            if ($type == 'array') {
                if (gettype($key) == 'integer') {
                    $result .= Generator::printArray($item);
                } else {
                    $result .= "'$key' => " . Generator::printArray($item);
                }
            } else {
                if (!is_numeric($item)) {
                    $item = "'$item'";
                }

                if (gettype($key) == 'integer') {
                    $result .= "$item";
                } else {
                    $result .= "'$key' => $item";
                }
            }

            if ($index < count($array) - 1) {
                $result .= ", ";
            }

            $index++;
        }

        return $result . "]";
    }
}
