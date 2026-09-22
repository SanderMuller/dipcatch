import type { Passkeys } from '@laravel/passkeys';

declare global {
    interface Window {
        /**
         * Exposed by `resources/js/passkeys.js` so the Blade views can drive the
         * passkey flows without importing the bundle themselves.
         */
        Passkeys: typeof Passkeys;

        /** The parts of Livewire's global that `livewire-errors.js` uses. */
        Livewire: {
            interceptRequest(callback: (hooks: {
                onError(callback: (context: { response: Response; body: string; preventDefault(): void }) => void): void;
                onFailure(callback: (context: { error: unknown }) => void): void;
            }) => void): () => void;
        };

        /** Flux's global, present once `@fluxScripts` has loaded. */
        Flux?: {
            toast(options: { variant?: 'success' | 'warning' | 'danger'; heading?: string; text: string }): void;
        };
    }
}

export {};
