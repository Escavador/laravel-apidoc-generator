<?php

namespace Mpociot\ApiDoc\Writing;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Mni\FrontYAML\Parser;
use Mpociot\ApiDoc\Tools\DocumentationConfig;
use Mpociot\Documentarian\Documentarian;
use ReflectionClass;
use Windwalker\Renderer\BladeRenderer;

class Writer
{
    /**
     * @var Command
     */
    protected $output;

    /**
     * @var DocumentationConfig
     */
    private $config;

    /**
     * @var string
     */
    private $baseUrl;

    /**
     * @var bool
     */
    private $forceIt;

    /**
     * @var bool
     */
    private $shouldGeneratePostmanCollection = true;

    /**
     * @var Documentarian
     */
    private $documentarian;

    /**
     * @var bool
     */
    private $isStatic;

    /**
     * @var string
     */
    private $sourceOutputPath;

    /**
     * @var string
     */
    private $outputPath;

    public function __construct(Command $output, DocumentationConfig $config = null, bool $forceIt = false)
    {
        // If no config is injected, pull from global
        $this->config = $config ?: new DocumentationConfig(config('apidoc'));
        $this->baseUrl = $this->config->get('base_url') ?? config('app.url');
        $this->forceIt = $forceIt;
        $this->output = $output;
        $this->shouldGeneratePostmanCollection = $this->config->get('postman.enabled', false);
        $this->documentarian = new Documentarian();
        $this->isStatic = $this->config->get('type') === 'static';
        $this->sourceOutputPath = 'resources/docs';
        $this->outputPath = $this->isStatic ? ($this->config->get('output_folder') ?? 'public/docs') : 'resources/views/apidoc';
    }

    public function writeDocs(Collection $routes)
    {
        // The source files (index.md, js/, css/, and images/) always go in resources/docs/source.
        // The static assets (js/, css/, and images/) always go in public/docs/.
        // For 'static' docs, the output files (index.html, collection.json) go in public/docs/.
        // For 'laravel' docs, the output files (index.blade.php, collection.json)
        // go in resources/views/apidoc/ and storage/app/apidoc/ respectively.

        $this->writeMarkdownAndSourceFiles($routes);

        $this->writeHtmlDocs();

        $this->writePostmanCollection($routes);
    }

