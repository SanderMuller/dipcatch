@php
    use App\Support\MoneyFormatter;

    $image = $product->safeImageUrl();
    // Suppress the denormalized `cheapest_price` when no eligible shop is
    // currently visible. The denorm is recomputed asynchronously after each
    // CheckShopPrice; between "all shops became ineligible" and the next
    // recompute, the column carries a stale number. Rendering it next to
    // "0 shops tracked" misleads — fall back to the no-price treatment.
    $priceLine = ($product->cheapest_price !== null && $shops->isNotEmpty())
        ? MoneyFormatter::format((string) $product->cheapest_price, $product->currency)
        : null;
    $ogDescription = $priceLine !== null
        ? "Tracked on DipCatch: cheapest at {$priceLine}"
        : 'Tracked on DipCatch.';
    $canonicalUrl = $product->publicShareUrl() ?? url('/');
    $hasChart = ! empty($chart);
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
<body class="min-h-dvh bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
    <main class="mx-auto max-w-2xl px-6 py-10">

        {{-- Brand bar --}}
        <div class="mb-10 flex items-center justify-between">
            <a href="{{ url('/') }}" class="inline-flex items-center gap-2 text-sm font-semibold tracking-tight text-zinc-900 dark:text-zinc-100">
                <img src="{{ asset('images/dipcatch-logo.png') }}" alt="" class="size-6" />
                DipCatch
            </a>
            <span class="text-xs text-zinc-500 dark:text-zinc-400">Price tracking</span>
        </div>

        {{-- Header --}}
        <header class="mb-8 flex items-start gap-6">
            @if ($image)
                <img
                    src="{{ $image }}"
                    alt=""
                    class="size-32 shrink-0 rounded-lg border border-zinc-200 bg-white object-cover dark:border-zinc-800"
                >
            @endif

            <div class="flex-1">
                <h1 class="text-2xl font-semibold">
                    {{ $product->title }}
                </h1>

                @if ($priceLine !== null)
                    <p class="mt-3 text-3xl font-bold tabular-nums">{{ $priceLine }}</p>
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
            <section class="mt-8">
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                    Price (last 90 days)
                </h2>
                <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                    <canvas id="price-history-chart" height="180"></canvas>
                </div>
                <script id="price-history-data" type="application/json">@json($chart)</script>
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
                                        borderColor: 'rgb(99, 102, 241)',
                                        backgroundColor: 'rgba(99, 102, 241, 0.1)',
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
                                    plugins: { legend: { display: false } },
                                },
                            });
                        };
                        init();
                    });
                </script>
            </section>
        @endif

        {{-- Shop list --}}
        @if ($shops->isNotEmpty())
            <section class="mt-8">
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                    Shops
                </h2>
                <ul role="list" class="divide-y divide-zinc-200 overflow-hidden rounded-lg border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-800 dark:bg-zinc-900">
                    @foreach ($shops as $shop)
                        <li>
                            <a
                                href="{{ $shop->url }}"
                                rel="noopener nofollow ugc"
                                target="_blank"
                                class="flex items-center justify-between gap-4 px-4 py-3 transition hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                            >
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium">
                                        {{ $shop->host }}
                                        @if ($loop->first && $shops->count() > 1)
                                            <span class="ml-1.5 inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 inset-ring inset-ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400 dark:inset-ring-emerald-500/20">Cheapest</span>
                                        @endif
                                    </p>
                                    @if ($shop->last_checked_at)
                                        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                                            Last checked {{ $shop->last_checked_at->diffForHumans() }}
                                        </p>
                                    @endif
                                </div>
                                <div class="text-right">
                                    <div class="flex items-center gap-1 justify-end">
                                        <p class="text-sm font-semibold tabular-nums">
                                            {{ MoneyFormatter::format((string) $shop->current_price, $shop->currency) }}
                                        </p>
                                        <svg viewBox="0 0 16 16" fill="none" class="size-4 text-zinc-400" aria-hidden="true">
                                            <path d="M6 12l4-4-4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    </div>
                                    @php
                                        $shopUnitPrice = $shop->unitPrice();
                                    @endphp
                                    @if ($shopUnitPrice !== null)
                                        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400 tabular-nums">
                                            {{ MoneyFormatter::format($shopUnitPrice, $shop->currency) }} {{ $shop->unitPriceLabel() }}
                                        </p>
                                    @endif
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- Footer --}}
        <footer class="mt-12 border-t border-zinc-200 pt-6 text-center text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
            Tracked on <a href="{{ url('/') }}" class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">DipCatch</a>
        </footer>
    </main>
</body>
</html>
