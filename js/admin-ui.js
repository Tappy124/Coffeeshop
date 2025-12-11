/**
 * admin-ui.js
 * Handles UI interactions for the admin dashboard (modals, notifications, logout)
 */

function initializeNotifications() {
  const notificationBell = document.getElementById("notificationBell");
  const notificationDropdown = document.getElementById("notificationDropdown");

  if (!notificationBell || !notificationDropdown) return;

  notificationBell.addEventListener("click", function (event) {
    event.stopPropagation();
    event.preventDefault();
    notificationDropdown.classList.toggle("show");
  });

  // Prevent SVG from intercepting clicks
  const bellSvg = notificationBell.querySelector("svg");
  if (bellSvg) {
    bellSvg.style.pointerEvents = "none";
  }

  // Close dropdown when clicking outside
  window.addEventListener("click", function (event) {
    if (
      !notificationDropdown.contains(event.target) &&
      !notificationBell.contains(event.target)
    ) {
      notificationDropdown.classList.remove("show");
    }
  });
}

function initializeLogoutModal() {
  const confirmModal = document.getElementById("confirmModal");
  const logoutLink = document.querySelector('a[href="logout.php"]');

  if (!logoutLink || !confirmModal) return;

  logoutLink.addEventListener("click", function (e) {
    e.preventDefault();
    const confirmBtn = document.getElementById("confirmYesBtn");
    document.getElementById("confirmMessage").textContent =
      "Are you sure you want to log out?";
    confirmBtn.textContent = "Yes, Logout";
    confirmBtn.className = "confirm-btn-yes btn-logout-yes";
    confirmModal.style.display = "block";
    confirmBtn.onclick = function () {
      window.location.href = "logout.php";
    };
  });

  document
    .getElementById("closeConfirmModal")
    .addEventListener("click", () => (confirmModal.style.display = "none"));
  document
    .getElementById("confirmCancelBtn")
    .addEventListener("click", () => (confirmModal.style.display = "none"));
}

function initializeModalClosing() {
  const confirmModal = document.getElementById("confirmModal");
  const chartModal = document.getElementById("chartModal");
  const deliveryDetailsModal = document.getElementById("deliveryDetailsModal");

  window.addEventListener("click", (e) => {
    if (e.target == confirmModal) confirmModal.style.display = "none";
    if (e.target == chartModal) chartModal.style.display = "none";
    if (e.target == deliveryDetailsModal)
      deliveryDetailsModal.style.display = "none";
  });
}

function initializeAllUI() {
  initializeNotifications();
  initializeLogoutModal();
  initializeModalClosing();
}
