document.addEventListener('DOMContentLoaded', function () {

    // 1. Validation: Ensure chart data exists (passed from PHP)
    if (typeof memberChartData === 'undefined') {
        console.warn('Member chart data is missing.');
        return;
    }

    // 2. Element Selection
    const ctx = document.getElementById('purchaseHistoryChart');

    // 3. Chart Initialization
    if (ctx) {
        new Chart(ctx.getContext('2d'), {
            type: 'line',
            data: {
                labels: memberChartData.labels,
                datasets: [{
                    label: 'Total Spent',
                    data: memberChartData.data,
                    backgroundColor: 'rgba(66, 133, 244, 0.1)', // Light Blue Fill
                    borderColor: '#4285F4',                    // Blue Line
                    borderWidth: 2,
                    tension: 0.4,                              // Smooth curve
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
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.parsed.y !== null) {
                                    // Format as currency USD
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
                        grid: {
                            borderDash: [4, 4],
                            color: '#f0f0f0'
                        },
                        ticks: {
                            callback: function (value) {
                                return '$' + value;
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }
});