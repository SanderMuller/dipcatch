{{-- Shared <head> for every page: marketing, auth and app.

     Optional variables, each guarded so a page can pass only what it has:
       $title        page title, suffixed with the app name
       $description  meta description, also the OG/Twitter description
       $canonical    absolute canonical URL — also the switch for the social
                     card block, so a page without one emits no half card
       $ogTitle      social-card title when it should differ from $title
       $ogImage      social-card image, defaults to the 1200x630 house card
       $robots       robots meta, e.g. `noindex`

     The canonical link and og:locale below are asserted byte for byte in
     MarketingLocaleTest, so keep the attribute order and the bare `>`. --}}
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>{{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}</title>

@if (filled($description ?? null))
    <meta name="description" content="{{ $description }}">
@endif

@if (filled($robots ?? null))
    <meta name="robots" content="{{ $robots }}">
@endif

@if (filled($canonical ?? null))
    <link rel="canonical" href="{{ $canonical }}">
@endif

<link rel="icon" href="/favicon.png" type="image/png">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
{{-- One colour, matching the light canvas: light is the default mode, so a
     colour keyed on the OS preference painted the wrong browser chrome for an
     OS-dark visitor who never chose dark. The script below keeps it in step
     with an explicit choice. --}}
<meta name="theme-color" content="#fff9e8">

@if (filled($canonical ?? null))
    @php($socialImage = $ogImage ?? asset('images/og-default.png'))
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ config('app.name') }}">
    <meta property="og:title" content="{{ $ogTitle ?? $title ?? config('app.name') }}">
    <meta property="og:description" content="{{ $description ?? '' }}">
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:locale" content="{{ app()->getLocale() === 'nl' ? 'nl_NL' : 'en_US' }}">
    <meta property="og:locale:alternate" content="{{ app()->getLocale() === 'nl' ? 'en_US' : 'nl_NL' }}">
    <meta property="og:image" content="{{ $socialImage }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $ogTitle ?? $title ?? config('app.name') }}">
    <meta name="twitter:description" content="{{ $description ?? '' }}">
    <meta name="twitter:image" content="{{ $socialImage }}">
@endif

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=geist:400,500,600&display=swap" rel="stylesheet" />

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance

{{-- Flux falls back to `system` when nothing is stored, which handed every
     OS-dark visitor a dark page. Light is the default here. An explicit
     choice still writes `flux.appearance` and wins on the next load. --}}
<script>
    window.Flux.applyAppearance(window.localStorage.getItem('flux.appearance') || 'light');
    document.querySelector('meta[name="theme-color"]')
        ?.setAttribute('content', document.documentElement.classList.contains('dark') ? '#09090b' : '#fff9e8');
</script>
