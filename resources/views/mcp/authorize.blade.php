{{-- Passport's consent screen. Self-contained: it renders during an OAuth
     redirect from a client we do not control, so it takes no layout and no
     Livewire. `state` must be echoed back on both forms or the client
     rejects the callback. --}}
<!DOCTYPE html>
<html lang="en" class="scroll-smooth bg-amber-50 dark:bg-zinc-950">
    <head>
        @include('partials.head', [
            'title' => __('Authorise access'),
            'robots' => 'noindex',
        ])
    </head>
    <body class="min-h-dvh bg-linear-to-br from-amber-50 to-rose-50 bg-fixed text-zinc-900 antialiased dark:from-zinc-950 dark:to-zinc-950 dark:text-zinc-50">
        <div class="mx-auto flex min-h-dvh w-full max-w-lg flex-col justify-center px-6 py-12">
            <div class="rounded-3xl bg-white/80 p-8 ring-1 ring-zinc-200 backdrop-blur-sm dark:bg-zinc-900/60 dark:ring-zinc-800">
                <div class="flex items-center gap-2 font-semibold">
                    <x-app-logo-icon class="size-6 fill-current" />
                    {{ config('app.name') }}
                </div>

                <h1 class="mt-6 text-2xl font-semibold tracking-tight">
                    {{ __('Give :client access to your account?', ['client' => $client->name]) }}
                </h1>

                <p class="mt-3 text-sm text-zinc-600 dark:text-zinc-300">
                    {{ __('It will be able to read the products you track and add new ones, as :email. It cannot see your password or your payment details.', ['email' => $user->email]) }}
                </p>

                @if (count($scopes) > 0)
                    <ul class="mt-4 space-y-1 text-sm text-zinc-600 dark:text-zinc-300">
                        @foreach ($scopes as $scope)
                            <li>&middot; {{ $scope->description }}</li>
                        @endforeach
                    </ul>
                @endif

                <p class="mt-4 text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __('You can withdraw this at any time from Connections in your account.') }}
                </p>

                <div class="mt-8 flex flex-wrap items-center gap-3">
                    <form method="post" action="{{ route('passport.authorizations.approve') }}">
                        @csrf
                        <input type="hidden" name="state" value="{{ $request->state }}">
                        <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                        <input type="hidden" name="auth_token" value="{{ $authToken }}">
                        <button type="submit" class="inline-flex items-center rounded-full bg-zinc-900 px-5 py-2.5 text-sm font-medium text-white shadow-md hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200">{{ __('Allow') }}</button>
                    </form>

                    <form method="post" action="{{ route('passport.authorizations.deny') }}">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="state" value="{{ $request->state }}">
                        <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                        <input type="hidden" name="auth_token" value="{{ $authToken }}">
                        <button type="submit" class="inline-flex items-center rounded-full bg-white/80 px-5 py-2.5 text-sm font-medium text-zinc-700 ring-1 ring-zinc-200 hover:text-zinc-900 dark:bg-zinc-900/60 dark:text-zinc-200 dark:ring-zinc-800">{{ __('Cancel') }}</button>
                    </form>
                </div>
            </div>
        </div>
    </body>
</html>
