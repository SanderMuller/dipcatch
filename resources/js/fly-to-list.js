/**
 * Sends a copy of a product's picture from its card to the shopping-list icon
 * in the header, so adding from the product list shows where the product went.
 *
 * Decoration only: the Livewire action does the adding, and the icon's count
 * and a toast say it happened. Nothing runs when the person asked for reduced
 * motion, or when either end of the flight is missing.
 */

const DURATION_MS = 650;

/** @param {HTMLElement | null} source */
function flyToList(source) {
    const target = document.getElementById('shopping-list-trigger');

    if (! source || ! target || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    const from = source.getBoundingClientRect();
    const to = target.getBoundingClientRect();
    const size = Math.min(from.width, from.height, 96);

    const ghost = /** @type {HTMLElement} */ (source.cloneNode(true));
    ghost.setAttribute('aria-hidden', 'true');
    Object.assign(ghost.style, {
        position: 'fixed',
        left: `${from.left + (from.width - size) / 2}px`,
        top: `${from.top + (from.height - size) / 2}px`,
        width: `${size}px`,
        height: `${size}px`,
        margin: '0',
        zIndex: '60',
        pointerEvents: 'none',
        borderRadius: '9999px',
        overflow: 'hidden',
    });
    document.body.appendChild(ghost);

    const dx = to.left + to.width / 2 - (from.left + from.width / 2);
    const dy = to.top + to.height / 2 - (from.top + from.height / 2);

    const flight = ghost.animate([
        { transform: 'translate(0, 0) scale(1)', opacity: 1 },
        { transform: `translate(${dx * 0.5}px, ${dy * 0.5 - 60}px) scale(0.6)`, opacity: 0.9, offset: 0.45 },
        { transform: `translate(${dx}px, ${dy}px) scale(0.15)`, opacity: 0.2 },
    ], { duration: DURATION_MS, easing: 'ease-in-out' });

    // A hidden tab freezes the timeline, and the flight would never finish.
    const cleanup = window.setTimeout(() => ghost.remove(), DURATION_MS + 500);

    flight.onfinish = () => {
        window.clearTimeout(cleanup);
        ghost.remove();
        target.animate([
            { transform: 'scale(1)' },
            { transform: 'scale(1.3)' },
            { transform: 'scale(1)' },
        ], { duration: 300, easing: 'ease-out' });
    };
}

window.flyToList = flyToList;
