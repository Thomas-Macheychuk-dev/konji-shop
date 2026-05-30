@php
    $appName = config('app.name', 'Laravel');

    $pageTitle = $seoTitle ?? $title ?? null;
    $fullTitle = filled($pageTitle)
        ? $pageTitle.' - '.$appName
        : $appName;

    $description = $seoDescription ?? null;
    $canonical = $canonicalUrl ?? url()->current();

    $ogTitle = $openGraphTitle ?? $pageTitle ?? $appName;
    $ogDescription = $openGraphDescription ?? $description;
    $ogImage = $openGraphImage ?? null;
    $ogType = $openGraphType ?? 'website';

    $structuredDataItems = collect($structuredData ?? [])
        ->filter(fn ($item) => is_array($item) && $item !== [])
        ->values();
@endphp

<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="csrf-token" content="{{ csrf_token() }}" />

<title>{{ $fullTitle }}</title>

@if (filled($description))
    <meta name="description" content="{{ $description }}">
@endif

<link rel="canonical" href="{{ $canonical }}">

<meta property="og:title" content="{{ $ogTitle }}">
<meta property="og:type" content="{{ $ogType }}">
<meta property="og:url" content="{{ $canonical }}">

@if (filled($ogDescription))
    <meta property="og:description" content="{{ $ogDescription }}">
@endif

@if (filled($ogImage))
    <meta property="og:image" content="{{ $ogImage }}">
@endif

<meta name="twitter:card" content="{{ filled($ogImage) ? 'summary_large_image' : 'summary' }}">
<meta name="twitter:title" content="{{ $ogTitle }}">

@if (filled($ogDescription))
    <meta name="twitter:description" content="{{ $ogDescription }}">
@endif

@if (filled($ogImage))
    <meta name="twitter:image" content="{{ $ogImage }}">
@endif

@foreach ($structuredDataItems as $structuredDataItem)
    <script type="application/ld+json">
        {!! json_encode($structuredDataItem, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) !!}
    </script>
@endforeach

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
