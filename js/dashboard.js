const menuButton = document.querySelector(".menu-toggle");
const menu = document.querySelector(".menu-panel");
const themeButton = document.querySelector(".theme-toggle");

const teacherMessageBadges = document.querySelectorAll("[data-teacher-unread-count]");
if (teacherMessageBadges.length) {
    const refreshTeacherMessageBadges = function () {
        const unreadUrl = new URL("../teacher-messages?unread_count=1", document.baseURI);
        fetch(unreadUrl, { credentials: "same-origin", cache: "no-store" })
            .then(function (response) { if (!response.ok) throw new Error("Message count unavailable"); return response.json(); })
            .then(function (data) {
                const unread = Math.max(0, Number(data.unread) || 0);
                teacherMessageBadges.forEach(function (badge) {
                    badge.textContent = unread > 99 ? "99+" : String(unread);
                    badge.hidden = unread === 0;
                });
            })
            .catch(function () {});
    };
    refreshTeacherMessageBadges();
    window.setInterval(refreshTeacherMessageBadges, 15000);
}

const dashboardManilaClock = document.getElementById("dashboard-manila-clock");
const dashboardManilaDate = document.getElementById("dashboard-manila-date");
if (dashboardManilaClock) {
    const updateDashboardManilaClock = function () {
        const now = new Date();
        if (dashboardManilaDate) {
            dashboardManilaDate.textContent = new Intl.DateTimeFormat("en-US", {
                timeZone: "Asia/Manila", weekday: "long", month: "long", day: "numeric", year: "numeric"
            }).format(now).toUpperCase();
        }
        dashboardManilaClock.textContent = new Intl.DateTimeFormat("en-US", {
            timeZone: "Asia/Manila", hour: "2-digit", minute: "2-digit", second: "2-digit", hour12: true
        }).format(now);
        dashboardManilaClock.dateTime = now.toISOString();
    };
    updateDashboardManilaClock();
    window.setInterval(updateDashboardManilaClock, 1000);
}

const topbarSignout = document.querySelector(".topbar .menu-panel .signout-link");
const topbarControls = document.querySelector(".topbar .nav-menu");
if (topbarSignout && topbarControls) {
    topbarSignout.classList.remove("menu-link", "signout-link");
    topbarSignout.classList.add("student-signout-button");
    topbarControls.appendChild(topbarSignout);
}

document.querySelectorAll(".menu-panel .menu-link[href]").forEach(function (link) {
    if (new URL(link.href, window.location.href).pathname === window.location.pathname) {
        link.classList.add("active");
        link.setAttribute("aria-current", "page");
    }
});

function setDarkMode(enabled) {
    document.body.classList.toggle("dark-mode", enabled);
    document.documentElement.classList.toggle("dark-mode", enabled);
    localStorage.setItem("spi-dark-mode", enabled ? "on" : "off");

    if (themeButton) {
        const label = enabled ? "Switch to light mode" : "Switch to dark mode";
        themeButton.setAttribute("aria-pressed", enabled);
        themeButton.setAttribute("aria-label", label);
        themeButton.setAttribute("title", label);
    }
}

setDarkMode(localStorage.getItem("spi-dark-mode") === "on");

if (menuButton && menu) {
    menuButton.addEventListener("click", function () {
        const isOpen = menu.hidden;
        menu.hidden = !isOpen;
        menuButton.setAttribute("aria-expanded", isOpen);
        menuButton.setAttribute("aria-label", isOpen ? "Close menu" : "Open menu");
    });

    document.addEventListener("click", function (event) {
        if (!menu.contains(event.target) && !menuButton.contains(event.target)) {
            menu.hidden = true;
            menuButton.setAttribute("aria-expanded", "false");
            menuButton.setAttribute("aria-label", "Open menu");
        }
    });

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
            menu.hidden = true;
            menuButton.setAttribute("aria-expanded", "false");
            menuButton.setAttribute("aria-label", "Open menu");
            menuButton.focus();
        }
    });
}

if (themeButton) {
    themeButton.addEventListener("click", function () {
        const darkModeIsOn = document.body.classList.contains("dark-mode");
        setDarkMode(!darkModeIsOn);
    });
}

document.querySelectorAll(".signout-link, .student-signout-button").forEach(function (signoutLink) {
    signoutLink.addEventListener("click", function (event) {
        event.preventDefault();

        const dialog = document.createElement("dialog");
        dialog.className = "logout-confirmation";
        dialog.setAttribute("aria-labelledby", "logout-confirmation-title");
        dialog.innerHTML = `
            <div class="logout-confirmation-icon" aria-hidden="true">↪</div>
            <h2 id="logout-confirmation-title">Sign out?</h2>
            <p>Are you sure you want to log out of your account?</p>
            <div class="logout-confirmation-actions">
                <button class="logout-cancel" type="button">Cancel</button>
                <a class="logout-confirm" href="${signoutLink.href}">Log out</a>
            </div>`;

        document.body.appendChild(dialog);
        document.body.classList.add("dashboard-modal-open");

        function removeDialog() {
            document.body.classList.remove("dashboard-modal-open");
            dialog.remove();
            signoutLink.focus();
        }

        dialog.addEventListener("close", removeDialog, { once: true });
        dialog.querySelector(".logout-cancel").addEventListener("click", function () {
            dialog.close();
        });
        dialog.addEventListener("click", function (dialogEvent) {
            if (dialogEvent.target === dialog) dialog.close();
        });
        dialog.showModal();
        dialog.querySelector(".logout-cancel").focus();
    });
});

document.querySelectorAll(".dashboard-modal-overlay").forEach(function (modal) {
    const closeButton = modal.querySelector(".dashboard-modal-close");
    if (!closeButton) return;

    function hideModal() {
        modal.hidden = true;
        document.body.classList.remove("dashboard-modal-open");
        const trigger = document.getElementById(modal.dataset.triggerId || "");
        if (trigger) {
            trigger.setAttribute("aria-expanded", "false");
            trigger.focus();
        }
    }

    modal.querySelectorAll(".dashboard-modal-close").forEach(function (button) {
        button.addEventListener("click", hideModal);
    });
    modal.addEventListener("click", function (event) {
        if (event.target === modal) hideModal();
    });
    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && !modal.hidden) hideModal();
    });

    if (!modal.hidden) {
        closeButton.focus();
        document.body.classList.add("dashboard-modal-open");
    }
});

document.querySelectorAll("[data-modal-target]").forEach(function (trigger) {
    const modal = document.getElementById(trigger.dataset.modalTarget || "");
    if (!modal) return;

    trigger.addEventListener("click", function () {
        modal.hidden = false;
        trigger.setAttribute("aria-expanded", "true");
        document.body.classList.add("dashboard-modal-open");
        const closeButton = modal.querySelector(".dashboard-modal-close");
        if (closeButton) closeButton.focus();
    });
});
