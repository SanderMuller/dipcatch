@php
    // Marketing locale: `?lang=nl|en` only, English otherwise (MarketingLocale).
    $locale = app()->getLocale();
    $requestedLang = \App\Http\Middleware\MarketingLocale::requested(request());
    $langQuery = $requestedLang === null ? [] : ['lang' => $requestedLang];
    $canonical = $locale === 'nl' ? route('terms', ['lang' => 'nl']) : route('terms');
    $contactEmail = config('site.contact_email');
    $updated = config('site.terms_updated_at');
    $trialDays = \App\Billing\ProPrice::trialDays();
    $description = __('The agreement between you and DipCatch: what the service does, what it costs, and what neither side promises.');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth bg-amber-50 dark:bg-zinc-950">
    <head>
        @include('partials.head', [
            'title' => __('Terms of service'),
            'description' => $description,
            'canonical' => $canonical,
        ])
        <link rel="alternate" hreflang="en" href="{{ route('terms') }}">
        <link rel="alternate" hreflang="nl" href="{{ route('terms', ['lang' => 'nl']) }}">
        <link rel="alternate" hreflang="x-default" href="{{ route('terms') }}">
        {{ \App\Support\JsonLd::script(\App\Support\StructuredData::terms($canonical, $description)) }}
    </head>
    <body class="min-h-dvh bg-linear-to-br from-amber-50 to-rose-50 bg-fixed text-zinc-900 antialiased dark:from-zinc-950 dark:to-zinc-950 dark:text-zinc-50">
        <div class="flex min-h-dvh flex-col">
            <x-marketing-header width="max-w-3xl" />

            <main class="mx-auto w-full max-w-3xl flex-1 px-6 pt-8 pb-20 lg:px-8">
                <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">{{ __('Terms of service') }}</h1>
                @if (filled($updated))
                    <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Last updated :date', ['date' => $updated]) }}</p>
                @endif

                <div class="mt-8 space-y-8 text-base text-zinc-700 [&_h2]:text-lg [&_h2]:font-semibold [&_h2]:text-zinc-900 [&_li]:mt-1 [&_ul]:list-disc [&_ul]:pl-5 dark:text-zinc-300 dark:[&_h2]:text-zinc-50">
                    <section>
                        <p>{{ __('DipCatch is run from the Netherlands. Using the service means you agree to what is on this page. It is written to be read, not to be impressive.') }}</p>
                    </section>

                    <section>
                        <h2>{{ __('What the service does') }}</h2>
                        <p class="mt-2">{{ __('You give DipCatch links to product pages. DipCatch fetches those pages on a schedule, reads the price and the pack size, keeps a history, and tells you when a price falls past a threshold you set.') }}</p>
                        <p class="mt-2">{{ __('The prices come from the shops, not from us. A shop can change a page, block automated requests or state a price we read wrongly, and a shop is always the authority on what it charges. Check the price at the shop before you buy.') }}</p>
                    </section>

                    <section>
                        <h2>{{ __('Your account') }}</h2>
                        <ul class="mt-2">
                            <li>{{ __('You need a working email address, and you confirm it before the account is usable.') }}</li>
                            <li>{{ __('You are responsible for what happens under your account, so keep the password to yourself.') }}</li>
                            <li>{{ __('You can delete the account at any time. That removes your products, their history and your alerts.') }}</li>
                        </ul>
                    </section>

                    <section>
                        <h2>{{ __('Paying for Pro') }}</h2>
                        <ul class="mt-2">
                            <li>{{ __('Free costs nothing and needs no card. Pro is a monthly subscription, and the price on the pricing page includes VAT.') }}</li>
                            <li>{{ __('Payments are handled by Stripe. DipCatch never receives your card details.') }}</li>
                            @if ($trialDays > 0)
                                <li>{{ __('A :days-day trial is available once per account. Cancel before it ends and nothing is charged.', ['days' => $trialDays]) }}</li>
                            @endif
                            <li>{{ __('Cancel whenever you like. Cancelling stops the next payment, and Pro keeps working until the end of the period you already paid for. We do not refund part of a month.') }}</li>
                            <li>{{ __('Your statutory rights as a consumer in the EU are not affected by anything on this page.') }}</li>
                            <li>{{ __('If the price changes, you hear about it before it applies to you, and you can cancel.') }}</li>
                        </ul>
                    </section>

                    <section>
                        <h2>{{ __('Fair use') }}</h2>
                        <ul class="mt-2">
                            <li>{{ __('Track products you buy. Do not use DipCatch to build a copy of a shop’s catalogue or to resell its data.') }}</li>
                            <li>{{ __('Do not try to break the service, reach another account, or get around the limits of your plan.') }}</li>
                            <li>{{ __('Our crawler identifies itself, reads robots.txt and honours it. Do not ask us to point it at something that forbids it.') }}</li>
                            <li>{{ __('An account that does any of this can be suspended, and one that is charging back payments while still subscribed can be blocked.') }}</li>
                        </ul>
                    </section>

                    <section>
                        <h2>{{ __('What we do not promise') }}</h2>
                        <p class="mt-2">{{ __('DipCatch is a small service and is offered as it is. We do not promise it is always available, that every shop keeps working, or that an alert always arrives in time to catch an offer. Do not rely on it for anything where being late costs you money.') }}</p>
                        <p class="mt-2">{{ __('As far as the law allows, our liability is limited to what you paid us in the twelve months before the claim. Nothing here limits liability for intent or gross negligence.') }}</p>
                    </section>

                    <section>
                        <h2>{{ __('Changes and ending it') }}</h2>
                        <ul class="mt-2">
                            <li>{{ __('We can change these terms. A change that matters to you is announced by email before it takes effect.') }}</li>
                            <li>{{ __('We can stop offering the service. If that happens while you are paying, the part of the period you paid for and cannot use is refunded.') }}</li>
                            <li>{{ __('Dutch law applies, and a dispute goes to a competent Dutch court.') }}</li>
                        </ul>
                    </section>

                    <section>
                        <h2>{{ __('Data and questions') }}</h2>
                        <p class="mt-2">
                            {{ __('What we store about you is on the privacy page, and how to reach us is on the support page.') }}
                            <a href="{{ route('privacy', $langQuery) }}" class="font-medium text-zinc-900 underline underline-offset-4 hover:text-zinc-700 dark:text-zinc-100 dark:hover:text-zinc-300">{{ __('Privacy') }}</a>
                            ·
                            <a href="{{ route('support', $langQuery) }}" class="font-medium text-zinc-900 underline underline-offset-4 hover:text-zinc-700 dark:text-zinc-100 dark:hover:text-zinc-300">{{ __('Support') }}</a>
                        </p>
                    </section>
                </div>
            </main>

            <footer class="mx-auto w-full max-w-3xl px-6 pb-10 lg:px-8">
                <x-marketing-footer-links :lang-query="$langQuery" :contact-email="$contactEmail" :home="true" />
            </footer>
        </div>

        @fluxScripts
    </body>
</html>
