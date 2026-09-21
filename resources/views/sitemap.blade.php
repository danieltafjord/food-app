{!! '<'.'?xml version="1.0" encoding="UTF-8"?'.'>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
@foreach ($pages as $alternates)
@foreach (collect($alternates)->except('x-default') as $url)
    <url>
        <loc>{{ $url }}</loc>
@foreach ($alternates as $language => $alternateUrl)
        <xhtml:link rel="alternate" hreflang="{{ $language }}" href="{{ $alternateUrl }}"/>
@endforeach
    </url>
@endforeach
@endforeach
</urlset>
