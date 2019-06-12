title: API Reference

language_tabs:
@foreach($settings['languages'] as $language)
- {{ $language }}
@endforeach

includes:
@foreach($sections['sections'] as $section)
- {{ $section }}
@endforeach

search: true

toc_footers:
- <a href='http://github.com/mpociot/documentarian'>Documentation Powered by Documentarian</a>