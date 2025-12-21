document.addEventListener('DOMContentLoaded', function () {

    // Safety check: ensure PHP passed the data
    if (typeof dashboardData === 'undefined') {
        console.warn('Dashboard data is missing.');
        return;
    }

    // ==========================================
    // 1. SALES TREND CHART (Line Chart)
    // ==========================================
    const ctxTrend = document.getElementById('salesTrendChart');
    if (ctxTrend) {
        new Chart(ctxTrend.getContext('2d'), {
            type: 'line',
            data: {
                labels: dashboardData.salesTrend.labels,
                datasets: [
                    {
                        label: 'Revenue ($)',
                        data: dashboardData.salesTrend.revenue,
                        borderColor: '#4285F4', // Google Blue
                        backgroundColor: 'rgba(66, 133, 244, 0.1)',
                        borderWidth: 2,
                        tension: 0.4,
                        fill: true,
                        pointRadius: 4,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Orders',
                        data: dashboardData.salesTrend.orders,
                        borderColor: '#34A853', // Google Green
                        backgroundColor: 'rgba(52, 168, 83, 0.1)',
                        borderWidth: 2,
                        borderDash: [5, 5], // Dashed line
                        tension: 0.4,
                        fill: false,
                        pointRadius: 4,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { display: true, position: 'bottom' } },
                scales: {
                    x: { grid: { display: false } },
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        beginAtZero: true
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        beginAtZero: true,
                        grid: { drawOnChartArea: false }
                    }
                }
            }
        });
    }

    // ==========================================
    // 2. SALES BY CATEGORY (Doughnut Chart)
    // ==========================================
    const ctxCat = document.getElementById('categoryChart');
    if (ctxCat) {
        new Chart(ctxCat.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: dashboardData.salesCategory.labels,
                datasets: [{
                    data: dashboardData.salesCategory.data,
                    backgroundColor: ['#4285F4', '#34A853', '#FBBC05', '#EA4335', '#A142F4'],
                    borderWidth: 2,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '60%',
                plugins: {
                    legend: {
                        position: 'right',
                        labels: { usePointStyle: true, boxWidth: 8, padding: 20 }
                    }
                }
            }
        });
    }

    // ==========================================
    // 3. INVENTORY CHART (Bar Chart with Filter)
    // ==========================================
    const ctxInv = document.getElementById('inventoryChart');
    if (ctxInv) {
        // Initial state: 'lowStock' as defined in HTML selected option
        let currentType = 'lowStock';
        const initialData = dashboardData.inventory[currentType];

        const inventoryChart = new Chart(ctxInv.getContext('2d'), {
            type: 'bar',
            data: {
                labels: initialData.labels,
                datasets: [{
                    label: 'Product Count',
                    data: initialData.data,
                    backgroundColor: '#FBBC05', // Default color (Yellow for Low Stock)
                    borderRadius: 4,
                    barThickness: 30
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { borderDash: [2, 4] },
                        title: { display: true, text: 'Number of Products' }
                    },
                    x: { grid: { display: false } }
                }
            }
        });

        // Handle Filter Changes
        const filterSelect = document.getElementById('inventoryFilter');
        if (filterSelect) {
            filterSelect.addEventListener('change', function (e) {
                const selectedType = e.target.value; // inStock, lowStock, outStock
                const newData = dashboardData.inventory[selectedType];

                // Change bar color based on type
                let newColor = '#FBBC05'; // Default Yellow
                if (selectedType === 'inStock') newColor = '#00C09D'; // Teal
                if (selectedType === 'outStock') newColor = '#EA4335'; // Red

                // Update Chart
                inventoryChart.data.labels = newData.labels;
                inventoryChart.data.datasets[0].data = newData.data;
                inventoryChart.data.datasets[0].backgroundColor = newColor;
                inventoryChart.update();
            });
        }
    }
});