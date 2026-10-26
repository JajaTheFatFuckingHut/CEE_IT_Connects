<?php if (($_SESSION['role'] ?? '') === 'student'): ?>
    <div id="ojt-deact-banner" class="alert alert-warning d-none" role="alert">
        You've completed your required hours. This account will be deactivated in
        <b id="ojt-deact-count">0</b> seconds.
    </div>
    <style>
        #ojt-deact-banner {
            position: fixed;
            top: 80px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 2000;
            min-width: 360px;
            max-width: 90vw;
            box-shadow: 0 6px 24px rgba(0, 0, 0, .2);
        }
    </style>
    <script>
        window.startGlobalDeact = function (secs) {
            if (window.__deactRunning) return;
            window.__deactRunning = true;
            const end = Date.now() + secs * 1000;
            const banner = document.getElementById('ojt-deact-banner');
            const countEl = document.getElementById('ojt-deact-count');
            banner.classList.remove('d-none');

            const t = setInterval(() => {
                const left = Math.ceil((end - Date.now()) / 1000);
                countEl.textContent = Math.max(left, 0);
                if (left <= 0) {
                    clearInterval(t);
                    // The server guard archives the account and redirects to login
                    setTimeout(() => location.reload(), 1000);
                }
            }, 250);
        };
        <?php if ($deactSecs !== null): ?>
            document.addEventListener('DOMContentLoaded', () => startGlobalDeact(<?= (int) $deactSecs ?>));
        <?php endif; ?>
    </script>
<?php endif; ?>