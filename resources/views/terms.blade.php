@php
    // Marketing locale: `?lang=nl|en` only, English otherwise (MarketingLocale).
    $locale = app()->getLocale();
    $requestedLang = \App\Http\Middleware\MarketingLocale::requested(request());
    $langQuery = $requestedLang === null ? [] : ['lang' => $requestedLang];
    $canonical = $locale === 'nl' ? route('terms', ['lang' => 'nl']) : route('terms');
    $contactEmail = config('site.contact_email');
    $operator = config('site.operator');
    $updated = config('site.terms_updated_at');
    $trialDays = \App\Billing\ProPrice::trialDays();
    $description = __('The agreement between you and DipCatch: what the service does, what it costs, and what neither side promises.');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth bg-canvas">
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
    <body class="min-h-dvh bg-linear-to-br from-canvas via-canvas to-soft-blush bg-fixed text-ink antialiased">
        <div class="flex min-h-dvh flex-col">
            <x-marketing-header />

            <main class="mx-auto w-full max-w-app flex-1 px-6 pt-8 pb-20 lg:px-8">
                <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">{{ __('Terms of service') }}</h1>
                @if (filled($updated))
                    <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Last updated :date', ['date' => $updated]) }}</p>
                @endif

                <div class="mt-8 max-w-3xl space-y-8 text-base text-zinc-700 [&_h2]:text-lg [&_h2]:font-semibold [&_h2]:text-ink [&_li]:mt-1 [&_ul]:list-disc [&_ul]:pl-5 dark:text-zinc-300">
                    <section>
                        <p>{{ __('DipCatch is a service of :name, :street, :city, the Netherlands, registered with the Dutch Chamber of Commerce (KvK) under number :kvk, VAT number :vat.', $operator) }}</p>
                        <p class="mt-2">{{ __('Using the service means you agree to what is on this page. It is written to be read, not to be impressive.') }}</p>
                    </section>

                    <section>
                        <h2>{{ __('What the service does') }}</h2>
                        <p class="mt-2">{{ __('You give DipCatch links to product pages. DipCatch fetches those pages on a schedule, reads the price and the pack size, keeps a history, and tells you when a price falls past a threshold you set.') }}</p>
                        <p class="mt-2">{{ __('The prices come from the shops, not from us. A shop can change a page, block automated requests or state a price we read wrongly, and a shop is always the authority on what it charges. Check the price at the shop before you buy.') }}</p>
                        <p class="mt-2">{{ __('Some features use AI: automatic categories, and the check that another shop sells the same product. AI can get it wrong, so check what it suggests before you rely on it.') }}</p>
                        <p class="mt-2">{{ __('Some links to bol.com and Amazon are affiliate links: when you buy after clicking one, that shop may pay DipCatch a commission. You pay the same. It never changes which shop DipCatch shows first, which depends on price alone. DipCatch is not a bol.com site, and bol.com has no control over it. As an Amazon Associate, DipCatch earns from qualifying purchases.') }}</p>
                    </section>

                    <section>
                        <h2>{{ __('Your account') }}</h2>
                        <ul class="mt-2">
                            <li>{{ __('You need to be 16 or older to use DipCatch.') }}</li>
                            <li>{{ __('You need a working email address, and you confirm it before the account is usable.') }}</li>
                            <li>{{ __('You are responsible for what happens under your account, so keep the password to yourself.') }}</li>
                            <li>{{ __('If you connect an AI assistant on Connections, it acts for you. A change it makes counts as a change you made.') }}</li>
                            <li>{{ __('You can delete the account at any time. That removes your products, their history and your alerts.') }}</li>
                        </ul>
                    </section>

                    <section>
                        <h2>{{ __('Paying for Pro') }}</h2>
                        <ul class="mt-2">
                            <li>{{ __('Free costs nothing and needs no card. Pro is a subscription paid per month, or per year where the pricing page offers it, and the price there includes VAT.') }}</li>
                            <li>{{ __('Payments are handled by Stripe. DipCatch never receives your card details.') }}</li>
                            @if ($trialDays > 0)
                                <li>{{ __('A :days-day trial is available once per account. Cancel before it ends and nothing is charged.', ['days' => $trialDays]) }}</li>
                            @endif
                            <li>{{ __('Cancel whenever you like. Cancelling stops the next payment, and Pro keeps working until the end of the period you already paid for. Apart from the right to withdraw below, we do not refund part of a month or a year.') }}</li>
                            <li>{{ __('Your statutory rights as a consumer in the EU are not affected by anything on this page.') }}</li>
                            <li>{{ __('If the price changes, you hear about it before it applies to you, and you can cancel.') }}</li>
                        </ul>
                    </section>

                    <section>
                        <h2>{{ __('Changing your mind') }}</h2>
                        <p class="mt-2">{{ __('As a consumer, you can withdraw from a Pro subscription within 14 days after you take it out, without giving a reason. Tell us through the contact form or by email, and we refund what you paid for it within 14 days.') }}</p>
                        @if ($trialDays > 0)
                            <p class="mt-2">{{ __('If it started with a trial, nothing has been charged yet, so cancelling is enough.') }}</p>
                        @endif
                    </section>

                    <section>
                        <h2>{{ __('Fair use') }}</h2>
                        <ul class="mt-2">
                            <li>{{ __('Track products you buy. Do not use DipCatch to build a copy of a shop’s catalogue or to resell its data.') }}</li>
                            <li>{{ __('Do not try to break the service, reach another account, or get around the limits of your plan.') }}</li>
                            <li>{{ __('Do not ask us to fetch pages a shop does not allow.') }}</li>
                            <li>{{ __('We can suspend or close an account that does any of this, that does not pay, or that the law requires us to block. An account closed for breaking these rules gets no refund.') }}</li>
                        </ul>
                    </section>

                    <section>
                        <h2>{{ __('Our rights and yours') }}</h2>
                        <ul class="mt-2">
                            <li>{{ __('DipCatch, its software and design, and the price data we collect are ours.') }}</li>
                            <li>{{ __('What you write yourself, such as notes, stays yours. You give us the right to use it, and the links you add, to run DipCatch.') }}</li>
                            <li>{{ __('We may use price data in anonymised form, for example for statistics.') }}</li>
                        </ul>
                    </section>

                    <section>
                        <h2>{{ __('What we do not promise') }}</h2>
                        <p class="mt-2">{{ __('DipCatch is a small service and is offered as it is. We do not promise it is always available, that every shop keeps working, or that an alert always arrives in time to catch an offer. Do not rely on it for anything where being late costs you money.') }}</p>
                        <p class="mt-2">{{ __('As far as the law allows, our liability is limited to what you paid us in the twelve months before the claim. Nothing here limits liability for intent or gross negligence.') }}</p>
                        <p class="mt-2">{{ __('As far as the law allows, we are not liable for indirect loss, such as a missed offer or lost profit.') }}</p>
                    </section>

                    <section>
                        <h2>{{ __('Changes and ending it') }}</h2>
                        <ul class="mt-2">
                            <li>{{ __('We can change these terms. We email you about a change that matters to you in good time before it applies. If you do not agree, you can cancel and delete your account before that date.') }}</li>
                            <li>{{ __('We can change, add or remove features, for example when a shop changes its site or a provider stops. We can also change what Free and Pro include. If a change makes Pro worse for you, we tell you in good time, and you can cancel.') }}</li>
                            <li>{{ __('We can stop offering the service. If that happens while you are paying, the part of the period you paid for and cannot use is refunded.') }}</li>
                            <li>{{ __('We can close a Free account that has not been used for a year. We email you a warning first.') }}</li>
                            <li>{{ __('We can transfer this agreement to another company that takes over DipCatch. We tell you first, and you can cancel if you do not want that.') }}</li>
                            <li>{{ __('Dutch law applies, and a dispute goes to a competent Dutch court.') }}</li>
                            <li>{{ __('If you are a consumer, you also keep the protection of the mandatory law of the country where you live, and you can take a dispute to a court there.') }}</li>
                        </ul>
                    </section>

                    <section>
                        <h2>{{ __('Data, questions and complaints') }}</h2>
                        <p class="mt-2">
                            {{ __('What we store about you is on the privacy page, and how to reach us is on the support page.') }}
                            <a href="{{ route('privacy', $langQuery) }}" class="font-medium text-ink underline underline-offset-4 hover:text-brand">{{ __('Privacy') }}</a>
                            ·
                            <a href="{{ route('support', $langQuery) }}" class="font-medium text-ink underline underline-offset-4 hover:text-brand">{{ __('Support') }}</a>
                        </p>
                        <p class="mt-2">
                            {{ __('Send a complaint through the contact form on the support page.') }}
                            @if (filled($contactEmail))
                                {{ __('Or email') }} <a href="mailto:{{ $contactEmail }}" class="font-medium text-ink underline underline-offset-4 hover:text-brand">{{ $contactEmail }}</a>.
                            @endif
                            {{ __('We confirm it and usually reply within 14 days.') }}
                        </p>
                    </section>
                </div>
            </main>

            <footer class="mx-auto w-full max-w-app px-6 pb-10 lg:px-8">
                <x-marketing-footer-links :lang-query="$langQuery" :contact-email="$contactEmail" :home="true" />
            </footer>
        </div>

        @fluxScripts
    </body>
</html>
