{{--
    The size the owner states for the chosen pack: "How much is in this pack?",
    and "your size" with change and remove once stated. Inside x-unit-target
    or x-pack-target, whose `pack()` is the chosen chip. `$ask` shows the
    field before a size is stated: while no shop can be compared per unit.
    Saved at once, by its own action, not by the form's save.
--}}
{{-- A saved size re-renders the whole form: focus goes back to the question above. --}}
<div x-data="{ editing: false }" x-on:alert-size-saved.window="$nextTick(() => document.getElementById('pack-choice-question')?.focus())" class="space-y-2" data-test="owner-pack-size">
    <p x-show="pack().ownerSize && ! editing" x-cloak class="text-base/7 text-zinc-600 sm:text-sm/6 dark:text-zinc-300" data-test="owner-pack-size-line">
        <span x-text="pack().shopSays
            ? @js(__('The shop now says')) + ' ' + pack().shopSays + '; ' + @js(__('your size:')) + ' ' + pack().ownerSize + '.'
            : @js(__('Your size:')) + ' ' + pack().ownerSize + '.'"></span>
        <button type="button" x-ref="change" @click="editing = true; $wire.set('packSizeInput', pack().ownerSize, false); $nextTick(() => requestAnimationFrame(() => $refs.sizeInput.focus()))" class="ms-1 font-medium underline underline-offset-4" data-test="owner-pack-size-change">{{ __('Change') }}<span class="sr-only"> {{ __('your size') }}</span></button>
        <button type="button" @click="$wire.removeOwnerPackSize(pack().shopId)" class="ms-1 text-zinc-500 underline underline-offset-4 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white" data-test="owner-pack-size-remove">{{ __('Remove') }}<span class="sr-only"> {{ __('your size') }}</span></button>
    </p>

    <div x-show="editing || (@js($ask) && ! pack().ownerSize)" @if (! $ask) x-cloak @endif>
        <label for="owner-pack-size" class="text-base/7 font-medium text-zinc-900 sm:text-sm/6 dark:text-white">{{ __('How much is in this pack?') }}</label>
        <p id="owner-pack-size-hint" class="max-w-[65ch] text-base/7 text-pretty text-zinc-500 sm:text-sm/6 dark:text-zinc-400">
            {{ __('For example 660 g, 1 l or 4 pieces. It counts for this shop only, until the shop states a size itself.') }}
        </p>
        <div class="mt-2 flex flex-wrap items-center gap-2">
            <input
                id="owner-pack-size"
                type="text"
                x-ref="sizeInput"
                aria-describedby="owner-pack-size-hint"
                @error('packSizeInput') aria-invalid="true" @enderror
                wire:model="packSizeInput"
                @keydown.enter.prevent="$wire.saveOwnerPackSize(pack().shopId)"
                placeholder="660 g"
                autocomplete="off"
                class="w-36 rounded-lg bg-white px-2.5 py-1.5 text-base/6 ring-1 placeholder:text-zinc-500 ring-zinc-950/10 focus:outline-2 focus:-outline-offset-1 focus:outline-zinc-900 sm:text-sm/6 dark:bg-white/5 dark:ring-white/10 dark:focus:outline-white"
                data-test="owner-pack-size-input"
            />
            <flux:button size="sm" type="button" x-on:click="$wire.saveOwnerPackSize(pack().shopId)" data-test="owner-pack-size-save">{{ __('Save size') }}</flux:button>
            <flux:button size="sm" variant="ghost" type="button" x-show="editing" x-on:click="editing = false; $nextTick(() => $refs.change?.focus())">{{ __('Cancel') }}</flux:button>
        </div>
        <flux:error name="packSizeInput" class="mt-2" />
    </div>
</div>
