/**
 * What a person sees when a Livewire request fails.
 *
 * Livewire's default puts the raw response body in a full-screen modal: an
 * error page or a stack trace where a button click used to be. A toast says
 * the action did not work and leaves the page usable.
 *
 * An expired session and an empty successful response keep Livewire's own
 * prompt, which offers the reload that fixes both. Without a toast outlet on
 * the page the modal stays too, so a failure is never silent. The response
 * body still reaches the console.
 *
 * A submit button in a `wire:submit` form must not carry
 * `wire:loading.attr="disabled"`: after a failed request it stays disabled.
 */

/** @param {string} text */
function showRequestFailed(text) {
    window.Flux?.toast({
        variant: 'danger',
        heading: 'Could not complete that',
        text,
    });
}

function register() {
    window.Livewire.interceptRequest(({ onError, onFailure }) => {
        onError(({ response, body, preventDefault }) => {
            if (response.status === 419 || response.ok || ! window.Flux) {
                return;
            }

            preventDefault();
            console.error(`Livewire request failed with status ${response.status}`, body);
            // Not "nothing changed": an error while rendering can follow an
            // action that already saved.
            showRequestFailed('Something went wrong. Reload the page to see where things stand.');
        });

        onFailure(({ error }) => {
            console.error('Livewire request did not reach the server', error);

            if (window.Flux) {
                showRequestFailed('DipCatch could not be reached. Check your connection and try again.');
            }
        });
    });
}

// This bundle is a module, so it can run after Livewire has already started.
if (window.Livewire) {
    register();
} else {
    document.addEventListener('livewire:init', register, { once: true });
}
