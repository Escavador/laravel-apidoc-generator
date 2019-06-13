# Info

Welcome to the generated API reference.
@if($showPostmanCollectionButton)
[Get Postman Collection]({{url($outputPath.'/collection.json')}})
@endif
@foreach($styles as $style)
<link rel="stylesheet" href="css/{{ basename($style) }}">
@endforeach

@foreach($scripts as $script)
<script lang="js" src="js/{{ basename($script) }}"></script>
@endforeach