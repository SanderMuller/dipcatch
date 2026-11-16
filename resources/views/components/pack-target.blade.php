@props([
    'packs',
    'currency' => 'EUR',
])

{{--
    The alert while no shop can be compared per unit: the price of the pack
    the person buys, at the shop they pick. Only that shop's price is
    compared, so a single bar elsewhere never meets a price meant for a
    12-pack. `packs` comes from UnitTargetGuide::pinnablePacks() and must not
    be empty.
--}}
<div
    {{ $attributes->class('space-y-6') }}
    wire:key="pack-target-{{ md5(json_encode($packs)) }}"
    x-data="{
        target: $wire.entangle('unitPriceTarget'),
        chosenShop: $wire.entangle('chosenPackShopId'),
        packs: @js($packs),
        currency: @js($currency),
        init() {
            if (! this.packs.some((option) => option.shopId === this.chosenShop)) {
                this.chosenShop = this.packs[0].shopId;
            }
        },
        pack() {
            return this.packs.find((option) => option.shopId === this.chosenShop) ?? this.packs[0];
        },
        money(value) {
            if (value === null || value === undefined || ! isFinite(value)) {
                return '—';
            }

            return new Intl.NumberFormat('en', { style: 'currency', currency: this.currency, minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value);
        },
        price() {
            const value = parseFloat(this.target);

            return isNaN(value) || value <= 0 ? null : value;
        },
    }"
    data-test="pack-target"
>
    <div>
        <p id="pack-choice-question" tabindex="-1" class="text-base/7 font-medium text-zinc-900 focus:outline-none sm:text-sm/6 dark:text-white">{{ __('Which pack do you buy?') }}</p>
        <div role="radiogroup" aria-labelledby="pack-choice-question" class="mt-2 flex flex-wrap gap-2">
            <template x-for="option in packs" :key="option.shopId">
                <button
                    type="button"
                    role="radio"
                    @click="chosenShop = option.shopId"
                    :aria-checked="chosenShop === option.shopId"
                    class="rounded-full px-3 py-1.5 text-base/6 tabular-nums ring-1 sm:text-sm/6"
                    :class="chosenShop === option.shopId ? 'bg-zinc-900 text-white ring-zinc-900 dark:bg-white dark:text-zinc-900 dark:ring-white' : 'bg-white text-zinc-700 ring-zinc-950/10 hover:ring-zinc-950/20 dark:bg-white/5 dark:text-zinc-200 dark:ring-white/10'"
                    data-test="pack-target-chip"
                >
                    <span x-text="option.shop"></span>
                    <span :class="chosenShop === option.shopId ? 'opacity-60' : 'text-zinc-500 dark:text-zinc-400'" x-text="(option.price === null ? '' : '· ' + money(option.price))"></span>
                </button>
            </template>
        </div>
    </div>

    @include('livewire.products.partials.owner-pack-size', ['ask' => true])

    <div>
        <label for="pack-target-price" class="text-base/7 font-medium text-zinc-900 sm:text-sm/6 dark:text-white">{{ __('Price for this pack') }}</label>
        <p id="pack-target-price-hint" class="max-w-[65ch] text-base/7 text-pretty text-zinc-500 sm:text-sm/6 dark:text-zinc-400">
            {{ __('We compare this shop’s price until we know how much is in a pack. Then we turn this into a price per kilo, litre or piece.') }}
        </p>
        <span class="relative mt-2 block w-36">
            <span class="pointer-events-none absolute inset-y-0 start-2.5 flex items-center text-zinc-500 dark:text-zinc-400" x-text="currency === 'EUR' ? '€' : currency"></span>
            <input
                id="pack-target-price"
                aria-describedby="pack-target-price-hint"
                @error('unitPriceTarget') aria-invalid="true" @enderror
                type="number"
                name="pack_target_price"
                step="0.01"
                min="0.01"
                x-model="target"
                class="w-full rounded-lg bg-white py-1.5 ps-7 pe-2 text-end text-base/6 tabular-nums ring-1 ring-zinc-950/10 focus:outline-2 focus:-outline-offset-1 focus:outline-zinc-900 sm:text-sm/6 dark:bg-white/5 dark:ring-white/10 dark:focus:outline-white"
                data-test="pack-target-price"
            />
        </span>
        <flux:error name="unitPriceTarget" class="mt-2" />
        <flux:error name="chosenPackShopId" class="mt-2" />
    </div>

    <p x-show="price() !== null" x-cloak class="text-base/7 text-zinc-600 sm:text-sm/6 dark:text-zinc-300" aria-live="polite" data-test="pack-target-summary">
        {{ __('Your alert:') }}
        <strong class="rounded bg-yellow-100 px-1 font-semibold text-yellow-900 tabular-nums dark:bg-yellow-400/20 dark:text-yellow-200" x-text="money(price()) + ' ' + @js(__('or less at')) + ' ' + pack().shop"></strong>
        <span class="tabular-nums" x-text="pack().price === null ? '' : @js(__('Today:')) + ' ' + money(pack().price) + '.'"></span>
    </p>
    <p x-show="price() === null" class="text-base/7 text-zinc-500 sm:text-sm/6 dark:text-zinc-400" data-test="pack-target-none">
        {{ __('No target: we tell you when the price drops well under its usual price.') }}
    </p>
</div>
