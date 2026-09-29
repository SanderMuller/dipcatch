{{-- Rendered when the application throws. Keep it to the head partial, the
     heading, one sentence and the links: whatever failed may still be
     failing, so this page asks nothing of the database or the session. --}}
@php
    $contactEmail = config('site.contact_email');
@endphp
<!DOCTYPE html>
<html lang="en" class="scroll-smooth bg-canvas">
    <head>
        @include('partials.head', [
            'title' => __('Something went wrong'),
            'description' => __('This page could not be loaded.'),
            'robots' => 'noindex',
        ])
    </head>
    <body class="min-h-dvh bg-linear-to-br from-canvas via-canvas to-soft-blush bg-fixed text-ink antialiased">
        <x-error-page
            :code="500"
            :heading="__('Something went wrong on our side')"
            :body="__('The error is logged and we are looking at it. Try again in a moment.')"
        >
            <x-error-page.link :href="url('/')">{{ __('Go to the homepage') }}</x-error-page.link>

            @if (filled($contactEmail))
                <x-error-page.link :href="'mailto:' . $contactEmail">{{ __('Contact us') }}</x-error-page.link>
            @endif
        </x-error-page>
    </body>
</html>
