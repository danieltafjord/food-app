<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts'])
        @php
            $seo = $page['props']['seo'] ?? null;
        @endphp
        @if ($seo)
            <meta name="description" content="{{ $seo['description'] }}">
            <link rel="canonical" href="{{ $seo['canonical'] }}">
            @foreach ($seo['alternates'] as $language => $url)
                <link rel="alternate" hreflang="{{ $language }}" href="{{ $url }}">
            @endforeach
            <meta property="og:type" content="website">
            <meta property="og:site_name" content="Handlelista">
            <meta property="og:title" content="{{ $seo['title'] }}">
            <meta property="og:description" content="{{ $seo['description'] }}">
            <meta property="og:url" content="{{ $seo['canonical'] }}">
            <meta property="og:locale" content="{{ app()->getLocale() === 'no' ? 'nb_NO' : 'en_GB' }}">
            <meta property="og:image" content="{{ asset('og-image.png') }}">
            <meta property="og:image:width" content="1200">
            <meta property="og:image:height" content="630">
            <meta name="twitter:card" content="summary_large_image">
            @php
                $structuredData = json_encode([
                    '@context' => 'https://schema.org',
                    '@type' => 'SoftwareApplication',
                    'name' => 'Handlelista',
                    'description' => $seo['description'],
                    'url' => $seo['canonical'],
                    'image' => asset('og-image.png'),
                    'inLanguage' => app()->getLocale(),
                    'applicationCategory' => 'LifestyleApplication',
                    'operatingSystem' => 'iOS',
                    'author' => ['@type' => 'Person', 'name' => config('handlelista.contact.operatorName')],
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
            @endphp
            <script type="application/ld+json">{!! $structuredData !!}</script>
        @else
            <meta name="robots" content="noindex">
        @endif
        <x-inertia::head>
            <title>{{ $seo['title'] ?? config('app.name', 'Handlelista') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