    /**
     * @param  Collection $parsedRoutes
     *
     * @return void
     */
    public function writeMarkdownAndSourceFiles(Collection $parsedRoutes)
    {
        $targetFile = $this->sourceOutputPath . '/source/index.md';
        $compareFile = $this->sourceOutputPath . '/source/.compare.md';

        $infoText = view('apidoc::partials.info')
            ->with('outputPath', 'docs')
            ->with('showPostmanCollectionButton', $this->shouldGeneratePostmanCollection);

        $settings = ['languages' => $this->config->get('example_languages')];
        $apiVersions = $this->config->get('api_versions', []);
        if (! is_array($apiVersions)) {
            $apiVersions = [];
        }

        $sections = $parsedRoutes->keys()->filter(function ($groupName) {
            return is_string($groupName) && trim($groupName) !== '';
        })->values()->all();

        // Generate Markdown for each route
        $parsedRouteOutput = $this->generateMarkdownOutputForEachRoute($parsedRoutes, $settings);

        $frontmatter = view('apidoc::partials.frontmatter')
            ->with('settings', $settings)
            ->with('apiVersions', $apiVersions)
            ->with('sections', $sections);

        /*
         * If the target file already exists,
         * we check if the documentation was modified
         * and skip the modified parts of the routes.
         */
        if (file_exists($targetFile) && file_exists($compareFile)) {
            $generatedDocumentation = file_get_contents($targetFile);
            $compareDocumentation = file_get_contents($compareFile);

            $parsedRouteOutput->transform(function (Collection $routeGroup) use ($generatedDocumentation, $compareDocumentation) {
                return $routeGroup->transform(function (array $route) use ($generatedDocumentation, $compareDocumentation) {
                    if (preg_match('/<!-- START_' . $route['id'] . ' -->(.*)<!-- END_' . $route['id'] . ' -->/is', $generatedDocumentation, $existingRouteDoc)) {
                        $routeDocumentationChanged = (preg_match('/<!-- START_' . $route['id'] . ' -->(.*)<!-- END_' . $route['id'] . ' -->/is', $compareDocumentation, $lastDocWeGeneratedForThisRoute) && $lastDocWeGeneratedForThisRoute[1] !== $existingRouteDoc[1]);
                        if ($routeDocumentationChanged === false || $this->forceIt) {
                            if ($routeDocumentationChanged) {
                                $this->output->warn('Discarded manual changes for route [' . implode(',', $route['methods']) . '] ' . $route['uri']);
                            }
                        } else {
                            $this->output->warn('Skipping modified route [' . implode(',', $route['methods']) . '] ' . $route['uri']);
                            $route['modified_output'] = $existingRouteDoc[0];
                        }
                    }

                    return $route;
                });
            });
        }

        $prependFileContents = $this->getMarkdownToPrepend();
        $appendFileContents = $this->getMarkdownToAppend();

        $markdown = view('apidoc::documentarian')
            ->with('writeCompareFile', false)
            ->with('frontmatter', $frontmatter)
            ->with('infoText', $infoText)
            ->with('prependMd', $prependFileContents)
            ->with('appendMd', $appendFileContents)
            ->with('outputPath', $this->config->get('output'))
            ->with('showPostmanCollectionButton', $this->shouldGeneratePostmanCollection)
            ->with('parsedRoutes', $parsedRouteOutput);

        $this->output->info('Writing index.md and source files to: ' . $this->sourceOutputPath);

        if (! is_dir($this->sourceOutputPath)) {
            $documentarian = new Documentarian();
            $documentarian->create($this->sourceOutputPath);
        }

        // Write output file
        file_put_contents($targetFile, $markdown);

        // Write comparable markdown file
        $compareMarkdown = view('apidoc::documentarian')
            ->with('writeCompareFile', true)
            ->with('frontmatter', $frontmatter)
            ->with('infoText', $infoText)
            ->with('prependMd', $prependFileContents)
            ->with('appendMd', $appendFileContents)
            ->with('outputPath', $this->config->get('output'))
            ->with('showPostmanCollectionButton', $this->shouldGeneratePostmanCollection)
            ->with('parsedRoutes', $parsedRouteOutput);

        file_put_contents($compareFile, $compareMarkdown);

        $this->output->info('Wrote index.md and source files to: ' . $this->sourceOutputPath);
    }

    public function generateMarkdownOutputForEachRoute(Collection $parsedRoutes, array $settings): Collection
    {
        $parsedRouteOutput = $parsedRoutes->map(function (Collection $routeGroup) use ($settings) {
            return $routeGroup->map(function (array $route) use ($settings) {
                $route = $this->prepareRouteForView($route);

                if (count($route['cleanBodyParameters']) && ! isset($route['headers']['Content-Type'])) {
                    // Set content type if the user forgot to set it
                    $route['headers']['Content-Type'] = 'application/json';
                }

                $hasRequestOptions = ! empty($route['headers']) || ! empty($route['cleanQueryParameters']) || ! empty($route['cleanBodyParameters']);
                $route['output'] = (string) view('apidoc::partials.route')
                    ->with('hasRequestOptions', $hasRequestOptions)
                    ->with('route', $route)
                    ->with('settings', $settings)
                    ->with('baseUrl', $this->baseUrl)
                    ->render();

                return $route;
            });
        });

        return $parsedRouteOutput;
    }

