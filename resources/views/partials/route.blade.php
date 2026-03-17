<!-- START_{{ $route['id'] }} -->
@php
  $metadata = $route['metadata'] ?? [];
  $title = $metadata['title'] ?? '';
  $authenticated = (bool) ($metadata['authenticated'] ?? false);
  $description = $metadata['description'] ?? '';
  $methods = $route['methods'] ?? [];
  $uri = $route['uri'] ?? '';
  $responses = $route['responses'] ?? [];
  $urlParameters = $route['urlParameters'] ?? [];
  $queryParameters = $route['queryParameters'] ?? [];
  $bodyParameters = $route['bodyParameters'] ?? [];
@endphp

@if ($title != '')
  ## {{ $title }}
@else## {{ $uri }}
@endif
@if ($authenticated)
  <br><small
    style="padding: 1px 9px 2px;font-weight: bold;white-space: nowrap;color: #ffffff;-webkit-border-radius: 9px;-moz-border-radius: 9px;border-radius: 9px;background-color: #3a87ad;">Requires
    authentication</small>
@endif
@if ($description)
  {!! $description !!}
@endif

> Example request:

@foreach ($settings['languages'] as $language)
  @include("apidoc::partials.example-requests.$language")
@endforeach

@if (in_array('GET', $methods) || (isset($route['showresponse']) && $route['showresponse']))
  @foreach ($responses as $response)
    > Example response ({{ $response['status'] }}):

    ```json
    @if (is_object($response['content']) || is_array($response['content']))
      {!! json_encode($response['content'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) !!}
    @else
      {!! json_encode(json_decode($response['content']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) !!}
    @endif
    ```
  @endforeach
@endif

### HTTP Request
@foreach ($methods as $method)
  `{{ $method }} {{ $uri }}`
@endforeach
@if (count($urlParameters))
  #### URL Parameters

  Parameter | Status | Description
  --------- | ------- | ------- | -------
  @foreach ($urlParameters as $attribute => $parameter)
    `{{ $attribute }}` | @if ($parameter['required'])
      required
    @else
      optional
    @endif | {!! $parameter['description'] !!}
  @endforeach
@endif
@if (count($queryParameters))
  #### Query Parameters

  Parameter | Status | Description
  --------- | ------- | ------- | -----------
  @foreach ($queryParameters as $attribute => $parameter)
    `{{ $attribute }}` | @if ($parameter['required'])
      required
    @else
      optional
    @endif | {!! $parameter['description'] !!}
  @endforeach
@endif
@if (count($bodyParameters))
  #### Body Parameters
  Parameter | Type | Status | Description
  --------- | ------- | ------- | ------- | -----------
  @foreach ($bodyParameters as $attribute => $parameter)
    `{{ $attribute }}` | {{ $parameter['type'] }} | @if ($parameter['required'])
      required
    @else
      optional
    @endif | {!! $parameter['description'] !!}
  @endforeach
@endif

<!-- END_{{ $route['id'] }} -->
