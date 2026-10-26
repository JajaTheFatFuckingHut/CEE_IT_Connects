<?php $deactSecs = $deactSecs ?? null; ?>
<?php if (($_SESSION['role'] ?? '') === 'student'): ?>
    <script>
        (function () {
            function getBanner() {
                let b = document.getElementById('ojt-deact-banner');
                if (!b) {
                    b = document.createElement('div');
                    b.id = 'ojt-deact-banner';
                    b.setAttribute('role', 'alert');
                    b.style.cssText =
                        'position:fixed;top:80px;left:50%;transform:translateX(-50%);z-index:2000;' +
                        'min-width:360px;max-width:90vw;padding:14px 20px;border-radius:10px;' +
                        'background:#fff3cd;color:#664d03;border:1px solid #ffecb5;' +
                        'box-shadow:0 6px 24px rgba(0,0,0,.2);font-size:14px;display:none;';
                    b.innerHTML = "You've completed your required hours. This account will be deactivated in " +
                        '<b id="ojt-deact-count">0</b> seconds.';
                    document.body.appendChild(b);
                }
                return b;
            }

            window.startGlobalDeact = function (secs) {
                if (window.__deactRunning) return;
                window.__deactRunning = true;

                const end = Date.now() + secs * 1000;
                const banner = getBanner();
                banner.style.display = 'block';
                const countEl = banner.querySelector('#ojt-deact-count');

                const t = setInterval(function () {
                    const left = Math.ceil((end - Date.now()) / 1000);
                    if (countEl) countEl.textContent = Math.max(left, 0);
                    if (left <= 0) {
                        clearInterval(t);
                        // The server guard archives the account and redirects to login
                        setTimeout(function () { location.reload(); }, 1000);
                    }
                }, 250);
            };

            <?php if ($deactSecs !== null): ?>
                document.addEventListener('DOMContentLoaded', function () {
                    startGlobalDeact(<?= (int) $deactSecs ?>);
                });
            <?php endif; ?>
        })();
    </script>
<?php endif; ?>