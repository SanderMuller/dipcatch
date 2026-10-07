@php
    // Marketing locale: `?lang=nl|en` only, English otherwise (MarketingLocale).
    $locale = app()->getLocale();
    $requestedLang = \App\Http\Middleware\MarketingLocale::requested(request());
    $langQuery = $requestedLang === null ? [] : ['lang' => $requestedLang];
    $canonical = $locale === 'nl' ? route('pricing', ['lang' => 'nl']) : route('pricing');
    $free = \App\Billing\Entitlements::of(\App\Billing\Plan::Free);
    $pro = \App\Billing\Entitlements::of(\App\Billing\Plan::Pro);
    // A former subscriber gets no second trial, so the page must not promise one.
    $trialDays = auth()->user()?->qualifiesForTrial() === false ? 0 : \App\Billing\ProPrice::trialDays();
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
                    $trialNote = $onSale
                        ? ($trialDays > 0 ? __(':days days free, then cancel any time.', ['days' => $trialDays]) : __('Cancel any time.'))
                        : __('Not on sale yet.');
                    $proCta = $isPro ? $proCtaLabel : ($trialDays > 0 ? __('Try Pro free') : $proCtaLabel);
                @endphp
                <div class="w-fit mx-auto">
                    <h1 class="max-w-[35ch] text-3xl font-semibold tracking-tight text-balance sm:text-4xl">{{ __('Free to start. Pro for everything you buy.') }}</h1>
                    <p class="mt-4 max-w-[56ch] text-base text-pretty text-zinc-600 dark:text-zinc-300">{{ __('Both plans alert you when a price drops. Pro compares every shop, checks more often, keeps prices for longer, and can let AI help.') }}</p>
                </div>

                <x-plans.comparison
                    class="mt-12"
                    :free-cta="$isPro ? null : ['href' => $ctaHref, 'label' => $authed ? __('Your plan') : __('Start free')]"
                    :pro-cta="['href' => $proCtaHref, 'label' => $proCta, 'short' => $trialDays > 0 ? __('Try free') : __('Get Pro')]"
                    :yearly-cta="! $isPro && \App\Billing\ProPrice::hasYearly() ? ['href' => route('upgrade', ['interval' => 'yearly']), 'label' => __('Pay yearly instead: :price', ['price' => \App\Billing\ProPrice::yearlyLabel()])] : null"
                    :note="$isPro ? null : $trialNote"
                    :is-pro="$isPro"
                    :offers-trial="$trialDays > 0"
                />

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