    /**
     * Normalize route keys for backward compatibility with legacy published views.
     */
    protected function prepareRouteForView(array $route): array
    {
        $metadata = $route['metadata'] ?? [];

        $route['id'] = $route['id'] ?? '';
        $route['uri'] = $route['uri'] ?? '';
        $route['boundUri'] = $route['boundUri'] ?? $route['uri'];
        $route['methods'] = (isset($route['methods']) && is_array($route['methods']) && count($route['methods']))
            ? array_values($route['methods'])
            : ['GET'];

        $route['headers'] = isset($route['headers']) && is_array($route['headers']) ? $route['headers'] : [];
        $route['urlParameters'] = isset($route['urlParameters']) && is_array($route['urlParameters']) ? $route['urlParameters'] : [];
        $route['queryParameters'] = isset($route['queryParameters']) && is_array($route['queryParameters']) ? $route['queryParameters'] : [];
        $route['bodyParameters'] = isset($route['bodyParameters']) && is_array($route['bodyParameters']) ? $route['bodyParameters'] : [];
        $route['cleanQueryParameters'] = isset($route['cleanQueryParameters']) && is_array($route['cleanQueryParameters']) ? $route['cleanQueryParameters'] : [];
        $route['cleanBodyParameters'] = isset($route['cleanBodyParameters']) && is_array($route['cleanBodyParameters']) ? $route['cleanBodyParameters'] : [];
        $route['tags'] = isset($route['tags']) && is_array($route['tags']) ? $route['tags'] : [];

        $route['title'] = $route['title'] ?? ($metadata['title'] ?? '');
        $route['description'] = $route['description'] ?? ($metadata['description'] ?? '');
        $route['authenticated'] = $route['authenticated'] ?? ($metadata['authenticated'] ?? false);
        $route['footerDescription'] = $route['footerDescription'] ?? ($metadata['groupDescription'] ?? '');
        $route['showresponse'] = $route['showresponse'] ?? false;

        // Legacy templates may use `uriParameters` and `response` instead of newer keys.
        $route['uriParameters'] = $route['uriParameters'] ?? ($route['urlParameters'] ?? []);
        $route['uriParameters'] = is_array($route['uriParameters']) ? $route['uriParameters'] : [];

        foreach ($route['uriParameters'] as $name => $parameter) {
            if (! is_array($parameter)) {
                $parameter = [];
            }

            $route['uriParameters'][$name] = [
                'type' => $parameter['type'] ?? 'string',
                'description' => $parameter['description'] ?? '',
                'required' => $parameter['required'] ?? false,
            ];
        }

        foreach ($route['queryParameters'] as $name => $parameter) {
            if (! is_array($parameter)) {
                $parameter = [];
            }

            $route['queryParameters'][$name] = [
                'description' => $parameter['description'] ?? '',
                'required' => $parameter['required'] ?? false,
                'value' => $parameter['value'] ?? null,
            ];
        }

        foreach ($route['bodyParameters'] as $name => $parameter) {
            if (! is_array($parameter)) {
                $parameter = [];
            }

            $route['bodyParameters'][$name] = [
                'type' => $parameter['type'] ?? 'string',
                'description' => $parameter['description'] ?? '',
                'required' => $parameter['required'] ?? false,
                'value' => $parameter['value'] ?? null,
            ];
        }

        $responses = $route['response'] ?? ($route['responses'] ?? []);
        if (! is_array($responses)) {
            $responses = [];
        }

        $route['response'] = array_map(function ($response) {
            if (! is_array($response)) {
                return [
                    'status' => 200,
                    'content' => $response,
                    'comment' => '',
                    'content-type' => 'application/json',
                ];
            }

            return [
                'status' => $response['status'] ?? 200,
                'content' => $response['content'] ?? null,
                'comment' => $response['comment'] ?? '',
                'content-type' => $response['content-type'] ?? 'application/json',
            ];
        }, $responses);

        return $route;
    }

    protected function writePostmanCollection(Collection $parsedRoutes): void
    {
        if ($this->shouldGeneratePostmanCollection) {
            $this->output->info('Generating Postman collection');

            $collection = $this->generatePostmanCollection($parsedRoutes);
            if ($this->isStatic) {
                $collectionPath = "{$this->outputPath}/collection.json";
                file_put_contents($collectionPath, $collection);
            } else {
                $storageInstance = Storage::disk($this->config->get('storage'));
                $storageInstance->put('apidoc/collection.json', $collection, 'public');
                if ($this->config->get('storage') == 'local') {
                    $collectionPath = 'storage/app/apidoc/collection.json';
                } else {
                    $collectionPath = $storageInstance->url('collection.json');
                }
            }

            $this->output->info("Wrote Postman collection to: {$collectionPath}");
        }
    }

    /**
     * Generate Postman collection JSON file.
     *
     * @param Collection $routes
     *
     * @return string
     */
    public function generatePostmanCollection(Collection $routes)
    {
        /** @var PostmanCollectionWriter $writer */
        $writer = app()->makeWith(
            PostmanCollectionWriter::class,
            ['routeGroups' => $routes, 'baseUrl' => $this->baseUrl]
        );

        return $writer->getCollection();
    }

    protected function getMarkdownToPrepend(): string
    {
        $prependFile = $this->sourceOutputPath . '/source/prepend.md';
        $prependFileContents = file_exists($prependFile)
            ? file_get_contents($prependFile) . "\n" : '';

        return $prependFileContents;
    }

