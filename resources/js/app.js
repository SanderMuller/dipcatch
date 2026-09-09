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
