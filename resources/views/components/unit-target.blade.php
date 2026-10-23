@props([
    'model',
    'description' => null,
    'packs',
    'history' => null,
    'currency' => 'EUR',
    'unitWord' => 'unit',
    'heading' => true,
    'suggestedUnit' => null,
    // ml or g per item where the price per item leads (App\Support\ItemPricing):
    // every figure per unit then reads per item. The target stays per unit.
    'itemSize' => null,
    // Sizes the owner stated outside the comparison unit (UnitTargetGuide::strandedOwnerSizes()).
    'stranded' => [],
])

{{--
    Set in pack prices, stored as one price per unit that every shop and pack
    size is held to. `packs` comes from UnitTargetGuide::packs(), in the
    best-value ranking's order so the first is the best value, and must not
    be empty.
    A `suggestion` slot adds the suggested alert as one more choice, placed
    among the levels by price. Its attributes go on that row: `wire:click`
    to pick it, or `wire:init` while `suggestedUnit` is still null and the
    slot shows a checking state.
    The "My own price" row sets the target three ways: a price for the chosen
    pack, a percentage under today's best, or an amount under the chosen
    pack's price. The server works the last two out again on save.
--}}
<div
    {{ $attributes->class('space-y-6') }}
    {{-- A new key once the suggestion arrives, or the packs change (a size
         the owner states), so Alpine starts again with them. --}}
    wire:key="unit-target-{{ isset($suggestion) ? ($suggestedUnit ?? 'checking') : 'none' }}-{{ md5(json_encode($packs)) }}"
    x-data="{
        target: $wire.entangle(@js($model)),
        packs: @js($packs),
        history: @js($history),
        currency: @js($currency),
        unitWord: @js($unitWord),
        itemSize: @js($itemSize),
        suggestedUnit: @js(isset($suggestion) && $suggestedUnit !== null ? (float) $suggestedUnit : null),
        chosenShop: $wire.entangle('chosenPackShopId'),
        helper: $wire.entangle('alertHelper'),
        helperValue: $wire.entangle('alertHelperValue'),
        chosen: 0,
        packInput: '',
        init() {
            const kept = this.packs.findIndex((option) => option.shopId === this.chosenShop);
            this.chosen = kept === -1 ? 0 : kept;
            this.chosenShop = this.pack().shopId;
            this.syncPack();
            // A helper amount survives a re-render the component restarts on.
            if (this.helper !== 'price' && this.helperValue) {
                this.packInput = this.helperValue;
            }
            // Another pack keeps the target: the box shows its price for that
            // pack. Only money off a pack's price depends on which pack.
            this.$watch('chosen', () => {
                this.chosenShop = this.pack().shopId;

                if (this.helper === 'amount') {
                    this.applyHelper();
                } else {
                    this.syncPack();
                }
            });
            // A target set from elsewhere — the suggestion, a
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
        // A figure per unit as it is shown: per item where the product reads so.
        shown(value) {
            return value === null || value === undefined || this.itemSize === null ? value : value * this.itemSize / 1000;
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
        isSuggested() {
            return this.suggestedUnit !== null && this.unit() !== null && Math.abs(this.unit() - this.floor(this.suggestedUnit)) < 0.00005;
        },
        // The levels with the suggestion in its place by price, easiest to
        // reach first, as the levels are.
        rows() {
            const rows = this.levels().map((level) => ({ ...level, suggested: false }));

            if (this.suggestedUnit === null) {
                return rows;
            }

            return rows
                .concat([{ name: 'suggested', unit: this.suggestedUnit, suggested: true }])
                .sort((a, b) => b.unit - a.unit);
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
            if (this.helper !== 'price') {
                return;
            }

            this.packInput = this.unit() === null ? '' : this.packPrice(this.pack()).toFixed(2);
        },
        // A preset or the suggestion sets the target itself: the row goes back
        // to a price for the pack, filled in from it.
        pick(value) {
            this.helper = 'price';
            this.helperValue = null;
            this.setUnit(value);
            this.syncPack();
        },
        useHelper(name) {
            this.helper = name;
            this.packInput = '';
            this.helperValue = null;

            if (name === 'price') {
                this.syncPack();
            }
        },
        helperError() {
            const value = parseFloat(this.packInput);

            if (this.packInput === '' || isNaN(value)) {
                return null;
            }

            if (this.helper === 'percent' && (value <= 0 || value >= 100)) {
                return @js(__('Enter a percentage between 0 and 100.'));
            }

            if (this.helper === 'amount' && (value <= 0 || this.pack().price === null || value >= this.pack().price)) {
                return @js(__('Enter less than today’s price for your pack.'));
            }

            return null;
        },
        applyHelper() {
            const value = parseFloat(this.packInput);
            this.helperValue = this.helper === 'price' || this.packInput === '' ? null : this.packInput;

            if (this.packInput === '' || isNaN(value) || this.helperError() !== null) {
                this.setUnit(null);

                return;
            }

            if (this.helper === 'percent') {
                this.setUnit(this.bestUnit() * (1 - value / 100));
            } else if (this.helper === 'amount') {
                this.setUnit((this.pack().price - value) / this.pack().perPack);
            } else {
                this.setFromPack(value);
            }
        },
        symbol() {
            return this.currency === 'EUR' ? '€' : this.currency;
        },
        helpers() {
            return [
                { name: 'price', label: @js(__('Price for my')) + ' ' + this.pack().pack },
                { name: 'percent', label: @js(__('% under today’s best')) },
                { name: 'amount', label: this.symbol() + ' ' + @js(__('under today’s price for my')) + ' ' + this.pack().pack },
            ];
        },
        helperLabel() {
            return this.helpers().find((option) => option.name === this.helper)?.label ?? '';
        },
        smallest() {
            const smallest = this.packs.reduce((min, option) => option.perPack < min.perPack ? option : min, this.packs[0]);

            return smallest.perPack < this.pack().perPack ? smallest : null;
        },
    }"
    data-test="unit-target"
>
    {{-- Rendered on the server, so the rule and today's figure read without
         the script. --}}
    @if ($heading)
        <div>
            <flux:heading>{{ __('Price alert') }}</flux:heading>
            <p class="mt-1 max-w-[65ch] text-base/7 text-pretty text-zinc-500 sm:text-sm/6 dark:text-zinc-400">
                {{ __('Any shop, any pack size, at the same price per :unit.', ['unit' => $unitWord]) }}
                {{ $description }}
            </p>
        </div>
    @endif

    {{-- Also with one size: the chip names the pack the prices below are for. --}}
    <div>
            <p id="pack-choice-question" tabindex="-1" class="text-base/7 font-medium text-zinc-900 focus:outline-none sm:text-sm/6 dark:text-white">{{ __('Which pack do you buy?') }}</p>
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
                        <span class="opacity-60" x-text="'· ' + option.shop + (option.price === null ? '' : ' · ' + money(option.price))"></span>
                    </button>
                </template>
            </div>

            {{-- The pack a person buys is often not the best rate. Said once,
                 with the way to switch, rather than hidden in the levels. --}}
            <p x-show="cheaperElsewhere() > 0" class="mt-3 text-base/7 text-amber-800 sm:text-sm/6 dark:text-amber-300" data-test="unit-target-cheaper-pack">
                <span x-text="@js(__('Your')) + ' ' + pack().pack + ' ' + @js(__('at')) + ' ' + pack().shop + ' ' + @js(__('costs')) + ' ' + money(shown(pack().unitPrice), true) + ' ' + @js(__('per')) + ' ' + unitWord + '. ' + @js(__('The')) + ' ' + best().pack + ' ' + @js(__('at')) + ' ' + best().shop + ' ' + @js(__('is')) + ' ' + cheaperElsewhere() + '% ' + @js(__('cheaper per')) + ' ' + unitWord + '.'"></span>
                <button type="button" @click="chosen = 0" class="font-medium underline underline-offset-4" x-text="@js(__('Use the')) + ' ' + best().pack"></button>
            </p>

            <div class="mt-3">
                @include('livewire.products.partials.owner-pack-size', ['ask' => false])
            </div>
            @foreach ($stranded as $owned)
                <p class="mt-2 text-base/7 text-zinc-600 sm:text-sm/6 dark:text-zinc-300" data-test="owner-pack-size-stranded">
                    {{ __('Your size at :shop, :size, is not in the unit this product compares in.', ['shop' => $owned['shop'], 'size' => $owned['size']]) }}
                    <button type="button" wire:click="removeOwnerPackSize(@js($owned['shopId']))" class="ms-1 text-zinc-500 underline underline-offset-4 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">{{ __('Remove') }}<span class="sr-only"> {{ __('your size') }}</span></button>
                </p>
            @endforeach
    </div>

    <div>
        <p class="text-base/7 font-medium text-zinc-900 sm:text-sm/6 dark:text-white">{{ __('Alert me when it costs') }}</p>
        <p x-show="levels().length > 0" class="text-base/7 text-zinc-500 sm:text-sm/6 dark:text-zinc-400" data-test="unit-target-context">
            {{ __('Today’s best:') }}
            <span class="rounded-md bg-zinc-950/5 px-1.5 py-0.5 font-medium text-zinc-900 tabular-nums dark:bg-white/10 dark:text-white" x-text="money(shown(bestUnit()), true) + ' ' + @js(__('per')) + ' ' + unitWord"></span>
            {{-- The pack price too, always: the suggested alert and the
                 levels below are pack prices. --}}
            <span x-text="@js(__('at')) + ' ' + best().shop + ', ' + @js(__('which is'))"></span>
            <span class="rounded-md bg-zinc-950/5 px-1.5 py-0.5 font-medium text-zinc-900 tabular-nums dark:bg-white/10 dark:text-white" x-text="money(bestUnit() * pack().perPack)"></span>
            <span x-text="@js(__('for your')) + ' ' + pack().pack + '.'"></span>
            <span x-show="lowest() !== null" x-text="@js(__('Lowest on the chart:')) + ' ' + money(shown(lowest()), true) + ' ' + @js(__('per')) + ' ' + unitWord + '.'"></span>
        </p>

        {{-- One list of choices rather than a grid of cards: the levels and
             the person's own price are the same decision. --}}
        <div role="radiogroup" :aria-label="@js(__('Alert price'))" class="mt-3 divide-y divide-zinc-950/5 rounded-xl ring-1 ring-zinc-950/10 dark:divide-white/5 dark:ring-white/10">
            @isset($suggestion)
                @if ($suggestedUnit === null)
                    <div {{ $suggestion->attributes->class('rounded-t-xl px-4 py-3') }} data-test="unit-target-suggestion-checking">{{ $suggestion }}</div>
                @endif
            @endisset

            <template x-for="(row, index) in rows()" :key="row.name">
                <div>
                    <template x-if="! row.suggested">
                        <button
                            type="button"
                            role="radio"
                            :aria-checked="isLevel(row)"
                            @click="pick(row.unit)"
                            class="flex w-full items-center gap-3 px-4 py-3 text-start hover:bg-zinc-950/2.5 dark:hover:bg-white/5"
                            :class="index === 0 && 'rounded-t-xl'"
                        >
                            <span class="grid size-5 shrink-0 place-items-center rounded-full ring-1 sm:size-4" :class="isLevel(row) ? 'bg-zinc-900 ring-zinc-900 dark:bg-white dark:ring-white' : 'ring-zinc-950/20 dark:ring-white/20'">
                                <span class="size-1.5 rounded-full bg-white dark:bg-zinc-900" x-show="isLevel(row)"></span>
                            </span>
                            <span class="min-w-0 flex-1 text-base/6 text-zinc-700 sm:text-sm/6 dark:text-zinc-200" x-text="row.name"></span>
                            <span class="text-end tabular-nums">
                                <span class="block text-base/6 font-semibold text-zinc-900 sm:text-sm/6 dark:text-white" x-text="money(row.unit * pack().perPack)"></span>
                                <span class="block text-sm/5 text-zinc-500 sm:text-[0.8125rem]/5 dark:text-zinc-400" x-text="money(shown(row.unit), true) + ' ' + @js(__('per')) + ' ' + unitWord"></span>
                            </span>
                        </button>
                    </template>
                    @isset($suggestion)
                        {{-- Rendered on the server inside the template, so the
                             reason reads in the response as the heading does. --}}
                        <template x-if="row.suggested">
                            <button
                                type="button"
                                role="radio"
                                :aria-checked="isSuggested()"
                                {{ $suggestion->attributes->class('flex w-full items-start gap-3 bg-amber-50/70 px-4 py-3 text-start hover:bg-amber-100/60 dark:bg-amber-400/5 dark:hover:bg-amber-400/10') }}
                                :class="index === 0 && 'rounded-t-xl'"
                                data-test="unit-target-suggestion"
                            >
                                <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full ring-1 sm:size-4" :class="isSuggested() ? 'bg-zinc-900 ring-zinc-900 dark:bg-white dark:ring-white' : 'ring-zinc-950/20 dark:ring-white/20'">
                                    <span class="size-1.5 rounded-full bg-white dark:bg-zinc-900" x-show="isSuggested()"></span>
                                </span>
                                <span class="min-w-0 flex-1">{{ $suggestion }}</span>
                                <span class="text-end tabular-nums">
                                    <span class="block text-base/6 font-semibold text-zinc-900 sm:text-sm/6 dark:text-white" x-text="money(row.unit * pack().perPack)"></span>
                                    <span class="block text-sm/5 text-zinc-500 sm:text-[0.8125rem]/5 dark:text-zinc-400" x-text="money(shown(row.unit), true) + ' ' + @js(__('per')) + ' ' + unitWord"></span>
                                </span>
                            </button>
                        </template>
                    @endisset
                </div>
            </template>

            <div class="px-4 py-3" :class="rows().length === 0 ? 'rounded-xl' : 'rounded-b-xl'" data-test="unit-target-own-price">
                <div class="flex items-center gap-3">
                    <span class="grid size-5 shrink-0 place-items-center rounded-full ring-1 sm:size-4" :class="unit() !== null && ! levels().some((level) => isLevel(level)) && ! isSuggested() ? 'bg-zinc-900 ring-zinc-900 dark:bg-white dark:ring-white' : 'ring-zinc-950/20 dark:ring-white/20'">
                        <span class="size-1.5 rounded-full bg-white dark:bg-zinc-900" x-show="unit() !== null && ! levels().some((level) => isLevel(level)) && ! isSuggested()"></span>
                    </span>
                    <label id="unit-target-own-price-label" for="unit-target-own-price" class="min-w-0 flex-1 text-base/6 text-zinc-700 sm:text-sm/6 dark:text-zinc-200">{{ __('My own price') }}</label>
                    <span class="relative w-28">
                        <span x-show="helper !== 'percent'" class="pointer-events-none absolute inset-y-0 start-2.5 flex items-center text-zinc-500 dark:text-zinc-400" x-text="symbol()"></span>
                        <span x-show="helper === 'percent'" x-cloak class="pointer-events-none absolute inset-y-0 end-2.5 flex items-center text-zinc-500 dark:text-zinc-400">%</span>
                        <span id="unit-target-own-price-mode" class="sr-only" x-text="helperLabel()"></span>
                        <input
                            id="unit-target-own-price"
                            type="number"
                            name="unit_target_own_price"
                            step="0.01"
                            min="0.01"
                            x-ref="packPrice"
                            x-model="packInput"
                            @input="applyHelper()"
                            aria-labelledby="unit-target-own-price-label unit-target-own-price-mode"
                            :aria-invalid="helperError() !== null"
                            aria-describedby="unit-target-helper-error"
                            :class="helper === 'percent' ? 'ps-2 pe-7' : 'ps-7 pe-2'"
                            class="w-full rounded-lg bg-white py-1.5 text-end text-base/6 tabular-nums ring-1 ring-zinc-950/10 focus:outline-2 focus:-outline-offset-1 focus:outline-zinc-900 sm:text-sm/6 dark:bg-white/5 dark:ring-white/10 dark:focus:outline-white"
                            data-test="unit-target-pack-price"
                        />
                    </span>
                </div>
                <div role="radiogroup" :aria-label="@js(__('How to set my own price'))" class="mt-2 ms-8 flex flex-wrap gap-1.5 sm:ms-7" data-test="unit-target-helpers">
                    <template x-for="option in helpers()" :key="option.name">
                        <button
                            type="button"
                            role="radio"
                            :aria-checked="helper === option.name"
                            @click="useHelper(option.name)"
                            class="rounded-full px-2.5 py-1 text-sm/5 ring-1"
                            :class="helper === option.name ? 'bg-zinc-900 text-white ring-zinc-900 dark:bg-white dark:text-zinc-900 dark:ring-white' : 'text-zinc-600 ring-zinc-950/10 hover:ring-zinc-950/20 dark:text-zinc-300 dark:ring-white/10'"
                            x-text="option.label"
                        ></button>
                    </template>
                </div>
                {{-- Always rendered, so a screen reader hears the error as it appears. --}}
                <p id="unit-target-helper-error" role="alert" x-text="helperError() ?? ''" class="mt-2 ms-8 empty:mt-0 text-base/6 text-red-600 sm:ms-7 sm:text-sm/6 dark:text-red-400" data-test="unit-target-helper-error"></p>
            </div>
        </div>
        <flux:error :name="$model" class="mt-2" />
        <flux:error name="alertHelperValue" class="mt-2" />
    </div>

    {{-- What is set, in one line, for eyes and for screen readers alike:
         the price per unit, what it comes to for the chosen pack, and for the
         smallest pack, which alerts at a far lower price. --}}
    <p x-show="unit() !== null" x-cloak class="flex flex-wrap items-baseline gap-x-2 gap-y-1 text-base/7 text-zinc-600 sm:text-sm/6 dark:text-zinc-300" aria-live="polite" data-test="unit-target-summary">
        <span>
            {{ __('Your alert:') }}
            <strong class="rounded bg-yellow-100 px-1 font-semibold text-yellow-900 tabular-nums dark:bg-yellow-400/20 dark:text-yellow-200" x-text="money(shown(unit()), true) + ' ' + @js(__('per')) + ' ' + unitWord"></strong>
            <span class="tabular-nums" x-text="'— ' + money(packPrice(pack())) + ' ' + @js(__('for your')) + ' ' + pack().pack + '.'"></span>
            <span class="tabular-nums" x-show="smallest() !== null" x-text="smallest() === null ? '' : @js(__('The')) + ' ' + smallest().pack + ' ' + @js(__('at')) + ' ' + smallest().shop + ' ' + @js(__('alerts at')) + ' ' + money(packPrice(smallest())) + ' ' + @js(__('or less.'))" data-test="unit-target-smallest"></span>
        </span>
        <button
            type="button"
            @click="pick(null)"
            class="text-zinc-500 underline underline-offset-4 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white"
        >{{ __('Remove') }}<span class="sr-only"> {{ __('alert') }}</span></button>
    </p>
    <p x-show="unit() === null" class="text-base/7 text-zinc-500 sm:text-sm/6 dark:text-zinc-400" data-test="unit-target-none">
        {{ __('No target: we tell you when the price drops well under its usual price.') }}
    </p>
</div>
