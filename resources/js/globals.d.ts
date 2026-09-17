import type { Passkeys } from '@laravel/passkeys';

declare global {
    interface Window {
        /**
         * Exposed by `resources/js/passkeys.js` so the Blade views can drive the
         * passkey flows without importing the bundle themselves.
         */
        Passkeys: typeof Passkeys;
    }
}

export {};
