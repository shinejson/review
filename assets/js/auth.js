// Auth pages (admin & superadmin login): password toggle, submit loading state, error dismissal
(function () {
    'use strict';

    var form = document.getElementById('authForm');
    if (!form) {
        return;
    }

    var toggle = document.getElementById('pwToggle');
    var password = document.getElementById('password');
    var submitBtn = document.getElementById('authSubmit');
    var submitLabel = submitBtn ? submitBtn.querySelector('.auth-submit-label') : null;

    // Show / hide password
    if (toggle && password) {
        toggle.addEventListener('click', function () {
            var show = password.type === 'password';
            password.type = show ? 'text' : 'password';
            toggle.classList.toggle('is-visible', show);
            toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
            toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            password.focus({ preventScroll: true });
        });
    }

    // Loading state while the form submits
    if (submitBtn && submitLabel) {
        form.addEventListener('submit', function () {
            if (form.checkValidity()) {
                submitBtn.classList.add('is-loading');
                submitBtn.disabled = true;
                submitLabel.textContent = 'Signing in\u2026';
            }
        });
    }

    // Hide the server-side error alert once the user edits a field
    var alertBox = document.querySelector('.auth-alert');
    if (alertBox) {
        form.addEventListener('input', function () {
            alertBox.classList.add('is-dismissed');
        });
    }
})();
