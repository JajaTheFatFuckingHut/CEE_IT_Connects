<?php $deactSecs = $deactSecs ?? null; ?>
<?php if (($_SESSION['role'] ?? '') === 'student'): ?>
    <script>
        (function () {
            function buildToast() {
                let t = document.getElementById('ojt-deact-toast');
                if (t) return t;
                t = document.createElement('div');
                t.id = 'ojt-deact-toast';
                t.style.cssText =
                    'position:fixed;top:80px;right:16px;display:none;z-index:2147483647;' +
                    'background:#fff;color:#1b2a4e;border:1px solid #f5c2a8;border-left:4px solid #f26b3a;' +
                    'border-radius:8px;padding:8px 12px;font-size:13px;line-height:1.3;' +
                    'box-shadow:0 4px 14px rgba(0,0,0,.15);max-width:240px;';
                t.innerHTML = 'Hours completed. Account deactivates in <b id="ojt-deact-count">0</b>s';
                document.documentElement.appendChild(t);
                return t;
            }

            window.startGlobalDeact = function (secs) {
                if (window.__deactRunning) return;
                window.__deactRunning = true;

                secs = Number(secs);
                if (!isFinite(secs) || secs < 0) secs = 10;   // fallback instead of NaN

                const toast = buildToast();
                const countEl = toast.querySelector('#ojt-deact-count');
                toast.style.display = 'block';

                const end = Date.now() + secs * 1000;
                countEl.textContent = Math.ceil(secs);

                const timer = setInterval(function () {
                    const left = Math.ceil((end - Date.now()) / 1000);
                    countEl.textContent = Math.max(left, 0);
                    if (left <= 0) {
                        clearInterval(timer);
                        setTimeout(function () { location.reload(); }, 800);
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