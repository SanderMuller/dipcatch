@php
    // Marketing locale: `?lang=nl|en` only, English otherwise (MarketingLocale).
    $locale = app()->getLocale();
    $requestedLang = \App\Http\Middleware\MarketingLocale::requested(request());
    $langQuery = $requestedLang === null ? [] : ['lang' => $requestedLang];
    $canonical = $locale === 'nl' ? route('pricing', ['lang' => 'nl']) : route('pricing');
    $free = \App\Billing\Entitlements::of(\App\Billing\Plan::Free);
    $pro = \App\Billing\Entitlements::of(\App\Billing\Plan::Pro);
    $price = \App\Billing\ProPrice::label();
    $trialDays = \App\Billing\ProPrice::trialDays();
    $authed = auth()->check();
    $ctaHref = $authed ? url('/app/billing') : route('register');
    // Pro has its own destination: `upgrade` decides between registration,
    // checkout and the billing page, so the card links to one URL whoever
    // is reading it.
    $isPro = $authed && auth()->user()?->isPro() === true;
    $proCtaHref = $isPro ? url('/app/billing') : route('upgrade');
    $proCtaLabel = match (true) {
        $isPro => __('Your plan'),
        $authed => __('Upgrade to Pro'),
        default => __('Start with Pro'),
    };
    $onSale = \App\Billing\BillingGate::isOpen();
    $description = __('DipCatch is free for :count products at up to :shops shops each. Pro follows up to :pro products at every shop you like, and can sort them into categories for you.', [
        'count' => $free->maxProducts(),
        'shops' => $free->maxShopsPerProduct(),
        'pro' => $pro->maxProducts(),
    ]);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth bg-canvas">
    <head>
        @include('partials.head', [
            'title' => __('Pricing'),
            'description' => $description,
            'canonical' => $canonical,
        ])
        <link rel="alternate" hreflang="en" href="{{ route('pricing') }}">
        <link rel="alternate" hreflang="nl" href="{{ route('pricing', ['lang' => 'nl']) }}">
        <link rel="alternate" hreflang="x-default" href="{{ route('pricing') }}">
        {{ \App\Support\JsonLd::script(\App\Support\StructuredData::pricing($description)) }}
    </head>
    <body class="min-h-dvh bg-linear-to-br from-canvas via-canvas to-soft-blush bg-fixed text-ink antialiased">
        <div class="flex min-h-dvh flex-col">
            <x-marketing-header />


            <main class="mx-auto w-full max-w-app flex-1 px-6 pt-8 pb-20 lg:px-8">
                @php
                    // Numbers come from the plans, never typed, so the page
                    // cannot drift from what each plan gives.
                    $symbol = \App\Support\MoneyFormatter::symbol(\App\Billing\ProPrice::currency());
                    $proHistory = __(':days days', ['days' => $pro->historyKeptDays()]);
                    // [label, what it means, Free, Pro]: true is a tick, false a dash.
                    $sections = [
                        __('Tracking') => [
                            [__('Products'), __('Everything you buy again and again.'), (string) $free->maxProducts(), (string) $pro->maxProducts()],
                            [__('Shops per product'), __('The best buy is only as good as the shops you compare.'), (string) $free->maxShopsPerProduct(), __('Unlimited')],
                            [__('Price checks'), __('How soon a new price reaches you.'), __('Every :hours h', ['hours' => $free->recheckIntervalHours()]), __('Every :hours h', ['hours' => $pro->recheckIntervalHours()])],
                            [__('Price per kilo, litre or piece'), __('Packs of different sizes compared fairly.'), true, true],
                        ],
                        __('Alerts') => [
                            [__('Price drop alerts'), __('Email, in-app and browser push.'), true, true],
                            [__('Alerts per hour'), __('For the days everything is on offer.'), (string) $free->notificationsHourlyLimit(), (string) $pro->notificationsHourlyLimit()],
                            [__('Target price per kilo'), __('Any shop, any pack, at the price you set.'), false, true],
                        ],
                        __('History') => [
                            [__('Price history'), __('Tells a real low from the usual offer.'), __(':days days', ['days' => $free->historyKeptDays()]), $proHistory],
                        ],
                        __('AI help, off until you switch it on') => [
                            [__('Automatic categories'), __('New products filed for you.'), false, true],
                            [__('Same-product check'), __('A warning when a new shop sells another flavour or pack.'), false, true],
                            [__('More shops found'), __('Other shops that sell what you track, confirmed.'), false, true],
                        ],
                    ];
                    $trialNote = $onSale
                        ? ($trialDays > 0 ? __(':days days free, then cancel any time.', ['days' => $trialDays]) : __('Cancel any time.'))
                        : __('Not on sale yet.');
                    $freeCtaLabel = $authed ? __('Your plan') : __('Start free');
                    // A Pro subscriber is not on Free, so the Free column offers no button.
                    $showFreeCta = ! $isPro;
                    $proCta = $isPro ? $proCtaLabel : ($trialDays > 0 ? __('Try Pro free') : $proCtaLabel);
                    // The Pro column is narrow on a phone, so its header button says less.
                    $proCtaShort = $trialDays > 0 ? __('Try free') : __('Get Pro');
                    $tick = '<svg viewBox="0 0 16 16" class="size-5 fill-savings-strong" aria-hidden="true"><path fill-rule="evenodd" d="M12.416 3.376a.75.75 0 0 1 .208 1.04l-5 7.5a.75.75 0 0 1-1.154.114l-3-3a.75.75 0 0 1 1.06-1.06l2.353 2.353 4.493-6.74a.75.75 0 0 1 1.04-.207Z" clip-rule="evenodd" /></svg>';
                    $dash = '<svg viewBox="0 0 16 16" class="size-5 fill-zinc-300 dark:fill-zinc-600" aria-hidden="true"><path d="M3.75 7.25a.75.75 0 0 0 0 1.5h8.5a.75.75 0 0 0 0-1.5h-8.5Z" /></svg>';
                    $cell = fn (string|bool $value, bool $strong): string => match (true) {
                        $value === true => $tick . '<span class="sr-only">' . e(__('Included')) . '</span>',
                        $value === false => $dash . '<span class="sr-only">' . e(__('Not included')) . '</span>',
                        default => '<span class="tabular-nums ' . ($strong ? 'font-semibold text-ink' : 'font-medium text-zinc-700 dark:text-zinc-300') . '">' . e($value) . '</span>',
                    };
                    // What Pro adds on a row, keyed by the row's label.
                    $gains = [
                        __('Products') => __(':times× more', ['times' => (int) floor($pro->maxProducts() / max(1, $free->maxProducts()))]),
                        __('Shops per product') => __('No limit'),
                        __('Price checks') => __(':times× as often', ['times' => (int) round($free->recheckIntervalHours() / max(1, $pro->recheckIntervalHours()))]),
                        __('Alerts per hour') => __(':times× more', ['times' => (int) floor($pro->notificationsHourlyLimit() / max(1, $free->notificationsHourlyLimit()))]),
                        __('Price history') => __(':times× longer', ['times' => (int) floor($pro->historyKeptDays() / max(1, $free->historyKeptDays()))]),
                        __('Target price per kilo') => __('Pro only'),
                        __('Automatic categories') => __('Pro only'),
                        __('Same-product check') => __('Pro only'),
                        __('More shops found') => __('Pro only'),
                    ];
                    $freeButton = 'inline-flex w-full items-center justify-center rounded-full bg-paper px-2 py-2 text-sm whitespace-nowrap sm:px-3 font-medium text-ink ring-1 ring-line hover:bg-canvas focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand';
                    $proButton = 'inline-flex w-full items-center justify-center rounded-full bg-ink px-2 py-2 text-sm whitespace-nowrap sm:px-3 font-medium text-paper hover:bg-ink/85 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand';
                @endphp
                {{-- Sticky plan header, and each Pro cell says how much more you
                     get, so the value of the upgrade reads row by row. --}}

                <div class="w-fit mx-auto">
                    <h1 class="max-w-[35ch] text-3xl font-semibold tracking-tight text-balance sm:text-4xl">{{ __('Free to start. Pro for everything you buy.') }}</h1>
                    <p class="mt-4 max-w-[56ch] text-base text-pretty text-zinc-600 dark:text-zinc-300">{{ __('Both plans alert you when a price drops. Pro compares every shop, checks more often, keeps prices for longer, and can let AI help.') }}</p>
                </div>

                <div class="mt-12 max-w-3xl mx-auto">
                    <table class="w-full table-fixed border-separate border-spacing-0 text-left">
                        <caption class="sr-only">{{ __('Free and Pro compared') }}</caption>
                        <colgroup>
                            <col class="w-[36%] sm:w-[40%]">
                            <col class="w-[24%] sm:w-[25%]">
                            <col class="w-[40%] sm:w-[35%]">
                        </colgroup>
                        <thead class="sticky top-16 z-10">
                            <tr>
                                <td class="border-b border-ink/10 bg-canvas/90 align-bottom backdrop-blur-sm dark:border-white/10">
                                    @unless ($isPro)
                                        <p class="pb-4 text-sm text-zinc-500 max-sm:hidden dark:text-zinc-400">{{ $trialNote }}</p>
                                    @endunless
                                </td>
                                <th scope="col" class="border-b border-ink/10 bg-canvas/90 px-3 py-4 align-top backdrop-blur-sm sm:px-6 dark:border-white/10">
                                    <p class="text-base font-semibold">{{ __('Free') }}</p>
                                    <p class="mt-1 text-2xl font-semibold tracking-tight tabular-nums">{{ $symbol }}0</p>
                                    @if ($showFreeCta)
                                        <a href="{{ $ctaHref }}" class="{{ $freeButton }} mt-3 max-sm:hidden">{{ $freeCtaLabel }}</a>
                                    @endif
                                </th>
                                <th scope="col" class="rounded-t-2xl border-b border-ink/10 bg-soft-yellow/90 px-3 py-4 align-top backdrop-blur-sm sm:px-6 dark:border-white/10">
                                    <p class="flex flex-wrap items-center gap-2 text-base font-semibold">
                                        {{ __('Pro') }}
                                        @if ($onSale && $trialDays > 0 && ! $isPro)
                                            <span class="rounded-full bg-savings/15 px-2 py-0.5 text-xs font-medium whitespace-nowrap text-savings-strong">{{ __(':days days free', ['days' => $trialDays]) }}</span>
                                        @endif
                                    </p>
                                    <p class="mt-1 flex flex-wrap items-baseline gap-x-1"><span class="text-2xl font-semibold tracking-tight text-brand tabular-nums">{{ $price }}</span><span class="text-sm font-normal text-zinc-600 dark:text-zinc-400">/ {{ __('month') }}</span></p>
                                    @if (\App\Billing\ProPrice::hasYearly())
                                        <p class="text-sm font-normal text-zinc-600 dark:text-zinc-400">{{ __('or :price a year', ['price' => \App\Billing\ProPrice::yearlyLabel()]) }}</p>
                                    @endif
                                    @if ($onSale)
                                        <a href="{{ $proCtaHref }}" @class([$proButton, 'mt-3', 'max-sm:hidden' => $isPro])>
                                            <span class="sm:hidden">{{ $proCtaShort }}</span><span class="max-sm:hidden">{{ $proCta }}</span>
                                        </a>
                                    @endif
                                </th>
                            </tr>
                        </thead>
                        @foreach ($sections as $section => $rows)
                            <tbody>
                                <tr>
                                    <th scope="colgroup" class="pt-8 pb-3 text-sm font-medium whitespace-nowrap text-brand">{{ $section }}</th>
                                    <td></td>
                                    <td class="bg-soft-yellow/60"></td>
                                </tr>
                                @foreach ($rows as [$label, $hint, $freeValue, $proValue])
                                    <tr>
                                        <th scope="row" class="border-b border-ink/5 py-4 pr-4 text-base font-normal dark:border-white/10">
                                            <span class="font-medium text-ink">{{ $label }}</span>
                                            <span class="mt-0.5 block text-sm text-zinc-500 max-sm:hidden dark:text-zinc-400">{{ $hint }}</span>
                                        </th>
                                        <td class="border-b border-ink/5 px-3 py-4 text-base sm:px-6 sm:text-lg dark:border-white/10">{!! $cell($freeValue, false) !!}</td>
                                        <td class="border-b border-ink/5 bg-soft-yellow/60 px-3 py-4 text-base sm:px-6 sm:text-lg dark:border-white/10">
                                            <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                {!! $cell($proValue, true) !!}
                                                @isset($gains[$label])
                                                    <span class="rounded-full bg-savings/15 px-2 py-0.5 text-xs font-medium whitespace-nowrap text-savings-strong">{{ $gains[$label] }}</span>
                                                @endisset
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        @endforeach
                        <tfoot>
                            <tr>
                                <td class="pt-6 pr-4 align-top text-sm text-zinc-500 dark:text-zinc-400">{{ __('VAT included.') }}</td>
                                <td class="px-3 pt-6 align-top sm:px-6">@if ($showFreeCta)<a href="{{ $ctaHref }}" class="{{ $freeButton }} max-sm:hidden">{{ $freeCtaLabel }}</a>@endif</td>
                                <td class="rounded-b-2xl bg-soft-yellow/60 px-3 pt-6 pb-6 align-top sm:px-6">
                                    {{-- On a phone the buttons sit full width under the table. --}}
                                    @if ($onSale)
                                        <a href="{{ $proCtaHref }}" class="{{ $proButton }} max-sm:hidden">{{ $proCta }}</a>
                                        @if (! $isPro && \App\Billing\ProPrice::hasYearly())
                                            <a href="{{ route('upgrade', ['interval' => 'yearly']) }}" class="mt-3 block text-center text-sm max-sm:hidden font-medium text-ink underline decoration-line underline-offset-4 hover:text-brand">{{ __('Pay yearly instead: :price', ['price' => \App\Billing\ProPrice::yearlyLabel()]) }}</a>
                                        @endif
                                    @else
                                        <p class="rounded-full bg-ink/5 px-3 py-2 text-center text-sm font-medium text-zinc-500">{{ __('Coming soon') }}</p>
                                    @endif
                                </td>
                            </tr>
                        </tfoot>
                    </table>

                    <div class="mt-6 space-y-3 sm:hidden">
                        @if ($onSale)
                            <a href="{{ $proCtaHref }}" class="flex w-full items-center justify-center rounded-full bg-ink px-4 py-2.5 text-base font-medium text-paper hover:bg-ink/85 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">{{ $proCta }}</a>
                            @if (! $isPro && \App\Billing\ProPrice::hasYearly())
                                <a href="{{ route('upgrade', ['interval' => 'yearly']) }}" class="block text-center text-base font-medium text-ink underline decoration-line underline-offset-4 hover:text-brand">{{ __('Pay yearly instead: :price', ['price' => \App\Billing\ProPrice::yearlyLabel()]) }}</a>
                            @endif
                        @endif
                        @if ($showFreeCta)
                            <a href="{{ $ctaHref }}" class="flex w-full items-center justify-center rounded-full bg-paper px-4 py-2.5 text-base font-medium text-ink ring-1 ring-line hover:bg-canvas focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">{{ $freeCtaLabel }}</a>
                        @endif
                    </div>
                </div>

                <p class="mt-10 max-w-[64ch] text-sm text-pretty text-zinc-500 dark:text-zinc-400">
                    {{ __('If you go back to Free, nothing you follow is deleted or stopped. You keep everything you added. You just cannot add more until you are under the free limit again.') }}
                </p>

            </main>

            <footer class="mx-auto w-full max-w-app px-6 pb-10 lg:px-8">
                <x-marketing-footer-links :lang-query="$langQuery" :contact-email="config('site.contact_email')" :home="true" />
            </footer>
        </div>

        @fluxScripts
    </body>
</html>
