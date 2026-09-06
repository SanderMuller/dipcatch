{{-- An unmatched URL throws before the `web` group runs, so there is no
     session and no `auth()->check()` here. A controller that throws 404 does
     have one, so the page must read the same either way: same links for
     everyone. No marketing header — an error page is not the place to
     discover a second failure. --}}
<!DOCTYPE html>
<html lang="en" class="scroll-smooth bg-amber-50 dark:bg-zinc-950">
    <head>
        @include('partials.head', [
            'title' => __('Page not found'),
            'description' => __('That page does not exist.'),
            'robots' => 'noindex',
        ])
    </head>
    <body class="min-h-dvh bg-linear-to-br from-amber-50 to-rose-50 bg-fixed text-zinc-900 antialiased dark:from-zinc-950 dark:to-zinc-950 dark:text-zinc-50">
        <x-error-page
            :code="404"
            :heading="__('This page does not exist')"
            :body="__('The link may be old, or the address may have a typo.')"
        >
            <x-error-page.link :href="url('/')">{{ __('Go to the homepage') }}</x-error-page.link>
            <x-error-page.link :href="url('/pricing')">{{ __('Pricing') }}</x-error-page.link>
        </x-error-page>
    </body>
</html>
