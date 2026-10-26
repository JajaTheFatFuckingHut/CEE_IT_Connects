<?php $deactSecs = $deactSecs ?? null; ?>
<?php if (($_SESSION['role'] ?? '') === 'student'): ?>
    <script>
        (function () {
            function buildModal() {
                let m = document.getElementById('ojt-deact-modal');
                if (m) return m;

                m = document.createElement('div');
                m.id = 'ojt-deact-modal';
                m.style.cssText =
                    'position:fixed;top:0;left:0;width:100vw;height:100vh;display:none;' +
                    'align-items:center;justify-content:center;background:rgba(15,23,42,.75);' +
                    'z-index:2147483647;font-family:inherit;';

                m.innerHTML =
                    '<div style="background:#fff;color:#1b2a4e;border-radius:16px;padding:32px 36px;' +
                    'width:min(420px,90vw);text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.4);">' +
                    '<div style="font-size:42px;color:#f26b3a;margin-bottom:8px;">&#9203;</div>' +
                    '<h3 style="margin:0 0 10px;font-size:20px;font-weight:700;">OJT Hours Completed</h3>' +
                    '<p style="margin:0 0 18px;font-size:14px;color:#64748b;">' +
                    'You have completed your required hours. This account will be deactivated in' +
                    '</p>' +
                    '<div id="ojt-deact-count" style="font-size:56px;font-weight:700;color:#f26b3a;line-height:1;">0</div>' +
                    '<div style="font-size:13px;color:#64748b;margin-top:6px;">seconds</div>' +
                    '</div>';

                // attach to <html> so no body/section container can hide or clip it
                document.documentElement.appendChild(m);
                return m;
            }

            window.startGlobalDeact = function (secs) {
                if (window.__deactRunning) return;
                window.__deactRunning = true;

                const modal = buildModal();
                const countEl = modal.querySelector('#ojt-deact-count');
                modal.style.display = 'flex';
                document.body.style.overflow = 'hidden';

                const end = Date.now() + secs * 1000;
                countEl.textContent = Math.max(Math.ceil(secs), 0);

                const t = setInterval(function () {
                    const left = Math.ceil((end - Date.now()) / 1000);
                    countEl.textContent = Math.max(left, 0);
                    if (left <= 0) {
                        clearInterval(t);
                        // Any page load runs deact-guard.php, which archives the account and redirects to login
                        setTimeout(function () { location.reload(); }, 800);
                    }
                }, 250);
            };

            console.log('deact-banner loaded');

            <?php if ($deactSecs !== null): ?>
                document.addEventListener('DOMContentLoaded', function () {
                    startGlobalDeact(<?= (int) $deactSecs ?>);
                });
            <?php endif; ?>
        })();
    </script>
<?php endif; ?>