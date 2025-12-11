/**
 * admin-charts.js
 * Handles all Chart.js initialization for the admin dashboard
 */

function initializeSalesVsWasteChart(
  chartInstances,
  salesVsWasteData,
  forecastPoints,
  timeRange
) {
  const salesVsWasteCtx = document
    .getElementById("salesVsWasteChart")
    .getContext("2d");

  let timeUnit = "day";
  let timeFormat = { month: "short", day: "numeric" };
  if (timeRange === "daily") {
    timeUnit = "hour";
    timeFormat = { hour: "numeric" };
  }

  // Combine historical and forecast labels
  const allTimePoints = salesVsWasteData
    .map((d) => d.time_point)
    .concat(forecastPoints.map((d) => d.time_point));
  const allLabels = allTimePoints.map((d) =>
    new Date(d).toLocaleString("en-US", timeFormat)
  );

  chartInstances.salesVsWasteChart = new Chart(salesVsWasteCtx, {
    type: "line",
    data: {
      labels: allLabels,
      datasets: [
        {
          label: "Total Sales",
          data: salesVsWasteData.map((d) => parseFloat(d.sales)),
          borderColor: "rgba(75, 192, 192, 1)",
          backgroundColor: "rgba(75, 192, 192, 0.1)",
          fill: true,
          tension: 0.3,
        },
        {
          label: "Forecasted Sales",
          data: Array(salesVsWasteData.length - 1)
            .fill(null)
            .concat([salesVsWasteData[salesVsWasteData.length - 1]?.sales])
            .concat(forecastPoints.map((d) => d.sales)),
          borderColor: "rgba(75, 192, 192, 1)",
          borderDash: [5, 5],
          backgroundColor: "rgba(75, 192, 192, 0.05)",
          fill: true,
          tension: 0.3,
        },
        {
          label: "Total Waste Cost",
          data: salesVsWasteData.map((d) => parseFloat(d.waste)),
          borderColor: "rgba(255, 99, 132, 1)",
          backgroundColor: "rgba(255, 99, 132, 0.1)",
          fill: true,
          tension: 0.3,
        },
        {
          label: "Forecasted Waste",
          data: Array(salesVsWasteData.length - 1)
            .fill(null)
            .concat([salesVsWasteData[salesVsWasteData.length - 1]?.waste])
            .concat(forecastPoints.map((d) => d.waste)),
          borderColor: "rgba(255, 99, 132, 1)",
          borderDash: [5, 5],
          backgroundColor: "rgba(255, 99, 132, 0.05)",
          fill: true,
          tension: 0.3,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        y: { beginAtZero: true, ticks: { callback: (value) => "₱" + value } },
      },
      plugins: {
        tooltip: {
          callbacks: {
            label: function (context) {
              let label = context.dataset.label || "";
              if (label) {
                label += ": ";
              }
              if (context.parsed.y !== null) {
                label +=
                  "₱" +
                  context.parsed.y.toLocaleString(undefined, {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2,
                  });
              }
              return label;
            },
          },
        },
        legend: {
          position: "top",
          onClick: (e, legendItem, legend) => {
            const chart = legend.chart;
            const clickedIndex = legendItem.datasetIndex;

            // Determine which group was clicked (Sales: 0, 1; Waste: 2, 3)
            const isSalesGroup = clickedIndex === 0 || clickedIndex === 1;

            // Check if the clicked group is already isolated
            const salesMeta = chart.getDatasetMeta(0);
            const wasteMeta = chart.getDatasetMeta(2);
            const isSalesIsolated = !salesMeta.hidden && wasteMeta.hidden;
            const isWasteIsolated = salesMeta.hidden && !wasteMeta.hidden;

            if (
              (isSalesGroup && isSalesIsolated) ||
              (!isSalesGroup && isWasteIsolated)
            ) {
              // If the clicked group is already isolated, show all datasets
              chart.data.datasets.forEach(
                (_, i) => (chart.getDatasetMeta(i).hidden = false)
              );
            } else {
              // Otherwise, isolate the clicked group
              chart.getDatasetMeta(0).hidden = !isSalesGroup; // Sales
              chart.getDatasetMeta(1).hidden = !isSalesGroup; // Forecasted Sales
              chart.getDatasetMeta(2).hidden = isSalesGroup; // Waste
              chart.getDatasetMeta(3).hidden = isSalesGroup; // Forecasted Waste
            }

            chart.update();
          },
        },
      },
    },
  });
}

function initializeTopProductsChart(chartInstances, topProductsData) {
  const topProductsCtx = document
    .getElementById("topProductsChart")
    .getContext("2d");

  chartInstances.topProductsChart = new Chart(topProductsCtx, {
    type: "bar",
    data: {
      labels: topProductsData.map((p) => p.name),
      datasets: [
        {
          label: "Units Sold",
          data: topProductsData.map((p) => p.total_sold),
          backgroundColor: [
            "rgba(111, 166, 168, 0.7)",
            "rgba(134, 182, 184, 0.7)",
            "rgba(158, 198, 200, 0.7)",
            "rgba(181, 214, 216, 0.7)",
            "rgba(205, 230, 232, 0.7)",
          ],
          borderColor: "rgba(74, 108, 111, 1)",
          borderWidth: 1,
        },
      ],
    },
    options: {
      indexAxis: "y",
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { x: { beginAtZero: true } },
    },
  });
}

function initializeInventoryStockChart(chartInstances, inventoryStockData) {
  const inventoryStockCtx = document
    .getElementById("inventoryStockChart")
    .getContext("2d");

  if (inventoryStockData.length > 0) {
    const lowStockThreshold = 2; // 1-2 is Low Stock
    const mediumStockThreshold = 4; // 3-4 is Medium Stock

    // Filter data to only show low and medium stock items by default
    const defaultFilteredInventory = inventoryStockData.filter(
      (p) => p.stock <= mediumStockThreshold && p.stock > 0
    );

    chartInstances.inventoryStockChart = new Chart(inventoryStockCtx, {
      type: "bar",
      data: {
        labels: defaultFilteredInventory.map((p) => p.name),
        datasets: [
          {
            label: "Low Stock",
            data: defaultFilteredInventory.map((p) =>
              p.stock <= lowStockThreshold ? p.stock : null
            ),
            backgroundColor: "rgba(255, 99, 132, 0.7)",
          },
          {
            label: "Medium Stock",
            data: defaultFilteredInventory.map((p) =>
              p.stock > lowStockThreshold && p.stock <= mediumStockThreshold
                ? p.stock
                : null
            ),
            backgroundColor: "rgba(255, 206, 86, 0.7)",
          },
        ],
      },
      options: {
        indexAxis: "y",
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          x: {
            stacked: true,
            beginAtZero: true,
            title: { display: true, text: "Stock Quantity" },
          },
          y: { stacked: true, ticks: { autoSkip: false } },
        },
        plugins: {
          legend: {
            position: "top",
            onClick: (e, legendItem, legend) => {
              const chart = legend.chart;
              const index = legendItem.datasetIndex;
              const clickedLabel = legendItem.text;

              // Check if the clicked item is the only one visible
              const isOnlyVisible =
                legend.legendItems.filter((li) => !li.hidden).length === 1 &&
                !legendItem.hidden;

              if (isOnlyVisible) {
                // If it's the only one visible, clicking it again should show all datasets
                chart.getDatasetMeta(0).hidden = false; // Low
                chart.getDatasetMeta(1).hidden = false; // Medium
              } else {
                // Otherwise, hide all other datasets and show only the clicked one
                legend.legendItems.forEach(
                  (item, i) => (chart.getDatasetMeta(i).hidden = i !== index)
                );
              }

              // Filter the product list to show only items from the selected category
              let currentFilteredProducts = [];
              if (isOnlyVisible) {
                currentFilteredProducts = defaultFilteredInventory;
              } else {
                currentFilteredProducts = inventoryStockData.filter((p) => {
                  if (
                    clickedLabel === "Low Stock" &&
                    p.stock <= lowStockThreshold
                  )
                    return true;
                  if (
                    clickedLabel === "Medium Stock" &&
                    p.stock > lowStockThreshold &&
                    p.stock <= mediumStockThreshold
                  )
                    return true;
                  return false;
                });
              }

              // Update chart data
              chart.data.labels = currentFilteredProducts.map((p) => p.name);
              chart.data.datasets[0].data = currentFilteredProducts.map((p) =>
                p.stock <= lowStockThreshold ? p.stock : null
              );
              chart.data.datasets[1].data = currentFilteredProducts.map((p) =>
                p.stock > lowStockThreshold && p.stock <= mediumStockThreshold
                  ? p.stock
                  : null
              );

              chart.update();
            },
          },
        },
      },
    });
  }
}

