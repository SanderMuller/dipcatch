import Chart from 'chart.js/auto';

// The price-history chart. Filament used to supply Chart.js; the Flux app
// carries its own. The dataset and options come from the server
// (App\Charts\PriceHistorySeries and PriceHistoryChartOptions) so the plan
// window and the axis rules stay in one place.
window.dipcatchChart = (canvas, payload, options) => {
    if (! canvas) {
        return null;
    }

    return new Chart(canvas, {
        type: 'line',
        data: payload,
        // The container sets the height; without this Chart.js keeps its own
        // aspect ratio and grows past the fold on a wide screen.
        options: { ...options, maintainAspectRatio: false, responsive: true },
    });
};

// The savings chart. Its tooltip formats with the browser's own ICU, using the
// `currency` each dataset carries: the amounts are never converted, so a euro
// bar and a dollar bar must be labelled in their own currency.
window.dipcatchSavingsChart = (canvas, payload) => {
    if (! canvas) {
        return null;
    }

    return new Chart(canvas, {
        type: 'bar',
        data: payload,
        options: {
            maintainAspectRatio: false,
            responsive: true,
            scales: { y: { beginAtZero: true } },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            const value = ctx.parsed.y;

                            if (value === null || value === undefined) {
                                return ctx.dataset.label;
                            }

                            const money = new Intl.NumberFormat(undefined, {
                                style: 'currency',
                                currency: ctx.dataset.currency,
                            }).format(value);

                            return `${ctx.dataset.label}: ${money}`;
                        },
                    },
                },
            },
        },
    });
};