    protected function getMarkdownToAppend(): string
    {
        $appendFile = $this->sourceOutputPath . '/source/append.md';
        $appendFileContents = file_exists($appendFile)
            ? "\n" . file_get_contents($appendFile) : '';

        return $appendFileContents;
    }

    protected function copyAssetsFromSourceFolderToPublicFolder(): void
    {
        $publicPath = $this->config->get('output_folder') ?? 'public/docs';
        if (! is_dir($publicPath)) {
            mkdir($publicPath, 0777, true);
            mkdir("{$publicPath}/css");
            mkdir("{$publicPath}/js");
        }
        copy("{$this->sourceOutputPath}/js/all.js", "{$publicPath}/js/all.js");
        rcopy("{$this->sourceOutputPath}/images", "{$publicPath}/images");
        rcopy("{$this->sourceOutputPath}/css", "{$publicPath}/css");

        if ($logo = $this->config->get('logo')) {
            copy($logo, "{$publicPath}/images/logo.png");
        }
    }

    protected function moveOutputFromSourceFolderToTargetFolder(): void
    {
        if ($this->isStatic) {
            // Move output (index.html, css/style.css and js/all.js) to public/docs
            rename("{$this->sourceOutputPath}/index.html", "{$this->outputPath}/index.html");
        } else {
            // Move output to resources/views
            if (! is_dir($this->outputPath)) {
                mkdir($this->outputPath);
            }
            rename("{$this->sourceOutputPath}/index.html", "$this->outputPath/index.blade.php");
            $contents = file_get_contents("$this->outputPath/index.blade.php");
            //
            $contents = str_replace('href="css/style.css"', 'href="{{ asset(\'/docs/css/style.css\') }}"', $contents);
            $contents = str_replace('src="js/all.js"', 'src="{{ asset(\'/docs/js/all.js\') }}"', $contents);
            $contents = str_replace('src="images/', 'src="/docs/images/', $contents);
            $contents = preg_replace('#href="https?://.+?/docs/collection.json"#', 'href="{{ route("apidoc.json") }}"', $contents);
            file_put_contents("$this->outputPath/index.blade.php", $contents);
        }
    }

    public function writeHtmlDocs(): void
    {
        $this->output->info('Generating API HTML code');

        try {
            $this->documentarian->generate($this->sourceOutputPath);
        } catch (InvalidArgumentException $exception) {
            // Compatibility fallback for newer windwalker/renderer versions.
            if (strpos($exception->getMessage(), 'View [index] not found.') === false) {
                throw $exception;
            }

            $this->output->warn('Detected renderer compatibility issue. Falling back to internal HTML renderer.');
            $this->generateHtmlWithFallbackRenderer();
        }

        // Move assets to public folder
        $this->copyAssetsFromSourceFolderToPublicFolder();

        $this->moveOutputFromSourceFolderToTargetFolder();

        $this->output->info("Wrote HTML documentation to: {$this->outputPath}");
    }

    protected function generateHtmlWithFallbackRenderer(): void
    {
        $sourceDir = $this->sourceOutputPath . '/source';
        $indexFile = $sourceDir . '/index.md';

        if (! is_dir($sourceDir) || ! file_exists($indexFile)) {
            return;
        }

        $parser = new Parser();
        $document = $parser->parse(file_get_contents($indexFile));
        $frontmatter = $document->getYAML();
        $html = $document->getContent();

        if (isset($frontmatter['includes'])) {
            foreach ($frontmatter['includes'] as $include) {
                $includeFile = $sourceDir . '/includes/_' . $include . '.md';
                if (file_exists($includeFile)) {
                    $includeDocument = $parser->parse(file_get_contents($includeFile));
                    $html .= $includeDocument->getContent();
                }
            }
        }

        $documentarianClassPath = dirname((new ReflectionClass(Documentarian::class))->getFileName());
        $renderer = new BladeRenderer([
            'paths' => [$documentarianClassPath . '/../resources/views'],
            'cache_path' => $sourceDir . '/_tmp',
        ]);

        $output = $renderer->render('index', [
            'page' => $frontmatter,
            'content' => $html,
        ]);

        file_put_contents($this->sourceOutputPath . '/index.html', $output);

        // Match Documentarian::generate() side effects for static assets.
        rcopy($sourceDir . '/assets/images/', $this->sourceOutputPath . '/images');
        rcopy($sourceDir . '/assets/stylus/fonts/', $this->sourceOutputPath . '/css/fonts');
    }
}
