@props([
    'model',
    'description' => null,
    'upgrade' => false,
    'packs',
    'history' => null,
    'currency' => 'EUR',
    'unitWord' => 'unit',
])

{{--
    Set in pack prices, stored as one price per unit that every shop and pack
    size is held to. `packs` comes from UnitTargetGuide::packs(), in the
    best-value ranking's order so the first is the best value, and must not
    be empty.
--}}
<div
    {{ $attributes->class('space-y-6') }}
    x-data="{
        target: $wire.entangle(@js($model)),
        packs: @js($packs),
        history: @js($history),
        currency: @js($currency),
        unitWord: @js($unitWord),
        chosen: 0,
        packInput: '',
        init() {
            this.syncPack();
            this.$watch('chosen', () => this.syncPack());
            // A target set from elsewhere — the switch from a drop alert, a
            // level — fills the price box too. One the box itself produced
            // is left as typed.
            this.$watch('target', () => {
                if (document.activeElement === this.$refs.packPrice) {
                    return;
                }

                const typed = parseFloat(this.packInput);

                if (isNaN(typed) || this.floor(typed / this.pack().perPack) !== this.unit()) {
                    this.syncPack();
                }
            });
        },
        money(value, perUnit = false) {
            if (value === null || value === undefined || ! isFinite(value)) {
                return '—';
            }

            return new Intl.NumberFormat('en', { style: 'currency', currency: this.currency, minimumFractionDigits: 2, maximumFractionDigits: perUnit && value < 1 ? 4 : 2 }).format(value);
        },
        pack() {
            return this.packs[this.chosen];
        },
        best() {
            return this.packs[0];
        },
        unit() {
            const value = parseFloat(this.target);

            return isNaN(value) || value <= 0 ? null : value;
        },
        bestUnit() {
            return this.best()?.unitPrice ?? null;
        },
        // Only a low below today's best: a level at today's price would alert
        // on the next check.
        lowest() {
            return this.history === null || this.history.lowest >= (this.bestUnit() ?? 0) * 0.99 ? null : this.history.lowest;
        },
        // How much cheaper per unit the best pack is than the chosen one, in
        // whole percent; 0 when the chosen pack is the best.
        cheaperElsewhere() {
            const mine = this.pack()?.unitPrice;

            if (! mine || this.bestUnit() === null || this.chosen === 0 || mine <= this.bestUnit()) {
                return 0;
            }

            return Math.round((1 - this.bestUnit() / mine) * 100);
        },
        levels() {
            if (this.bestUnit() === null) {
                return [];
            }

            const candidates = [
                { name: @js(__('10% under today’s best')), unit: this.bestUnit() * 0.9 },
                { name: @js(__('20% under today’s best')), unit: this.bestUnit() * 0.8 },
            ];

            if (this.lowest() === null) {
                candidates.push({ name: @js(__('30% under today’s best')), unit: this.bestUnit() * 0.7 });
            } else {
                candidates.push({ name: @js(__('Back at the low on the chart')), unit: this.lowest() });
                candidates.push({ name: @js(__('Under the low on the chart')), unit: this.lowest() * 0.95 });
            }

            // Easiest to reach first, and a level within 2% of one already
            // listed is the same level twice.
            const levels = [];

            candidates.sort((a, b) => b.unit - a.unit).forEach((level) => {
                if (levels.every((kept) => Math.abs(kept.unit - level.unit) / kept.unit > 0.02)) {
                    levels.push(level);
                }
            });

            return levels;
        },
        packPrice(pack) {
            return this.unit() === null ? null : this.unit() * pack.perPack;
        },
        // Rounded down to the four decimals the column keeps, so a target never
        // ends up above the price it was set from.
        floor(value) {
            return Math.floor(value * 10000) / 10000;
        },
        isLevel(level) {
            return this.unit() !== null && Math.abs(this.unit() - this.floor(level.unit)) < 0.00005;
        },
        setUnit(value) {
            const rounded = value === null || isNaN(value) ? 0 : this.floor(value);
            this.target = rounded <= 0 ? null : String(rounded);
        },
        setFromPack(price) {
            this.setUnit(parseFloat(price) / this.pack().perPack);
        },
        syncPack() {
            this.packInput = this.unit() === null ? '' : this.packPrice(this.pack()).toFixed(2);
        },
    }"
    data-test="unit-target"
