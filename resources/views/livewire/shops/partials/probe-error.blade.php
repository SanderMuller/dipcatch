{{--
    The probe error copy for every `ProbeFailure` code. Shared by add-shop and
    create-product-from-url — `$url` and `$errorContext` are public component
    properties, so an `@include` inherits them the same way manual-selector
    and variant-chooser already do.
--}}
@switch($errorCode)
    @case('invalid_url')
        That doesn't look like a valid URL.
        @break
    @case('empty_url')
        Paste a product URL above.
        @break
    @case('duplicate')
        @php $dupHost = $errorContext['existing_shop_host'] ?? null; @endphp
        This URL is already tracked for this product{{ $dupHost ? ' (' . $dupHost . ')' : '' }}.
        @break
    @case('robots_disallowed')
        This shop's robots.txt forbids automated access.
        @break
    @case('blocked')
        @if ($errorContext['persistent'] ?? false)
            This shop has blocked our last {{ $errorContext['failures'] ?? 0 }} requests. Trying again won't help.
        @else
            This shop is blocking automated checks (Cloudflare/Akamai). We can't track it right now.
        @endif
        @break
    @case('host_rate_limited')
        This shop returned a rate-limit response (HTTP 429). Try again in {{ $errorContext['retry_after_seconds'] ?? '~60' }} seconds.
        @break
    @case('local_throttle')
        We're spacing out checks to this shop to be polite. Try again in {{ $errorContext['retry_after_seconds'] ?? '~60' }} seconds.
        @break
    @case('probe_rate_limited')
        You've probed too many URLs in the last minute. Try again in {{ $errorContext['retry_after_seconds'] ?? '~60' }} seconds.
        @break
    @case('temporary_failure')
        @if ($errorContext['persistent'] ?? false)
            This shop hasn't answered our last {{ $errorContext['failures'] ?? 0 }} requests. Trying again now won't help.
        @else
            The shop is having a server problem (HTTP {{ $errorContext['status'] ?? '5xx' }}). Try again later.
        @endif
        @break
    @case('http_error')
        The shop returned HTTP {{ $errorContext['status'] ?? 'error' }}. Check the URL and try again.
        @break
    @case('currency_mismatch')
        That shop sells in {{ $errorContext['actual'] ?? '?' }} but this product is tracked in {{ $errorContext['expected'] ?? '?' }}. Multi-currency tracking is not supported yet.
        @break
    @case('extraction_failed')
        DipCatch could not read a price from that page. Most shops work from the product URL itself.
        <x-shop-request-link :url="$url" class="font-medium" />
        @break
    @case('shop_not_servable')
        This shop builds its prices in the browser, so there is nothing for DipCatch to read on the page. It cannot be tracked.
        @break
    @case('not_in_dataset')
        @php $njReason = $errorContext['reason'] ?? null; @endphp
        @if ($njReason === 'unrecognized_url')
            No product id found in that URL. Paste a product page URL for this shop (not a category or search page).
        @elseif ($njReason === 'dataset_empty')
            The daily price dataset has not been loaded yet. Run <code>php artisan dipcatch:refresh-checkjebon</code> once.
        @else
            This product is not in the daily price dataset (checkjebon.nl). DipCatch can only track dataset-listed products for this shop.
        @endif
        @break
    @default
        {{ $errorCode }}
@endswitch
