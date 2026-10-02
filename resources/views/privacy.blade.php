@php
    // Marketing locale: `?lang=nl|en` only, English otherwise (MarketingLocale).
    // The bare URL is the canonical English page, `?lang=nl` the canonical
    // Dutch one, and `?lang=en` a duplicate that points back at the bare URL.
    $locale = app()->getLocale();
    $requestedLang = \App\Http\Middleware\MarketingLocale::requested(request());
    $langQuery = $requestedLang === null ? [] : ['lang' => $requestedLang];
    $canonical = $locale === 'nl' ? route('privacy', ['lang' => 'nl']) : route('privacy');
    $contactEmail = config('site.contact_email');
    $operator = config('site.operator');
    $updated = config('site.privacy_updated_at');
    $description = __('What DipCatch stores about you, why, and how to get rid of it.');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth bg-canvas">
    <head>
        @include('partials.head', [
            'title' => __('Privacy'),
            'description' => $description,
            'canonical' => $canonical,
        ])
        <link rel="alternate" hreflang="en" href="{{ route('privacy') }}">
        <link rel="alternate" hreflang="nl" href="{{ route('privacy', ['lang' => 'nl']) }}">
        <link rel="alternate" hreflang="x-default" href="{{ route('privacy') }}">
        {{ \App\Support\JsonLd::script(\App\Support\StructuredData::privacy($canonical, $description)) }}
    </head>
    <body class="min-h-dvh bg-linear-to-br from-canvas via-canvas to-soft-blush bg-fixed text-ink antialiased">
        <div class="flex min-h-dvh flex-col">
            <x-marketing-header />


            <main class="mx-auto w-full max-w-app flex-1 px-6 pt-8 pb-20 lg:px-8">
                <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">{{ __('Privacy') }}</h1>
                @if (filled($updated))
                    <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Last updated :date', ['date' => $updated]) }}</p>
                @endif

                <div class="mt-8 max-w-3xl space-y-8 text-base text-zinc-700 [&_h2]:text-lg [&_h2]:font-semibold [&_h2]:text-ink [&_li]:mt-1 [&_ul]:list-disc [&_ul]:pl-5 dark:text-zinc-300">
                    <section>
                        <p>{{ __('DipCatch is a price-tracking service of :name, :street, :city, the Netherlands, KvK :kvk. :name is responsible for the personal data on this page.', $operator) }}</p>
                        <p class="mt-2">{{ __('This page says what we store about you, why, and how to get rid of it. We keep it short on purpose; if something is unclear, ask.') }}</p>
                    </section>

                    <section>
                        <h2>{{ __('What we store') }}</h2>
                        <ul class="mt-2">
                            <li><strong>{{ __('Account') }}:</strong> {{ __('your name, email address, a hashed password, your timezone and default currency, and your notification preferences. Optional: two-factor secrets, passkeys, browser-push subscriptions, and a link to your Google or Apple account — all of which you turn on yourself.') }}</li>
                            <li><strong>{{ __('Pro') }}:</strong> {{ __('your plan, the state of your subscription, Stripe’s reference for you, and a record of each payment: the amount and the date. Your card details go to Stripe, never to us.') }}</li>
                            <li><strong>{{ __('Tracked products') }}:</strong> {{ __('the product links you paste, the titles, images and prices we read from those pages, the price history, and any private notes you add.') }}</li>
                            <li><strong>{{ __('Alerts') }}:</strong> {{ __('which price drops we told you about, and when.') }}</li>
                            <li><strong>{{ __('Hidden shops') }}:</strong> {{ __('the shops you chose not to have suggested.') }}</li>
                            <li><strong>{{ __('Pages we could not read') }}:</strong> {{ __('when you try to add a shop page DipCatch cannot read, its web address, why it failed and who tried it last, so we can make that shop work. We keep it for six months after the last try.') }}</li>
                            <li><strong>{{ __('Searches without results') }}:</strong> {{ __('when a search in the app finds nothing, the words you searched for and how often, so we can see what is missing. We keep them for six months after the last such search.') }}</li>
                            <li><strong>{{ __('Technical') }}:</strong> {{ __('a session cookie to keep you signed in, and short-lived rate-limit counters keyed on your IP address to protect the shops we read prices from and this service.') }}</li>
                        </ul>
                    </section>

                    <section>
                        <h2>{{ __('What we do with it') }}</h2>
                        <p class="mt-2">{{ __('Run the service: check the prices of the products you track, work out the cheapest shop, and send you the alerts you asked for. Now and then we also email you about DipCatch itself, such as a new feature. Every such email has a link to stop them. We do not sell data, we do not build advertising profiles, and we do not use tracking cookies or analytics scripts on this site.') }}</p>
                    </section>

                    <section>
                        <h2>{{ __('Why we may use it') }}</h2>
                        <ul class="mt-2">
                            <li>{{ __('To give you the service you signed up for: your account, the products you track, your alerts and your payments.') }}</li>
                            <li>{{ __('Because you switched it on: browser push, an AI feature, or a connected assistant. Switch it off and that use stops.') }}</li>
                            <li>{{ __('Because we need a working and safe service: rate limits, error reports, and the lists of pages we could not read and of searches without results.') }}</li>
                            <li>{{ __('Because the law requires it: payment records for the tax authorities.') }}</li>
                        </ul>
                    </section>

                    <section>
                        <h2>{{ __('Who else sees it') }}</h2>
                        <ul class="mt-2">
                            <li><strong>Laravel Cloud:</strong> {{ __('hosts the application and database.') }}</li>
                            <li><strong>Stripe:</strong> {{ __('takes Pro payments and makes the invoices. It receives your name, email address and payment details.') }}</li>
                            <li><strong>Sentry:</strong> {{ __('stores error reports so we can fix bugs. A report shows what the app was doing when it failed, and can contain a product link or your email address.') }}</li>
                            <li><strong>Resend:</strong> {{ __('delivers our email (verification, password reset, the daily digest).') }}</li>
                            <li><strong>{{ __('Your browser’s push service') }}:</strong> {{ __('only if you turn on browser push; it receives the alert payloads.') }}</li>
                            <li><strong>Google:</strong> {{ __('shop logos on this site are loaded from Google’s favicon service by your browser, which sees the shop domain and your IP address. That request carries no account data. Separately, if you sign in with Google, Google sees that you signed in to DipCatch and sends us your Google profile. We keep your name, email address and Google account id from it.') }}</li>
                            <li><strong>Apple:</strong> {{ __('if you sign in with Apple, Apple sees that you signed in to DipCatch and sends us your Apple account id and an email address — your own, or a relay address Apple forwards from if you asked Apple to hide yours. It sends your name the first time you sign in, and never again.') }}</li>
                            <li><strong>TypeSafe:</strong> {{ __('our AI provider, only if you switch on an AI feature in your settings (automatic categories, or the check on a new shop, which also suggests an alert for a new product). It receives the product name, its shops and their web addresses, the pack size, barcode and price, any offer a shop shows, and the category of the product. Nothing that identifies you or your account.') }}</li>
                            <li><strong>Serper:</strong> {{ __('only if you have Pro with the AI check on a new shop switched on. It receives the names of the products you track, and searches Google for other shops that sell them. Nothing that identifies you or your account. We then read the product pages it finds from our servers, as we do for the shops you track.') }}</li>
                            <li><strong>bol.com:</strong> {{ __('to suggest bol.com as a shop, we ask bol.com whether it sells the products you track, by their name and barcode. Nothing that identifies you or your account. A bol.com link in the app goes through bol.com’s partner link, so bol.com can credit DipCatch with a sale. bol.com then sets its own cookie in your browser, under its own privacy statement. We send it nothing about you.') }}</li>
                            <li><strong>Amazon:</strong> {{ __('a link to amazon.nl in the app carries DipCatch’s partner tag, so Amazon can credit DipCatch with a sale. Amazon then sets its own cookie in your browser, under its own privacy statement. We send it nothing about you.') }}</li>
                            <li><strong>{{ __('The shops') }}:</strong> {{ __('we fetch product pages from the shops you track. Those requests come from our servers, not from you, and carry nothing about you.') }}</li>
                            <li><strong>{{ __('An assistant you connect') }}:</strong> {{ __('If you connect Claude, ChatGPT, or another assistant on Connections, DipCatch sends that assistant the product data and tool results it asks for, and the assistant can change the products you track. Disconnect it on Connections to stop new access. That does not delete chats or other copies the assistant’s provider already stored. DipCatch does not send your password to the assistant.') }}</li>
                        </ul>
                        <p class="mt-2">{{ __('Some of these companies process data outside the European Economic Area, for example in the United States. Such a transfer rests on the EU–US Data Privacy Framework or on the EU standard contractual clauses.') }}</p>
                        <p class="mt-2">{{ __('If you share a product page, anyone with that link can see the product, its prices and the shops. It says nothing about your account.') }}</p>
                        <p class="mt-2">{{ __('Product images on a shared page are loaded straight from the shop’s own servers by the viewer’s browser, so that shop sees the viewer’s IP address. We do not copy or store the images.') }}</p>
                    </section>

                    <section>
                        <h2>{{ __('How long') }}</h2>
                        <p class="mt-2">{{ __('As long as you have an account. Price-check history older than a year is pruned, keeping the most recent points so your charts keep working. Delete a product and its history goes with it. Delete your account (Settings → Profile) and everything goes.') }}</p>
                        <p class="mt-2">{{ __('Payment records are the exception. They stay after you delete your account, without your name, because Dutch tax law requires us to keep them for seven years. Stripe keeps its own records under the same kind of rules.') }}</p>
                    </section>

                    <section>
                        <h2>{{ __('Your rights') }}</h2>
                        <p class="mt-2">{{ __('Under the GDPR you can ask for a copy of your data, also as a file you can take elsewhere. You can have it corrected or deleted, limit its use, or object to its use. You can already change or delete most of it yourself in the app. We answer a request within a month, or tell you within that month why it takes longer.') }}
                            @if (filled($contactEmail))
                                {{ __('For anything else, email') }} <a href="mailto:{{ $contactEmail }}" class="font-medium text-ink underline underline-offset-4 hover:text-brand">{{ $contactEmail }}</a>.
                            @endif
                        </p>
                        <p class="mt-2">
                            {{ __('If you think we handle your data wrongly, you can complain to the Dutch data protection authority,') }}
                            <a href="https://autoriteitpersoonsgegevens.nl" class="font-medium text-ink underline underline-offset-4 hover:text-brand">Autoriteit Persoonsgegevens</a>.
                        </p>
                    </section>

                    <section>
                        <h2>{{ __('Security') }}</h2>
                        <p class="mt-2">{{ __('The site only works over HTTPS. Passwords are stored hashed, and you can add two-factor sign-in or a passkey. If a data breach puts you at high risk, we tell you, as the law requires.') }}</p>
                    </section>

                    <section>
                        <h2>{{ __('Changes') }}</h2>
                        <p class="mt-2">{{ __('When this page changes, so does the date at the top. We email you about a big change.') }}</p>
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