>
    {{-- Rendered on the server, so the rule and today's figure read without
         the script. --}}
    <div>
        <flux:heading>{{ __('Price alert') }}</flux:heading>
        <p class="mt-1 max-w-[65ch] text-base/7 text-pretty text-zinc-500 sm:text-sm/6 dark:text-zinc-400">
            {{ __('Any shop, any pack size, at the same price per :unit.', ['unit' => $unitWord]) }}
            {{ $description }}
            @if ($upgrade)
                <flux:link :href="route('upgrade')">{{ __('Get Pro') }}</flux:link>
            @endif
        </p>
    </div>

    <div x-show="packs.length > 1">
        <p class="text-base/7 font-medium text-zinc-900 sm:text-sm/6 dark:text-white">{{ __('Which pack do you buy?') }}</p>
        <div class="mt-2 flex flex-wrap gap-2">
            <template x-for="(option, index) in packs" :key="option.shopId">
                <button
                    type="button"
                    @click="chosen = index"
                    :aria-pressed="chosen === index"
                    class="rounded-full px-3 py-1.5 text-base/6 tabular-nums ring-1 sm:text-sm/6"
                    :class="chosen === index ? 'bg-zinc-900 text-white ring-zinc-900 dark:bg-white dark:text-zinc-900 dark:ring-white' : 'bg-white text-zinc-700 ring-zinc-950/10 hover:ring-zinc-950/20 dark:bg-white/5 dark:text-zinc-200 dark:ring-white/10'"
                >
                    <span x-text="option.pack"></span>
                    <span class="opacity-60" x-text="'· ' + option.host + (option.price === null ? '' : ' · ' + money(option.price))"></span>
                </button>
            </template>
        </div>

        {{-- The pack a person buys is often not the best rate. Said once,
             with the way to switch, rather than hidden in the levels. --}}
        <p x-show="cheaperElsewhere() > 0" class="mt-3 text-base/7 text-amber-800 sm:text-sm/6 dark:text-amber-300" data-test="unit-target-cheaper-pack">
            <span x-text="@js(__('Your')) + ' ' + pack().pack + ' ' + @js(__('at')) + ' ' + pack().host + ' ' + @js(__('costs')) + ' ' + money(pack().unitPrice, true) + ' ' + @js(__('per')) + ' ' + unitWord + '. ' + @js(__('The')) + ' ' + best().pack + ' ' + @js(__('at')) + ' ' + best().host + ' ' + @js(__('is')) + ' ' + cheaperElsewhere() + '% ' + @js(__('cheaper per')) + ' ' + unitWord + '.'"></span>
            <button type="button" @click="chosen = 0" class="font-medium underline underline-offset-4" x-text="@js(__('Use the')) + ' ' + best().pack"></button>
        </p>
    </div>

    <div>
        <p class="text-base/7 font-medium text-zinc-900 sm:text-sm/6 dark:text-white">{{ __('Alert me when it costs') }}</p>
        <p x-show="levels().length > 0" class="text-base/7 text-zinc-500 sm:text-sm/6 dark:text-zinc-400" data-test="unit-target-context">
            {{ __('Today’s best:') }}
            <span class="rounded-md bg-zinc-950/5 px-1.5 py-0.5 font-medium text-zinc-900 tabular-nums dark:bg-white/10 dark:text-white" x-text="money(bestUnit(), true) + ' ' + @js(__('per')) + ' ' + unitWord"></span>
            <span x-text="@js(__('at')) + ' ' + best().host + (packs.length > 1 || chosen !== 0 ? ', ' + @js(__('which is')) : '.')"></span>
            <template x-if="packs.length > 1 || chosen !== 0">
                <span>
                    <span class="rounded-md bg-zinc-950/5 px-1.5 py-0.5 font-medium text-zinc-900 tabular-nums dark:bg-white/10 dark:text-white" x-text="money(bestUnit() * pack().perPack)"></span>
                    <span x-text="@js(__('for your')) + ' ' + pack().pack + '.'"></span>
                </span>
            </template>
            <span x-show="lowest() !== null" x-text="@js(__('Lowest on the chart:')) + ' ' + money(lowest(), true) + ' ' + @js(__('per')) + ' ' + unitWord + '.'"></span>
        </p>

        {{-- One list of choices rather than a grid of cards: the levels and
             the person's own price are the same decision. --}}
        <div role="radiogroup" :aria-label="@js(__('Alert price'))" class="mt-3 divide-y divide-zinc-950/5 rounded-xl ring-1 ring-zinc-950/10 dark:divide-white/5 dark:ring-white/10">
            <template x-for="level in levels()" :key="level.name">
                <button
                    type="button"
                    role="radio"
                    :aria-checked="isLevel(level)"
                    @click="setUnit(level.unit); syncPack()"
                    class="flex w-full items-center gap-3 px-4 py-3 text-start first:rounded-t-xl hover:bg-zinc-950/2.5 dark:hover:bg-white/5"
                >
                    <span class="grid size-5 shrink-0 place-items-center rounded-full ring-1 sm:size-4" :class="isLevel(level) ? 'bg-zinc-900 ring-zinc-900 dark:bg-white dark:ring-white' : 'ring-zinc-950/20 dark:ring-white/20'">
                        <span class="size-1.5 rounded-full bg-white dark:bg-zinc-900" x-show="isLevel(level)"></span>
                    </span>
                    <span class="min-w-0 flex-1 text-base/6 text-zinc-700 sm:text-sm/6 dark:text-zinc-200" x-text="level.name"></span>
                    <span class="text-end tabular-nums">
                        <span class="block text-base/6 font-semibold text-zinc-900 sm:text-sm/6 dark:text-white" x-text="money(level.unit * pack().perPack)"></span>
                        <span class="block text-sm/5 text-zinc-500 sm:text-[0.8125rem]/5 dark:text-zinc-400" x-text="money(level.unit, true) + ' ' + @js(__('per')) + ' ' + unitWord"></span>
                    </span>
                </button>
            </template>

            <label class="flex items-center gap-3 px-4 py-3" :class="levels().length === 0 ? 'rounded-xl' : 'rounded-b-xl'">
                <span class="grid size-5 shrink-0 place-items-center rounded-full ring-1 sm:size-4" :class="unit() !== null && ! levels().some((level) => isLevel(level)) ? 'bg-zinc-900 ring-zinc-900 dark:bg-white dark:ring-white' : 'ring-zinc-950/20 dark:ring-white/20'">
                    <span class="size-1.5 rounded-full bg-white dark:bg-zinc-900" x-show="unit() !== null && ! levels().some((level) => isLevel(level))"></span>
                </span>
                <span class="min-w-0 flex-1 text-base/6 text-zinc-700 sm:text-sm/6 dark:text-zinc-200" x-text="@js(__('My own price for')) + ' ' + pack().pack"></span>
                <span class="relative w-28">
                    <span class="pointer-events-none absolute inset-y-0 start-2.5 flex items-center text-zinc-400" x-text="currency === 'EUR' ? '€' : currency"></span>
                    <input
                        type="number"
                        name="unit_target_pack_price"
                        step="0.01"
                        min="0.01"
                        x-ref="packPrice"
                        x-model="packInput"
                        @input="setFromPack($event.target.value)"
                        :aria-label="@js(__('My own price for')) + ' ' + pack().pack"
                        class="w-full rounded-lg bg-white py-1.5 ps-7 pe-2 text-end text-base/6 tabular-nums ring-1 ring-zinc-950/10 focus:outline-2 focus:-outline-offset-1 focus:outline-zinc-900 sm:text-sm/6 dark:bg-white/5 dark:ring-white/10 dark:focus:outline-white"
                        data-test="unit-target-pack-price"
                    />
                </span>
            </label>
        </div>
        <flux:error :name="$model" class="mt-2" />
    </div>

    {{-- What is set, in one line, for eyes and for screen readers alike. --}}
    <p x-show="unit() !== null" x-cloak class="flex flex-wrap items-baseline gap-x-2 gap-y-1 text-base/7 text-zinc-600 sm:text-sm/6 dark:text-zinc-300" aria-live="polite" data-test="unit-target-summary">
        <span x-show="unit() !== null" x-cloak>
            {{ __('Your alert:') }}
            <strong class="font-semibold text-zinc-900 tabular-nums dark:text-white" x-text="money(packPrice(pack())) + ' ' + @js(__('for')) + ' ' + pack().pack"></strong>
            <span class="rounded bg-yellow-100 px-1 font-medium text-yellow-900 tabular-nums dark:bg-yellow-400/20 dark:text-yellow-200" x-text="money(unit(), true) + ' ' + @js(__('per')) + ' ' + unitWord"></span>
            {{ __('at any shop, any pack size.') }}
        </span>
        <button
            type="button"
            x-show="unit() !== null"
            x-cloak
            @click="setUnit(null); syncPack()"
            class="text-zinc-500 underline underline-offset-4 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white"
        >{{ __('Remove') }}</button>
    </p>
</div>
