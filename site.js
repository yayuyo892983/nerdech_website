/* nerdech — site.js
   1. ナビ(ハンバーガー)   2. スクロールアニメ   3. 粒子・光暈・走査線   4. contact フォーム */
(function () {
    'use strict';

    var doc = document;
    var root = doc.documentElement;
    root.classList.add('js');

    var mqReduce = window.matchMedia('(prefers-reduced-motion: reduce)');
    var mqCompact = window.matchMedia('(max-width: 900px), (hover: none)');

    function onChange(mq, fn) {
        if (mq.addEventListener) mq.addEventListener('change', fn);
        else if (mq.addListener) mq.addListener(fn);
    }

    /* =====================
       1. ナビ (≤900px でハンバーガー)
    ===================== */
    function initNav() {
        var header = doc.querySelector('.header');
        var toggle = doc.querySelector('.nav-toggle');
        var nav = doc.getElementById('site-nav');
        if (!header || !toggle || !nav) return;

        var links = [].slice.call(nav.querySelectorAll('a'));
        var logo = header.querySelector('.header__logo');
        var inertTargets = [].slice.call(doc.querySelectorAll('main, footer'));
        var mqMobile = window.matchMedia('(max-width: 900px)');
        var isOpen = false;

        function setInert(value) {
            inertTargets.forEach(function (el) {
                if (value) el.setAttribute('inert', '');
                else el.removeAttribute('inert');
            });
        }

        function open() {
            isOpen = true;
            header.classList.add('is-open');
            root.classList.add('nav-open');
            toggle.setAttribute('aria-expanded', 'true');
            toggle.setAttribute('aria-label', 'メニューを閉じる');
            setInert(true);
            if (links[0]) links[0].focus();
        }

        function close(returnFocus) {
            if (!isOpen) return;
            isOpen = false;
            header.classList.remove('is-open');
            root.classList.remove('nav-open');
            toggle.setAttribute('aria-expanded', 'false');
            toggle.setAttribute('aria-label', 'メニューを開く');
            setInert(false);
            if (returnFocus) toggle.focus();
        }

        toggle.addEventListener('click', function () {
            if (isOpen) close(true);
            else open();
        });

        doc.addEventListener('keydown', function (e) {
            if (!isOpen) return;
            if (e.key === 'Escape') {
                e.preventDefault();
                close(true);
                return;
            }
            if (e.key !== 'Tab') return;

            // フォーカスをロゴ / ボタン / ナビリンクの中で循環させる
            var order = [logo, toggle].concat(links).filter(Boolean);
            var first = order[0];
            var last = order[order.length - 1];
            var active = doc.activeElement;
            if (e.shiftKey && active === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && active === last) {
                e.preventDefault();
                first.focus();
            }
        });

        links.forEach(function (a) {
            a.addEventListener('click', function () { close(false); });
        });

        onChange(mqMobile, function () {
            if (!mqMobile.matches) close(false);
        });
    }

    /* =====================
       2. スクロールアニメ
       data-reveal="up|down|left|right|fade"
       data-reveal-each="up,left,right" (親に付けると子へ順に割り当て)
       data-reveal-step="80" (子へ遅延を段階付け。ms)
    ===================== */
    function initReveal() {
        [].slice.call(doc.querySelectorAll('[data-reveal-each]')).forEach(function (parent) {
            var pattern = (parent.getAttribute('data-reveal-each') || 'up').split(',').map(function (v) { return v.trim(); });
            var step = parseInt(parent.getAttribute('data-reveal-step') || '0', 10);
            [].slice.call(parent.children).forEach(function (child, i) {
                if (!child.hasAttribute('data-reveal')) child.setAttribute('data-reveal', pattern[i % pattern.length]);
                if (step && !child.style.getPropertyValue('--d')) child.style.setProperty('--d', (i * step) + 'ms');
            });
        });

        var els = [].slice.call(doc.querySelectorAll('[data-reveal]'));
        if (!els.length) return;

        function showAll() {
            els.forEach(function (el) { el.classList.add('is-visible'); });
        }

        if (mqReduce.matches || !('IntersectionObserver' in window)) {
            showAll();
            return;
        }

        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('is-visible');
                io.unobserve(entry.target);
            });
        }, { threshold: 0.12, rootMargin: '0px 0px -6% 0px' });

        els.forEach(function (el) { io.observe(el); });

        onChange(mqReduce, function () {
            if (mqReduce.matches) showAll();
        });
    }

    /* =====================
       3. 粒子 canvas / カーソルの光暈 / 走査線
       モバイル(≤900px・hover なし)と prefers-reduced-motion では作らない。
       粒子の色は、その位置の面が明るいか暗いかを 4x4 で標本化して決める。
    ===================== */
    var effects = (function () {
        var LIGHT = '.section--light, .block--light, .contact-split__left';
        var COLS = 4;
        var ROWS = 4;
        var canvas = null;
        var ctx = null;
        var glow = null;
        var scan = null;
        var raf = 0;
        var timer = 0;
        var particles = [];
        var tones = [];
        var w = 0;
        var h = 0;
        var mx = 0;
        var my = 0;
        var cx = 0;
        var cy = 0;
        var sampleQueued = false;

        function resize() {
            w = canvas.width = window.innerWidth;
            h = canvas.height = window.innerHeight;
        }

        function makeParticles() {
            particles = [];
            var count = Math.min(120, Math.round((window.innerWidth * window.innerHeight) / 11000));
            for (var i = 0; i < count; i++) {
                particles.push({
                    x: Math.random() * window.innerWidth,
                    y: Math.random() * window.innerHeight,
                    vx: (Math.random() - 0.5) * 0.3,
                    vy: (Math.random() - 0.5) * 0.3,
                    size: Math.random() * 1.5 + 0.3,
                    alpha: Math.random() * 0.5 + 0.1,
                    flicker: Math.random() * Math.PI * 2
                });
            }
        }

        function sample() {
            sampleQueued = false;
            if (!canvas) return;
            tones = [];
            for (var r = 0; r < ROWS; r++) {
                for (var c = 0; c < COLS; c++) {
                    var el = doc.elementFromPoint((c + 0.5) * w / COLS, (r + 0.5) * h / ROWS);
                    tones.push(el && el.closest(LIGHT) ? 0 : 1); // 1 = 暗い面 → 白い粒
                }
            }
        }

        function queueSample() {
            if (sampleQueued) return;
            sampleQueued = true;
            window.requestAnimationFrame(sample);
        }

        function onMove(e) {
            mx = e.clientX;
            my = e.clientY;
        }

        function frame() {
            ctx.clearRect(0, 0, w, h);
            for (var i = 0; i < particles.length; i++) {
                var p = particles[i];
                p.x += p.vx;
                p.y += p.vy;
                p.flicker += 0.02;
                if (p.x < 0) p.x = w;
                if (p.x > w) p.x = 0;
                if (p.y < 0) p.y = h;
                if (p.y > h) p.y = 0;
                var col = Math.min(COLS - 1, Math.floor(p.x / w * COLS));
                var row = Math.min(ROWS - 1, Math.floor(p.y / h * ROWS));
                var dark = tones.length ? tones[row * COLS + col] : 1;
                var a = p.alpha * (0.7 + 0.3 * Math.sin(p.flicker));
                ctx.beginPath();
                ctx.arc(p.x, p.y, p.size, 0, Math.PI * 2);
                ctx.fillStyle = 'rgba(' + (dark ? '255,255,255' : '0,0,0') + ',' + a + ')';
                ctx.fill();
            }
            cx += (mx - cx) * 0.08;
            cy += (my - cy) * 0.08;
            glow.style.transform = 'translate(' + cx + 'px,' + cy + 'px)';
            raf = window.requestAnimationFrame(frame);
        }

        function start() {
            if (canvas) return;
            canvas = doc.createElement('canvas');
            canvas.className = 'particle-canvas';
            canvas.setAttribute('aria-hidden', 'true');
            doc.body.appendChild(canvas);
            ctx = canvas.getContext('2d');

            glow = doc.createElement('div');
            glow.className = 'cursor-glow';
            glow.setAttribute('aria-hidden', 'true');
            doc.body.appendChild(glow);

            scan = doc.createElement('div');
            scan.className = 'scanline';
            scan.setAttribute('aria-hidden', 'true');
            doc.body.appendChild(scan);

            mx = cx = window.innerWidth / 2;
            my = cy = window.innerHeight / 2;
            resize();
            makeParticles();
            sample();

            window.addEventListener('resize', onResize);
            window.addEventListener('scroll', queueSample, { passive: true });
            doc.addEventListener('mousemove', onMove);
            timer = window.setInterval(queueSample, 600);
            raf = window.requestAnimationFrame(frame);
        }

        function onResize() {
            resize();
            makeParticles();
            queueSample();
        }

        function stop() {
            if (!canvas) return;
            window.cancelAnimationFrame(raf);
            window.clearInterval(timer);
            window.removeEventListener('resize', onResize);
            window.removeEventListener('scroll', queueSample);
            doc.removeEventListener('mousemove', onMove);
            [canvas, glow, scan].forEach(function (el) {
                if (el && el.parentNode) el.parentNode.removeChild(el);
            });
            canvas = ctx = glow = scan = null;
            particles = [];
            tones = [];
        }

        function sync() {
            if (mqReduce.matches || mqCompact.matches) stop();
            else start();
        }

        return { sync: sync };
    })();

    /* =====================
       4. contact フォーム
       - ?sent=1 / ?error=1 の表示切替
       - 送信時に「ご用件」を message の先頭へ【ご用件: 〇〇】として付与
         (contact_handler.php は name / email / message だけを受け取る)
    ===================== */
    function initContact() {
        var form = doc.getElementById('contact-form');
        if (!form) return;

        var sent = doc.getElementById('form-sent');
        var error = doc.getElementById('form-error');
        var note = doc.querySelector('.cs-right__note');
        var params = new URLSearchParams(window.location.search);

        if (params.get('sent') === '1') {
            form.hidden = true;
            if (sent) sent.hidden = false;
            if (note) note.hidden = true;
        }
        if (params.get('error') === '1' && error) {
            error.hidden = false;
        }

        var topic = doc.getElementById('contact-topic');
        var message = doc.getElementById('contact-message');
        form.addEventListener('submit', function () {
            if (!topic || !message) return;
            // 戻る操作で復元された値に二重付与しない
            var body = message.value.replace(/^【ご用件:[^】]*】\n?/, '');
            message.value = '【ご用件: ' + topic.value + '】\n' + body;
        });
    }

    /* ===================== */
    function init() {
        initNav();
        initReveal();
        effects.sync();
        onChange(mqReduce, effects.sync);
        onChange(mqCompact, effects.sync);
        initContact();
    }

    if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', init);
    else init();
})();
