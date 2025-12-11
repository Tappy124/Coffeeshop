/**
 * admin-calendar.js
 * Handles delivery calendar functionality for the admin dashboard
 */

function initializeDeliveryCalendar(deliveries) {
  const deliveryCalendarEl = document.getElementById("deliveryCalendar");
  if (!deliveryCalendarEl) return;

  const deliveriesByDate = deliveries.reduce((acc, delivery) => {
    const date = delivery.restock_schedule;
    if (!acc[date]) acc[date] = {};
    if (!acc[date][delivery.company_name]) {
      acc[date][delivery.company_name] = {
        products: [],
        last_received: delivery.last_received_date,
        contact_person: delivery.contact_person,
        phone: delivery.phone,
      };
    }
    if (delivery.product_name) {
      acc[date][delivery.company_name].products.push({
        name: delivery.product_name,
        qty: delivery.default_quantity,
      });
    }
    return acc;
  }, {});

  let currentMonth, currentYear;

  function renderCalendar(month, year) {
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    const firstDayOfMonth = new Date(year, month, 1);
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const startingDay = firstDayOfMonth.getDay();

    let calendarNavHTML = `<div class="calendar-nav"><button id="prevMonth">&lt;</button><span class="calendar-title">${firstDayOfMonth.toLocaleString(
      "default",
      { month: "long", year: "numeric" }
    )}</span><button id="nextMonth">&gt;</button></div>`;

    let calendarHTML =
      '<div class="calendar-header">Sun</div><div class="calendar-header">Mon</div><div class="calendar-header">Tue</div><div class="calendar-header">Wed</div><div class="calendar-header">Thu</div><div class="calendar-header">Fri</div><div class="calendar-header">Sat</div>';

    // Add empty cells for days before the 1st
    for (let i = 0; i < startingDay; i++) {
      calendarHTML += '<div class="calendar-day other-month"></div>';
    }

    // Add days of the month
    for (let day = 1; day <= daysInMonth; day++) {
      const currentDate = new Date(year, month, day, 12);
      const y = currentDate.getFullYear();
      const m = String(currentDate.getMonth() + 1).padStart(2, "0");
      const d = String(currentDate.getDate()).padStart(2, "0");
      const dateString = `${y}-${m}-${d}`;
      let dayClass = "calendar-day";

      const loopDate = new Date(year, month, day);
      loopDate.setHours(0, 0, 0, 0);

      if (loopDate.getTime() === today.getTime()) {
        dayClass += " today";
      }

      const deliveryInfo = deliveriesByDate[dateString];
      if (deliveryInfo) {
        dayClass += " has-delivery";

        let tooltipHTML = "";
        const allReceived = Object.values(deliveryInfo).every(
          (d) => d.last_received === dateString
        );
        if (allReceived) dayClass += " received";

        tooltipHTML = '<div class="delivery-tooltip">';
        Object.keys(deliveriesByDate[dateString]).forEach((company) => {
          tooltipHTML += `<div>- ${company}</div>`;
        });
        tooltipHTML += "</div>";
        calendarHTML += `<div class="${dayClass}" data-date="${dateString}">${day}${tooltipHTML}</div>`;
      } else {
        calendarHTML += `<div class="${dayClass}" data-date="${dateString}">${day}</div>`;
      }
    }

    deliveryCalendarEl.innerHTML =
      calendarNavHTML + `<div class="calendar">${calendarHTML}</div>`;

    document.getElementById("prevMonth").onclick = () => {
      currentMonth = currentMonth === 0 ? 11 : currentMonth - 1;
      if (currentMonth === 11) currentYear--;
      renderCalendar(currentMonth, currentYear);
    };
    document.getElementById("nextMonth").onclick = () => {
      currentMonth = currentMonth === 11 ? 0 : currentMonth + 1;
      if (currentMonth === 0) currentYear++;
      renderCalendar(currentMonth, currentYear);
    };
    attachCalendarClickEvents();
  }

  function attachCalendarClickEvents() {
    const deliveryModal = document.getElementById("deliveryDetailsModal");
    const deliveryModalTitle = document.getElementById("deliveryModalTitle");
    const deliveryDetailsContent = document.getElementById(
      "deliveryDetailsContent"
    );

    document
      .querySelectorAll(".calendar-day.has-delivery")
      .forEach((dayCell) => {
        dayCell.addEventListener("click", function () {
          const dateString = this.dataset.date;
          const deliveriesOnDate = deliveriesByDate[dateString] || {};

          const formattedDate = new Date(
            dateString + "T00:00:00"
          ).toLocaleDateString("en-US", {
            month: "long",
            day: "numeric",
            year: "numeric",
          });
          deliveryModalTitle.textContent = `Deliveries for ${formattedDate}`;

          let contentHtml = "";
          for (const companyName in deliveriesOnDate) {
            const delivery = deliveriesOnDate[companyName];
            const isReceived = delivery.last_received === dateString;

            contentHtml += `<h3>${companyName}</h3>`;
            contentHtml += `<p class="contact-info">Contact: ${
              delivery.contact_person || "N/A"
            } (${delivery.phone || "N/A"})</p>`;

            if (isReceived) {
              contentHtml +=
                '<p><strong>Status:</strong> <span class="status-received">Received</span></p>';
            } else {
              contentHtml +=
                '<p><strong>Status:</strong> <span class="status-pending">Pending</span></p>';
            }

            if (delivery.products.length > 0) {
              contentHtml += "<ul>";
              delivery.products.forEach((product) => {
                contentHtml += `<li>${product.name} (Expected Qty: ${
                  product.qty || 1
                })</li>`;
              });
              contentHtml += "</ul>";
            } else {
              contentHtml +=
                "<p>No specific products assigned for this delivery.</p>";
            }
            contentHtml += "<hr>";
          }

          if (contentHtml === "") {
            contentHtml =
              "<p>All scheduled deliveries for this date have been received.</p>";
          }
          deliveryDetailsContent.innerHTML = contentHtml;
          deliveryModal.style.display = "block";
        });
      });
  }

  const initialDate = new Date();
  currentMonth = initialDate.getMonth();
  currentYear = initialDate.getFullYear();
  renderCalendar(currentMonth, currentYear);

  const deliveryModal = document.getElementById("deliveryDetailsModal");
  if (document.getElementById("closeDeliveryModal")) {
    document.getElementById("closeDeliveryModal").onclick = () =>
      (deliveryModal.style.display = "none");
  }
  if (document.getElementById("closeDeliveryDetailsBtn")) {
    document.getElementById("closeDeliveryDetailsBtn").onclick = () =>
      (deliveryModal.style.display = "none");
  }
}
