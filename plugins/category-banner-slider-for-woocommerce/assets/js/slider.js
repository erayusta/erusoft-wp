/* Category Banner Slider — bağımsız (jQuery'siz) slider motoru */
(function () {
    'use strict';

    function initSlider(root) {
        var config = {};
        try { config = JSON.parse(root.getAttribute('data-eru-cbs') || '{}'); } catch (e) { config = {}; }

        var track  = root.querySelector('.eru-cbs__track');
        var slides = root.querySelectorAll('.eru-cbs__slide');
        if (!track || slides.length === 0) { return; }

        var index = 0;
        var count = slides.length;
        var timer = null;

        function go(i) {
            index = (i + count) % count;
            track.style.transform = 'translateX(' + (-index * 100) + '%)';
            updateDots();
        }
        function next() { go(index + 1); }
        function prev() { go(index - 1); }

        var prevBtn = root.querySelector('.eru-cbs__arrow--prev');
        var nextBtn = root.querySelector('.eru-cbs__arrow--next');
        if (prevBtn) { prevBtn.addEventListener('click', function () { prev(); restart(); }); }
        if (nextBtn) { nextBtn.addEventListener('click', function () { next(); restart(); }); }

        var dotsWrap = root.querySelector('.eru-cbs__dots');
        var dots = [];
        if (dotsWrap && count > 1) {
            for (var d = 0; d < count; d++) {
                (function (di) {
                    var dot = document.createElement('button');
                    dot.type = 'button';
                    dot.className = 'eru-cbs__dot';
                    dot.setAttribute('aria-label', 'Slayt ' + (di + 1));
                    dot.addEventListener('click', function () { go(di); restart(); });
                    dotsWrap.appendChild(dot);
                    dots.push(dot);
                })(d);
            }
        }
        function updateDots() {
            for (var i = 0; i < dots.length; i++) {
                if (i === index) { dots[i].classList.add('is-active'); }
                else { dots[i].classList.remove('is-active'); }
            }
        }

        var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        function start() {
            stop();
            if (config.autoplay && !reduced && count > 1) {
                timer = window.setInterval(next, config.speed || 5000);
            }
        }
        function stop() { if (timer) { window.clearInterval(timer); timer = null; } }
        function restart() { stop(); start(); }

        root.addEventListener('mouseenter', stop);
        root.addEventListener('mouseleave', start);

        var startX = null;
        track.addEventListener('touchstart', function (e) { startX = e.touches[0].clientX; stop(); }, { passive: true });
        track.addEventListener('touchend', function (e) {
            if (startX === null) { return; }
            var dx = e.changedTouches[0].clientX - startX;
            if (Math.abs(dx) > 40) { if (dx < 0) { next(); } else { prev(); } }
            startX = null;
            start();
        });

        go(0);
        start();
    }

    function initAll() {
        var roots = document.querySelectorAll('.eru-cbs');
        for (var i = 0; i < roots.length; i++) { initSlider(roots[i]); }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();
