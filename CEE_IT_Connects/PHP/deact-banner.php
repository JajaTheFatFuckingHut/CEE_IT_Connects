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
                    'position:fixed;top:80px;right:16px;display:none;z-index:900;' +
                    'background:#fff;color:#1b2a4e;border:1px solid #f5c2a8;border-left:4px solid #f26b3a;' +
                    'border-radius:8px;padding:8px 12px;font-size:13px;line-height:1.3;' +
                    'box-shadow:0 4px 14px rgba(0,0,0,.15);max-width:260px;';
                t.innerHTML = 'Hours completed. Account deactivates in <b id="ojt-deact-count">…</b>';
                document.documentElement.appendChild(t);

                // small screens: sit beside the sidebar, shrink, don't cover content
                const mq = window.matchMedia('(max-width: 768px)');
                const place = () => {
                    if (mq.matches) {
                        t.style.left = '68px';     // clears the 60px mobile sidebar
                        t.style.right = '8px';
                        t.style.top = '76px';
                        t.style.maxWidth = 'none';
                        t.style.fontSize = '11.5px';
                        t.style.padding = '6px 10px';
                    } else {
                        t.style.left = 'auto';
                        t.style.right = '16px';
                        t.style.top = '80px';
                        t.style.maxWidth = '260px';
                        t.style.fontSize = '13px';
                        t.style.padding = '8px 12px';
                    }
                };
                place();
                mq.addEventListener('change', place);
                return t;
            }

            function fmt(totalSecs) {
                const s = Math.max(0, Math.floor(totalSecs));
                const d = Math.floor(s / 86400);
                const h = Math.floor((s % 86400) / 3600);
                const m = Math.floor((s % 3600) / 60);
                const sec = s % 60;
                const p = n => String(n).padStart(2, '0');
                return d + 'd ' + p(h) + 'h ' + p(m) + 'm ' + p(sec) + 's';
            }

            window.startGlobalDeact = function (secs) {
                if (window.__deactRunning) return;
                window.__deactRunning = true;

                secs = Number(secs);
                if (!isFinite(secs) || secs < 0) secs = 30 * 86400;   // fallback

                const toast = buildToast();
                const countEl = toast.querySelector('#ojt-deact-count');
                toast.style.display = 'block';

                const end = Date.now() + secs * 1000;
                countEl.textContent = fmt(Math.ceil(secs));

                const timer = setInterval(function () {
                    const left = Math.ceil((end - Date.now()) / 1000);
                    countEl.textContent = fmt(left);
                    if (left <= 0) {
                        clearInterval(timer);
                        setTimeout(function () { location.reload(); }, 800);
                    }
                }, 1000);
            };

            <?php if ($deactSecs !== null): ?>
                document.addEventListener('DOMContentLoaded', function () {
                    startGlobalDeact(<?= (int) $deactSecs ?>);
                });
            <?php endif; ?>
        })();
    </script>
<?php endif; ?>