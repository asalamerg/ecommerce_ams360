{{--
    Search and sharing tags from App\Support\StorefrontSeo, printed into the first
    response for crawlers and link previews. resources/js/lib/seo.ts replaces every
    [data-seo] tag on client-side visits. Pages without SEO data are kept out of the index.
--}}
@if ($seo)
    @if ($seo['description'])
        <meta name="description" content="{{ $seo['description'] }}" data-seo>
    @endif
    <meta name="robots" content="{{ $seo['robots'] }}" data-seo>
    <link rel="canonical" href="{{ $seo['canonical'] }}" data-seo>
    @foreach ($seo['alternates'] as $hreflang => $href)
        <link rel="alternate" hreflang="{{ $hreflang }}" href="{{ $href }}" data-seo>
    @endforeach
    <meta property="og:type" content="{{ $seo['type'] }}" data-seo>
    <meta property="og:site_name" content="{{ $seo['site_name'] }}" data-seo>
    <meta property="og:title" content="{{ $seo['title'] }}" data-seo>
    @if ($seo['description'])
        <meta property="og:description" content="{{ $seo['description'] }}" data-seo>
    @endif
    <meta property="og:url" content="{{ $seo['canonical'] }}" data-seo>
    <meta property="og:locale" content="{{ $seo['locale'] }}" data-seo>
    @if ($seo['image'])
        <meta property="og:image" content="{{ $seo['image'] }}" data-seo>
    @endif
    <meta name="twitter:card" content="{{ $seo['image'] ? 'summary_large_image' : 'summary' }}" data-seo>
    @if ($seo['schema'])
        {{-- JSON_HEX_TAG turns < and > into < and >, so text such as </script> in a product name cannot end this tag. --}}
        <script type="application/ld+json" nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" data-seo>{!! json_encode($seo['schema'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) !!}</script>
    @endif
@else
    <meta name="robots" content="noindex" data-seo>
@endif
