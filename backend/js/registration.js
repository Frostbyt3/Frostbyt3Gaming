/* registration.js */
document.addEventListener('DOMContentLoaded', function () {
    const passwordInput = document.getElementById('password');
    const confirmInput = document.getElementById('confirm_password');
    const strengthFill = document.getElementById('password-strength-fill');
    const strengthText = document.getElementById('password-strength-text');
    const matchText = document.getElementById('password-match-text');
    const passwordToggleButtons = document.querySelectorAll('.fbg-password-toggle');

    function fbgEvaluatePasswordStrength(password) {
        const weakList = [
            'password',
            'password123',
            '12345678',
            '123456789',
            '1234567890',
            'qwerty',
            'qwerty123',
            'letmein',
            'welcome',
            'admin',
            'admin123',
            'abc123',
            'iloveyou'
        ];

        if (!password) {
            return {
                score: 0,
                label: 'Enter a password',
                width: '0%',
                className: '',
                color: '#ff4d4f'
            };
        }

        if (weakList.includes(password.toLowerCase())) {
            return {
                score: 0,
                label: 'Very weak',
                width: '10%',
                className: 'is-weak',
                color: '#ff4d4f'
            };
        }

        let score = 0;

        if (password.length >= 10) score++;
        if (password.length >= 14) score++;
        if (/[a-z]/.test(password)) score++;
        if (/[A-Z]/.test(password)) score++;
        if (/\d/.test(password)) score++;
        if (/[^a-zA-Z0-9]/.test(password)) score++;

        if (score <= 2) {
            return {
                score,
                label: 'Weak',
                width: '25%',
                className: 'is-weak',
                color: '#ff4d4f'
            };
        }

        if (score <= 4) {
            return {
                score,
                label: 'Meh',
                width: '50%',
                className: 'is-meh',
                color: '#ffb84d'
            };
        }

        if (score === 5) {
            return {
                score,
                label: 'Good',
                width: '75%',
                className: 'is-good',
                color: '#7bd88f'
            };
        }

        return {
            score,
            label: 'Strong',
            width: '100%',
            className: 'is-strong',
            color: '#66d9ef'
        };
    }

    function fbgSetPasswordVisibility(button, input, isVisible) {
        input.type = isVisible ? 'text' : 'password';

        button.setAttribute('aria-pressed', isVisible ? 'true' : 'false');
        button.setAttribute('aria-label', isVisible ? 'Hide password' : 'Show password');
        button.setAttribute('title', isVisible ? 'Hide password' : 'Show password');

        const icon = button.querySelector('i');
        if (icon) {
            icon.classList.remove('fa-eye', 'fa-eye-slash');
            icon.classList.add(isVisible ? 'fa-eye' : 'fa-eye-slash');
        }
    }

    function fbgInitPasswordToggles() {
        passwordToggleButtons.forEach(function (button) {
            const targetSelector = button.getAttribute('data-toggle-password');
            if (!targetSelector) {
                return;
            }

            const input = document.querySelector(targetSelector);
            if (!input) {
                return;
            }

            button.addEventListener('click', function () {
                const isVisible = input.type !== 'password';
                fbgSetPasswordVisibility(button, input, !isVisible);
            });
        });
    }

    function updateStrengthMeter() {
        if (!passwordInput || !strengthFill || !strengthText) {
            return;
        }

        const result = fbgEvaluatePasswordStrength(passwordInput.value);

        strengthFill.style.width = result.width;
        strengthFill.style.backgroundColor = result.color;

        strengthText.textContent = result.label;
        strengthText.className = 'password-strength-text';

        if (result.className) {
            strengthText.classList.add(result.className);
        }
    }

    function updatePasswordMatch() {
        if (!passwordInput || !confirmInput || !matchText) {
            return;
        }

        matchText.textContent = '';
        matchText.className = 'form-help-text';

        if (confirmInput.value === '') {
            return;
        }

        if (passwordInput.value === confirmInput.value) {
            matchText.textContent = 'Passwords match.';
            matchText.classList.add('is-success');
        } else {
            matchText.textContent = 'Passwords do not match.';
            matchText.classList.add('is-error');
        }
    }

    if (passwordInput) {
        passwordInput.addEventListener('input', function () {
            updateStrengthMeter();
            updatePasswordMatch();
        });
    }

    if (confirmInput) {
        confirmInput.addEventListener('input', updatePasswordMatch);
    }

    fbgInitPasswordToggles();
    updateStrengthMeter();
    updatePasswordMatch();
});