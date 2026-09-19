@php
    // Marketing locale: `?lang=nl|en` only, English otherwise (MarketingLocale).
    $locale = app()->getLocale();
    $requestedLang = \App\Http\Middleware\MarketingLocale::requested(request());
    $langQuery = $requestedLang === null ? [] : ['lang' => $requestedLang];
    $canonical = $locale === 'nl' ? route('support', ['lang' => 'nl']) : route('support');
    $contactEmail = config('site.contact_email');
    $trialDays = \App\Billing\ProPrice::trialDays();
    $description = __('How to reach DipCatch, what to send with a question, and where to change or cancel a subscription.');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth bg-amber-50 dark:bg-zinc-950">
    <head>
        @include('partials.head', [
            'title' => __('Support'),
            'description' => $description,
            'canonical' => $canonical,
        ])
        <link rel="alternate" hreflang="en" href="{{ route('support') }}">
        <link rel="alternate" hreflang="nl" href="{{ route('support', ['lang' => 'nl']) }}">
        <link rel="alternate" hreflang="x-default" href="{{ route('support') }}">
        {{ \App\Support\JsonLd::script(\App\Support\StructuredData::support($canonical, $description)) }}
    </head>
    <body class="min-h-dvh bg-linear-to-br from-amber-50 to-rose-50 bg-fixed text-zinc-900 antialiased dark:from-zinc-950 dark:to-zinc-950 dark:text-zinc-50">
        <div class="flex min-h-dvh flex-col">
            <x-marketing-header width="max-w-3xl" />

            <main class="mx-auto w-full max-w-3xl flex-1 px-6 pt-8 pb-20 lg:px-8">
                <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">{{ __('Support') }}</h1>
                <p class="mt-3 max-w-[60ch] text-lg text-pretty text-zinc-600 dark:text-zinc-300">{{ $description }}</p>

                <div class="mt-8 space-y-8 text-base text-zinc-700 [&_h2]:text-lg [&_h2]:font-semibold [&_h2]:text-zinc-900 [&_li]:mt-1 [&_ul]:list-disc [&_ul]:pl-5 dark:text-zinc-300 dark:[&_h2]:text-zinc-50">
                    <section>
                        <h2>{{ __('Getting in touch') }}</h2>
                        {{-- The form is the real channel: it asks what kind of
                             question this is and routes it. It lives behind
                             sign-in, so say that rather than let a guest hit
                             the login wall without warning. --}}
                        <p class="mt-2">
                            {{ __('The contact form in the app is the quickest way to reach us. It asks what kind of question you have, so it lands in the right place.') }}
                            <a href="{{ route('app.support') }}" class="font-medium text-zinc-900 underline underline-offset-4 hover:text-zinc-700 dark:text-zinc-100 dark:hover:text-zinc-300">{{ __('Open the contact form') }}</a>{{ __(' (sign in first).') }}
                        </p>
                        @if (filled($contactEmail))
                            <p class="mt-2">
                                {{ __('No account? Email us instead:') }}
                                <a href="mailto:{{ $contactEmail }}" class="font-medium text-zinc-900 underline underline-offset-4 hover:text-zinc-700 dark:text-zinc-100 dark:hover:text-zinc-300">{{ $contactEmail }}</a>
                            </p>
                        @endif
                        <p class="mt-2">{{ __('A question about one product is easiest to answer with the link to that product page and the shop it is at. Say what you expected to see and what you saw instead.') }}</p>
                    </section>

                    <section>
                        <h2>{{ __('A shop that will not read') }}</h2>
                        <p class="mt-2">{{ __('Before anything is saved, DipCatch shows you the name, the price and the pack size it read. A shop it cannot read says so right there, not weeks later.') }}</p>
                        <ul class="mt-2">
                            <li>{{ __('Check that the link is the product page itself, not a search result or a category.') }}</li>
                            <li>{{ __('Some shops do not let us look at all, and at some the price only appears a moment after the page opens. We cannot read either of those.') }}</li>
                            <li>{{ __('When a shop that used to work stops working, we store nothing rather than a wrong price. The shop is marked on the product page.') }}</li>
                        </ul>
                        <p class="mt-2">
                            {{ __('Send us a product link if pasting one does not pick up the price. We set a shop up ourselves when its pages are too tricky to read. Those shops are listed on the supported shops page.') }}
                            <x-shop-request-link class="font-medium text-zinc-900 hover:text-zinc-700 dark:text-zinc-100 dark:hover:text-zinc-300" />
                        </p>
                    </section>

                    <section>
                        <h2>{{ __('Plans, payment and cancelling') }}</h2>
                        <ul class="mt-2">
                            <li>{{ __('Free needs no card and does not expire.') }}</li>
                            <li>{{ __('Pro is billed monthly through Stripe, VAT included, and can be cancelled at any time from Plan & billing inside the app.') }}</li>
                            <li>{{ __('Cancelling stops the next payment. Pro keeps working until the end of the period already paid for.') }}</li>
                            @if ($trialDays > 0)
                                <li>{{ __('The :days-day trial runs once per account. An account that has subscribed before goes straight to a paid subscription.', ['days' => $trialDays]) }}</li>
                            @endif
                            <li>{{ __('Card details are handled by Stripe. DipCatch never sees them.') }}</li>
                        </ul>
                        <p class="mt-2">{{ __('If a payment went wrong, email us with the date and the amount and we will sort it out.') }}</p>
                    </section>

                    <section>
                        <h2>{{ __('Your account and your data') }}</h2>
                        <p class="mt-2">{{ __('You can change your notification settings, your timezone and your password in the app, and delete the account there as well. Deleting it removes the products, the price history and the alerts with it.') }}</p>
                        <p class="mt-2">
                            {{ __('What we store and why is set out on the privacy page.') }}
                            <a href="{{ route('privacy', $langQuery) }}" class="font-medium text-zinc-900 underline underline-offset-4 hover:text-zinc-700 dark:text-zinc-100 dark:hover:text-zinc-300">{{ __('Privacy') }}</a>
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
