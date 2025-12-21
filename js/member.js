document.addEventListener('DOMContentLoaded', function () {

    // 1. Validation: Ensure chart data exists
    if (typeof memberChartData === 'undefined') {
        console.warn('Member chart data is missing.');
        return;
    }

    // 2. Element Selection
    const ctx = document.getElementById('purchaseHistoryChart');

    // 3. Chart Initialization
    if (ctx) {
        const chartCtx = ctx.getContext('2d');

        // IMPROVEMENT: Create Gradient (Matches Dashboard Style)
        const gradientFill = chartCtx.createLinearGradient(0, 0, 0, 300);
        gradientFill.addColorStop(0, 'rgba(66, 133, 244, 0.4)'); // Blue top
        gradientFill.addColorStop(1, 'rgba(66, 133, 244, 0.0)'); // Transparent bottom

        new Chart(chartCtx, {
            type: 'line',
            data: {
                labels: memberChartData.labels,
                datasets: [{
                    label: 'Total Spent',
                    data: memberChartData.data,
                    backgroundColor: gradientFill, // Use Gradient
                    borderColor: '#4285F4',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: '#4285F4',
                    pointRadius: 5,
                    pointHoverRadius: 7
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                let label = context.dataset.label || '';
                                if (label) label += ': ';
                                if (context.parsed.y !== null) {
                                    label += new Intl.NumberFormat('en-US', {
                                        style: 'currency',
                                        currency: 'USD'
                                    }).format(context.parsed.y);
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { borderDash: [4, 4], color: '#f0f0f0' },
                        ticks: {
                            callback: function (value) { return '$' + value; }
                        }
                    },
                    x: {
                        grid: { display: false }
                    }
                }
            }
        });
    }
});