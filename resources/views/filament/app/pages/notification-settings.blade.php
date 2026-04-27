<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div class="flex justify-end">
            <x-filament::button type="submit">Save preferences</x-filament::button>
        </div>
    </form>

    <div
        x-data="{
            permission: typeof Notification !== 'undefined' ? Notification.permission : 'unsupported',
            vapidPublicKey: @js(config('webpush.vapid.public_key', '')),
            subscribeUrl: @js(route('push.subscribe')),
            unsubscribeUrl: @js(route('push.unsubscribe')),
            csrfToken: @js(csrf_token()),
            message: null,

            disablePushToggle(reason) {
                this.message = reason;
                // Flip the Livewire-bound toggle back to false so the form
                // doesn't persist the intent without a working subscription.
                if (this.$wire) {
                    this.$wire.set('data.notify_via_push', false);
                }
            },

            async ensureSubscription() {
                if (typeof navigator === 'undefined' || ! ('serviceWorker' in navigator) || ! ('PushManager' in window)) {
                    this.disablePushToggle('Browser push is not supported on this device.');
                    return;
                }

                if (Notification.permission === 'denied') {
                    this.disablePushToggle('Browser push permission was denied. Re-enable it in your browser settings.');
                    return;
                }

                if (Notification.permission === 'default') {
                    const result = await Notification.requestPermission();
                    this.permission = result;
                    if (result !== 'granted') {
                        this.disablePushToggle('Permission not granted. Browser push stays disabled.');
                        return;
                    }
                }

                if (! this.vapidPublicKey) {
                    this.disablePushToggle('Browser push is not configured on the server.');
                    return;
                }

                try {
                    const registration = await navigator.serviceWorker.register('/sw.js');
                    const subscription = await registration.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: this.urlBase64ToUint8Array(this.vapidPublicKey),
                    });

                    const response = await fetch(this.subscribeUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': this.csrfToken,
                            Accept: 'application/json',
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({
                            endpoint: subscription.endpoint,
                            keys: {
                                p256dh: this.arrayBufferToBase64Url(subscription.getKey('p256dh')),
                                auth: this.arrayBufferToBase64Url(subscription.getKey('auth')),
                            },
                            contentEncoding: 'aes128gcm',
                        }),
                    });

                    if (! response.ok) {
                        try { await subscription.unsubscribe(); } catch (e) { /* ignore */ }
                        this.disablePushToggle('Could not register browser push subscription with the server. Please try again.');
                        return;
                    }

                    this.message = 'Browser push subscription registered.';
                } catch (error) {
                    console.error('Push subscription failed', error);
                    this.disablePushToggle('Browser push setup failed. Please try again.');
                }
            },

            urlBase64ToUint8Array(base64String) {
                const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
                const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
                const raw = atob(base64);
                const out = new Uint8Array(raw.length);
                for (let i = 0; i < raw.length; ++i) out[i] = raw.charCodeAt(i);
                return out;
            },

            arrayBufferToBase64Url(buffer) {
                const bytes = new Uint8Array(buffer);
                let binary = '';
                for (let i = 0; i < bytes.byteLength; i++) binary += String.fromCharCode(bytes[i]);
                return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
            },
        }"
        x-init="$watch('$wire.data.notify_via_push', (val) => { if (val) ensureSubscription(); })"
        class="text-sm text-gray-500"
    >
        <template x-if="message">
            <div x-text="message" class="mt-2"></div>
        </template>
    </div>
</x-filament-panels::page>
