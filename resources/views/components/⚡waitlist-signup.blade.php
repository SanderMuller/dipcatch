<?php declare(strict_types=1);

use App\Models\WaitlistSignup;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    #[Validate(['required', 'email:rfc', 'max:255'])]
    public string $email = '';

    /**
     * Honeypot. Real humans never see (or fill) this field — it's hidden via
     * absolute off-screen positioning + tabindex="-1" + aria-hidden. Bots that
     * blindly fill every input will trip it; we then short-circuit to the
     * success state without writing a row, so the bot has no signal it was
     * caught.
     */
    public string $company = '';

    public bool $submitted = false;

    public function submit(): void
    {
        if ($this->company !== '') {
            $this->submitted = true;
            $this->reset('email', 'company');

            return;
        }

        $this->validate();

        $key = 'waitlist:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => __('Too many attempts. Please try again later.'),
            ]);
        }

        RateLimiter::hit($key, decaySeconds: 3600);

        WaitlistSignup::query()->firstOrCreate(
            ['email' => mb_strtolower(trim($this->email))],
            [
                'ip_address' => request()->ip(),
                'user_agent' => mb_substr((string) request()->userAgent(), 0, 255),
            ],
        );

        $this->submitted = true;
        $this->reset('email');
    }
}; ?>

<div class="w-full max-w-md">
    @if ($submitted)
        <div class="rounded-2xl bg-emerald-50 p-4 ring-1 ring-emerald-200 dark:bg-emerald-950/40 dark:ring-emerald-900">
            <p class="text-sm font-semibold text-emerald-900 dark:text-emerald-100">{{ __('You\'re on the list.') }}</p>
            <p class="mt-1 text-sm text-emerald-800 dark:text-emerald-300">{{ __('We\'ll email you when DipCatch opens up.') }}</p>
        </div>
    @else
        <form wire:submit="submit" class="flex flex-col gap-2 sm:flex-row" novalidate>
            <div class="pointer-events-none absolute -left-[9999px] size-px overflow-hidden" aria-hidden="true">
                <label for="waitlist-company">{{ __('Company') }}</label>
                <input
                    id="waitlist-company"
                    wire:model="company"
                    type="text"
                    tabindex="-1"
                    autocomplete="off"
                />
            </div>
            <label class="sr-only" for="waitlist-email">{{ __('Email') }}</label>
            <input
                id="waitlist-email"
                wire:model="email"
                type="email"
                required
                autocomplete="email"
                placeholder="{{ __('you@example.com') }}"
                class="flex-1 rounded-full bg-white/90 px-4 py-2.5 text-sm text-zinc-900 ring-1 ring-zinc-200 outline-none placeholder:text-zinc-400 focus-visible:ring-2 focus-visible:ring-emerald-500 dark:bg-zinc-900/80 dark:text-zinc-100 dark:ring-zinc-800 dark:placeholder:text-zinc-500"
            >
            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="submit"
                class="inline-flex items-center justify-center rounded-full bg-zinc-900 px-5 py-2.5 text-sm font-medium text-white shadow-md hover:bg-zinc-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900 disabled:opacity-60 dark:bg-white dark:text-zinc-900 dark:shadow-none dark:hover:bg-zinc-200"
            >
                <span wire:loading.remove wire:target="submit">{{ __('Join wait list') }}</span>
                <span wire:loading wire:target="submit">{{ __('Joining…') }}</span>
            </button>
        </form>
        @error('email')
            <p class="mt-2 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>
        @enderror
    @endif
</div>