function attachChartClickHandlers(chartInstances) {
  const chartModal = document.getElementById("chartModal");
  const chartModalTitle = document.getElementById("chartModalTitle");
  const modalChartCanvas = document.getElementById("modalChartCanvas");
  const closeChartModalBtn = document.getElementById("closeChartModal");
  let modalChartInstance = null;

  function openChartInModal(chartInstance, title) {
    if (!chartInstance) return;

    if (modalChartInstance) {
      modalChartInstance.destroy();
    }

    chartModalTitle.textContent = title;
    const modalCtx = modalChartCanvas.getContext("2d");

    modalChartInstance = new Chart(modalCtx, {
      type: chartInstance.config.type,
      data: chartInstance.config.data,
      options: {
        ...chartInstance.config.options,
        maintainAspectRatio: false,
      },
    });

    chartModal.style.display = "block";
  }

  document.querySelectorAll(".chart-container canvas").forEach((canvas) => {
    canvas.closest(".chart-container").style.cursor = "pointer";
    canvas.addEventListener("click", () => {
      const chartId = canvas.id;
      const title = canvas
        .closest(".chart-box")
        .querySelector("h3").textContent;
      openChartInModal(chartInstances[chartId], title);
    });
  });

  closeChartModalBtn.addEventListener(
    "click",
    () => (chartModal.style.display = "none")
  );
}
