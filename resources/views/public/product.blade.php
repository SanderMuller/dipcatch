@php
    use App\Support\Favicon;
    use App\Support\MoneyFormatter;

    $image = $product->safeImageUrl();
    // The live shop behind the figure the page leads with: the best value per
    // unit, or the lowest price when the product compares no unit.
    // Never a listed shop the owner page would not crown, such as a trade-only price.
    $headlineShop = $headline->shop !== null && $shops->contains($headline->shop) ? $headline->shop : null;
    $perUnit = $headline->isPerUnit() && $headlineShop !== null && $headlineShop->is($headline->shop);
    $priceLine = match (true) {
        $headlineShop?->current_price === null => null,
        $perUnit => $headline->text() . ' at ' . $headlineShop->host . ' (' . $headline->packLine()?->text() . ')',
        default => MoneyFormatter::format((string) $headlineShop->current_price, $product->currency),
    };
    $headlineBundleLabel = \App\Support\BundlePriceLabel::forShop($headlineShop);
    $ogDescription = match (true) {
        $priceLine === null => 'Tracked on DipCatch.',
        $perUnit => "Tracked on DipCatch: best value {$priceLine}",
        default => "Tracked on DipCatch: cheapest at {$priceLine}" . ($headlineBundleLabel === null ? '' : " · {$headlineBundleLabel}"),
    };
    $canonicalUrl = $product->publicShareUrl() ?? url('/');
    $hasChart = ! empty($chart['points']);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $product->title }} — DipCatch</title>

    {{-- Open Graph / Twitter Card. og:image / twitter:image only emit when
         the user-supplied image_url passes the http(s) scheme check via
         safeImageUrl(). --}}
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $product->title }}">
    <meta property="og:description" content="{{ $ogDescription }}">
    <meta property="og:url" content="{{ $canonicalUrl }}">
    @if ($image)
        <meta property="og:image" content="{{ $image }}">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:image" content="{{ $image }}">
    @else
        <meta name="twitter:card" content="summary">
    @endif
    <meta name="twitter:title" content="{{ $product->title }}">
    <meta name="twitter:description" content="{{ $ogDescription }}">

    <link rel="icon" href="{{ asset('favicon.png') }}" type="image/png">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=geist:400,500,600&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css'])

    @if ($hasChart)
        {{-- Pinned versions with SRI (sha384) so a compromised CDN response
             cannot inject code on this page. The bundled date-fns adapter
             is required for Chart.js 4's `time` scale to render at all. --}}
        <script
            src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"
            integrity="sha384-NrKB+u6Ts6AtkIhwPixiKTzgSKNblyhlk0Sohlgar9UHUBzai/sgnNNWWd291xqt"
            crossorigin="anonymous"
            defer
        ></script>
        <script
            src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"
            integrity="sha384-cVMg8E3QFwTvGCDuK+ET4PD341jF3W8nO1auiXfuZNQkzbUUiBGLsIQUE+b1mxws"
            crossorigin="anonymous"
            defer
        ></script>
    @endif
</head>
<body class="min-h-dvh bg-linear-to-br from-canvas via-canvas to-soft-blush bg-fixed text-ink antialiased">
    <main class="mx-auto w-full max-w-app px-6 pb-10 lg:px-8">

        {{-- Brand bar --}}
        <div class="mb-10 flex items-center justify-between py-4">
            <a href="{{ url('/') }}" class="inline-flex items-center gap-2 font-semibold">
                <span class="flex aspect-square size-8 items-center justify-center rounded-xl bg-white p-0.5">
                    <img src="{{ asset('images/dipcatch-logo.png') }}" alt="" class="size-7" />
                </span>
                DipCatch
            </a>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Price tracking</p>
        </div>

        <div class="grid grid-cols-1 items-start gap-12 lg:grid-cols-12">
            <div class="lg:col-span-7">

                {{-- Header --}}
                <header class="mb-8 flex items-start gap-6">
                    @if ($image)
                        <img
                            src="{{ $image }}"
                            alt=""
                            class="size-32 shrink-0 rounded-2xl bg-white object-contain p-2 ring-1 ring-line"
                        >
                    @endif

                    <div class="min-w-0 flex-1">
                        <h1 class="max-w-[40ch] text-3xl font-semibold tracking-tight text-balance">
                            {{ $product->title }}
                        </h1>

                        @if ($priceLine !== null && $perUnit)
                            <p class="mt-3 text-4xl font-semibold tracking-tight text-brand tabular-nums" data-test="public-headline">
                                {{ $headline->text() }}
                                @if ($regularUnit = $headline->regularUnitPrice())
                                    <del title="Regular price" class="ms-2 text-lg font-normal text-zinc-400 decoration-1 dark:text-zinc-500">{{ MoneyFormatter::unitPrice($regularUnit, $headline->currency()) }} {{ \App\Support\UnitWord::labelFor($headline->unit) }}</del>
                                @endif
                            </p>
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                                {{-- Plain text: this page loads no Flux script to open a tooltip. --}}
                                Best value: {{ $headline->packLine()?->text() }} at {{ $headlineShop->host }}
                            </p>
                            @if ($headline->lowestShop && $shops->contains($headline->lowestShop))
                                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400" data-test="lowest-price-note">
                                    Lowest price: {{ $headline->packLine($headline->lowestShop)?->text() }} at {{ $headline->lowestShop->host }}.@if ($gap = $headline->lowestCostsMorePercent()) That is {{ $gap }}% more {{ \App\Support\UnitWord::forCode($headline->unit) }}.@endif
                                </p>
                            @endif
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                                Compared across {{ $shops->count() }} {{ $shops->count() === 1 ? 'shop' : 'shops' }} tracked.
                            </p>
                        @elseif ($priceLine !== null)
                            <p class="mt-3 text-4xl font-semibold tracking-tight text-brand tabular-nums"><x-shop-price :shop="$headlineShop" /></p>
                            @if ($headlineBundleLabel !== null)
                                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ $headlineBundleLabel }}</p>
                            @endif
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                                Cheapest across {{ $shops->count() }} {{ $shops->count() === 1 ? 'shop' : 'shops' }} tracked.
                            </p>
                        @else
                            <p class="mt-3 text-lg text-zinc-500 dark:text-zinc-400">
                                No live price available right now.
                            </p>
                        @endif
                    </div>
                </header>

                {{-- Price-history chart --}}
                @if ($hasChart)
                    <section class="mt-10">
                        <h2 class="mb-3 text-base font-semibold">
                            {{ $chart['unit'] === null ? 'Price' : 'Price ' . \App\Support\UnitWord::forCode($chart['unit']) }} (last 90 days)
                        </h2>
                        <div class="rounded-2xl bg-paper/80 p-4 ring-1 ring-line backdrop-blur-sm">
                            <canvas id="price-history-chart" height="180"></canvas>
                        </div>
                        <script id="price-history-data" type="application/json">@json($chart['points'])</script>
                        <script>
                            document.addEventListener('DOMContentLoaded', function () {
                                const init = function () {
                                    if (typeof Chart === 'undefined') {
                                        return setTimeout(init, 50);
                                    }
                                    const data = JSON.parse(document.getElementById('price-history-data').textContent);
                                    const ctx = document.getElementById('price-history-chart');
                                    new Chart(ctx, {
                                        type: 'line',
                                        data: {
                                            datasets: [{
                                                data: data,
                                                borderColor: 'rgb(53, 84, 255)',
                                                backgroundColor: 'rgba(53, 84, 255, 0.1)',
                                                borderWidth: 2,
                                                pointRadius: 0,
                                                stepped: 'before',
                                                tension: 0,
                                                fill: true,
                                                parsing: { xAxisKey: 'x', yAxisKey: 'y' },
                                            }],
                                        },
                                        options: {
                                            responsive: true,
                                            maintainAspectRatio: false,
                                            scales: {
                                                x: { type: 'time', time: { unit: 'day' }, grid: { display: false } },
                                                y: { beginAtZero: false },
                                            },
                                            plugins: {
                                                legend: { display: false },
                                                tooltip: {
                                                    callbacks: {
                                                        afterLabel: function (context) {
                                                            return context.raw.bundle || '';
                                                        },
                                                    },
                                                },
                                            },
                                        },
                                    });
                                };
                                init();
                            });
                        </script>
                    </section>
                @endif
            </div>

            <div class="lg:col-span-5">
                {{-- Shop list --}}
                @if ($shops->isNotEmpty())
                    <section>
                        <h2 class="mb-3 text-base font-semibold">
                            Shops
                        </h2>
                        <ul role="list" class="divide-y divide-ink/5 overflow-hidden rounded-2xl bg-paper/80 ring-1 ring-line backdrop-blur-sm">
                            @foreach ($shops as $shop)
                                <li>
                                    <a
                                        href="{{ $shop->url }}"
                                        rel="noopener nofollow ugc"
                                        target="_blank"
                                        class="flex flex-col items-start gap-2 px-4 py-3 hover:bg-canvas sm:flex-row sm:items-center sm:justify-between sm:gap-4"
                                    >
                                        <div class="min-w-0 flex-1">
                                            <p class="flex items-center gap-1.5 truncate text-sm font-medium">
                                                <img src="{{ Favicon::url($shop->host) }}" alt="" loading="lazy" class="size-4 rounded-sm" />
                                                {{ $shop->host }}
                                                @if ($shop->id === $lowestShopId && $shops->count() > 1)
                                                    <span class="ml-1.5 inline-flex items-center rounded-full bg-savings/10 px-2 py-0.5 text-xs font-medium text-savings-strong">Lowest price</span>
                                                @endif
                                                @if ($shop->id === $bestValueShopId && $shops->count() > 1)
                                                    <span class="ml-1.5 inline-flex items-center rounded-full bg-brand/10 px-2 py-0.5 text-xs font-medium text-brand">Best value</span>
                                                @endif
                                            </p>
                                            @php
                                                $pack = $packs->for($shop);
                                            @endphp
                                            @if ($pack !== null && $pack->isExcluded())
                                                <p class="mt-0.5 text-xs text-amber-700 dark:text-amber-500">{{ $pack->reason() }}</p>
                                            @endif
                                            @if ($shop->priceReadAt())
                                                <p class="mt-0.5 text-xs {{ $shop->readsAreFailing() ? 'text-amber-700 dark:text-amber-500' : 'text-zinc-500 dark:text-zinc-400' }}">
                                                    {{-- The age of the price, not of the last attempt: a failed
                                                         read stamps `last_checked_at` too, so a shop that has
                                                         been failing for a week read as freshly checked. --}}
                                                    Price read {{ $shop->priceReadAt()->diffForHumans() }}{{ $shop->readsAreFailing() ? ', and not read since' : '' }}
                                                </p>
                                            @endif
                                        </div>
                                        <div class="w-full text-left sm:w-auto sm:text-right">
                                            @php($shopUnitPrice = $packs->hasComparisonUnit() && $shop->notAConsumerPriceReason() === null ? $packs->unitPriceOf($shop) : null)
                                            <div class="flex items-center justify-start gap-1 sm:justify-end">
                                                <p class="text-sm font-semibold tabular-nums">
                                                    {{-- Per unit first: the figure the shops compare on. --}}
                                                    @if ($shopUnitPrice !== null)
                                                        {{ MoneyFormatter::unitPrice($shopUnitPrice, $shop->currency) }} {{ \App\Support\UnitWord::labelFor($packs->unit()) }}
                                                    @else
                                                        <x-shop-price :shop="$shop" />
                                                    @endif
                                                </p>
                                                <svg viewBox="0 0 16 16" fill="none" class="size-4 text-zinc-400" aria-hidden="true">
                                                    <path d="M6 12l4-4-4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                                </svg>
                                            </div>
                                            @if ($shopUnitPrice !== null)
                                                <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ \App\Support\PackLine::of($shop, $packs)->text() }}</p>
                                            @elseif ($bundleLabel = \App\Support\BundlePriceLabel::forShop($shop))
                                                <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ $bundleLabel }}</p>
                                            @endif
                                            @if ($reason = $shop->notAConsumerPriceReason())
                                                <p class="mt-0.5 text-xs text-amber-700 dark:text-amber-500">{{ $reason }}</p>
                                            @endif
                                        </div>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            </div>
        </div>

        {{-- Footer --}}
        <footer class="mt-16 border-t border-ink/10 pt-6 text-sm text-zinc-500 dark:text-zinc-400">
            Tracked on <a href="{{ url('/') }}" class="font-medium text-brand hover:underline">DipCatch</a>
        </footer>
    </main>
</body>
</html>
