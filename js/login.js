fetch("session-status.php", { credentials: "same-origin", cache: "no-store" })
    .then(function (response) { return response.ok ? response.json() : null; })
    .then(function (session) {
        if (session && session.authenticated && session.dashboard) {
            window.location.replace(session.dashboard);
        }
    })
    .catch(function () {
        // Keep the login form usable if the session check is temporarily unavailable.
    });

const passwordButton = document.querySelector(".show-password");
const passwordInput = document.getElementById("password");

if (passwordButton && passwordInput) {
    passwordButton.addEventListener("click", function () {
        const showPassword = passwordInput.type === "password";
        passwordInput.type = showPassword ? "text" : "password";
        passwordButton.classList.toggle("is-visible", showPassword);
        passwordButton.setAttribute("aria-pressed", showPassword);
        passwordButton.setAttribute("aria-label", showPassword ? "Hide password" : "Show password");
    });
}

const errorMessages = {
    missing: "Enter your LRN or email address and password.",
    invalid: "The LRN or email address, or password, is incorrect.",
    unavailable: "Login is unavailable right now. Please try again."
};

const errorCode = new URLSearchParams(window.location.search).get("error");
const errorModal = document.getElementById("login-error-modal");
const errorText = document.getElementById("login-error-message");
const closeErrorModal = errorModal && errorModal.querySelector("[data-login-error-close]");

if (errorModal && errorText && errorMessages[errorCode]) {
    errorText.textContent = errorMessages[errorCode];
    errorModal.hidden = false;
    if (closeErrorModal) closeErrorModal.focus();
}

if (errorModal && closeErrorModal) {
    const dismissErrorModal = function () {
        errorModal.hidden = true;
        const url = new URL(window.location.href);
        url.searchParams.delete("error");
        window.history.replaceState({}, "", url);
    };

    closeErrorModal.addEventListener("click", dismissErrorModal);
    errorModal.addEventListener("click", function (event) {
        if (event.target === errorModal) dismissErrorModal();
    });
    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && !errorModal.hidden) dismissErrorModal();
    });
}

const loginSlider = document.querySelector("[data-login-slider]");
if (loginSlider) {
    const sliderImage = loginSlider.querySelector(".login-hero-image");
    const slides = (loginSlider.dataset.slides || "")
        .split("|")
        .map(function (source) { return source.trim(); })
        .filter(Boolean);

    if (sliderImage && slides.length > 1) {
        let currentSlide = 0;
        const interval = Number.parseInt(loginSlider.dataset.interval, 10) || 2000;

        window.setInterval(function () {
            currentSlide = (currentSlide + 1) % slides.length;
            sliderImage.src = slides[currentSlide];
            sliderImage.classList.remove("is-entering");
            void sliderImage.offsetWidth;
            sliderImage.classList.add("is-entering");
        }, interval);
    }
}
