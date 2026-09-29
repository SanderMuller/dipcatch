<div class="max-w-4xl">
    <flux:heading size="xl" class="tracking-tight">{{ __('Support') }}</flux:heading>
    <flux:text class="mt-1 max-w-[60ch] text-pretty text-base text-zinc-600 sm:text-sm dark:text-zinc-400">
        {{ __('Send feedback, request a shop, report a problem, or ask us anything about DipCatch.') }}
    </flux:text>

    @if ($submitted)
        <flux:callout variant="success" icon="check-circle" class="mt-6">
            <flux:callout.heading>{{ __('Message sent') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Thanks. We will reply by email if your message needs an answer.') }}</flux:callout.text>
        </flux:callout>
    @endif

    <flux:card class="mt-6">
        <form wire:submit="submit" class="space-y-6">
            <flux:radio.group
                variant="cards"
                wire:model.live="requestType"
                :label="__('How can we help?')"
                class="grid! grid-cols-1 sm:grid-cols-2"
            >
                @foreach ($requestTypes as $type)
                    <flux:radio
                        :value="$type->value"
                        :icon="$type->icon()"
                        :label="$type->label()"
                        :description="$type->description()"
                    />
                @endforeach
            </flux:radio.group>

            @if ($selectedRequestType->requiresShopUrl())
                <flux:input
                    type="url"
                    wire:model="shopUrl"
                    :label="__('Shop URL')"
                    :description="__('Paste the product page where you want us to look.')"
                    placeholder="https://shop.example.com/product"
                    required
                    autofocus
                />
            @endif

            <flux:textarea
                wire:model="message"
                :label="__('Message')"
                :description="__('Tell us what happened, what you expected, or what would make DipCatch better.')"
                rows="6"
                maxlength="5000"
                required
            />

            @error('form')
                <flux:callout variant="danger" icon="exclamation-triangle">{{ $message }}</flux:callout>
            @enderror

            <div class="flex flex-col gap-3 border-t border-zinc-950/5 pt-5 sm:flex-row sm:items-center sm:justify-between dark:border-white/10">
                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                    {{ __('Sending as :name (:email)', ['name' => $user->name, 'email' => $user->email]) }}
                </flux:text>

                <flux:button type="submit" variant="primary" icon:trailing="paper-airplane">
                    <span wire:loading.remove wire:target="submit">{{ __('Send message') }}</span>
                    <span wire:loading wire:target="submit">{{ __('Sending…') }}</span>
                </flux:button>
            </div>
        </form>
    </flux:card>
</div>
