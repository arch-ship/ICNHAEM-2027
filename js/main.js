/* ICNHAEM-2027 — shared UI behaviour */
(function () {
    'use strict';

    /* ---------- Mobile navigation ---------- */
    var toggle = document.querySelector('.menu-toggle');
    var mobileNav = document.querySelector('.mobile-nav');
    var header = document.querySelector('.site-header');

    function closeMenu() {
        if (!toggle || !mobileNav) return;
        mobileNav.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-label', 'Open menu');
        document.body.classList.remove('nav-open');
    }

    if (toggle && mobileNav) {
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-label', 'Open menu');

        toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            var isOpen = mobileNav.classList.toggle('open');
            toggle.setAttribute('aria-expanded', String(isOpen));
            toggle.setAttribute('aria-label', isOpen ? 'Close menu' : 'Open menu');
            document.body.classList.toggle('nav-open', isOpen);
        });

        mobileNav.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', closeMenu);
        });

        document.addEventListener('click', function (e) {
            if (mobileNav.classList.contains('open') &&
                !mobileNav.contains(e.target) && !toggle.contains(e.target)) {
                closeMenu();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeMenu();
        });

        window.addEventListener('resize', function () {
            if (window.innerWidth > 900) closeMenu();
        });
    }

    /* ---------- Subtle header shadow on scroll ---------- */
    function onScroll() {
        if (header) header.classList.toggle('scrolled', window.scrollY > 8);
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();

    /* ---------- Active navigation state ---------- */
    var current = (location.pathname.split('/').pop() || 'index.html').toLowerCase();
    if (current === '') current = 'index.html';
    document.querySelectorAll('.nav-link, .mobile-nav a').forEach(function (link) {
        var target = (link.getAttribute('href') || '').split('/').pop().split('?')[0].toLowerCase();
        if (target === current) link.classList.add('active');
    });

    /* ---------- Countdown to the conference ---------- */
    var targetDate = new Date('2027-03-18T09:00:00').getTime();
    var ids = ['days', 'hours', 'mins', 'secs'];
    var nodes = {};
    ids.forEach(function (id) { nodes[id] = document.getElementById('count-' + id); });

    function pad(n) { return n < 10 ? '0' + n : String(n); }

    function tick() {
        var distance = targetDate - Date.now();
        if (distance <= 0) {
            ids.forEach(function (id) {
                if (nodes[id]) nodes[id].textContent = '00';
            });
            var label = document.getElementById('count-status');
            if (label) label.textContent = 'The conference is underway — welcome to ICNHAEM-2027!';
            return;
        }
        var days = Math.floor(distance / 86400000);
        var hours = Math.floor((distance % 86400000) / 3600000);
        var mins = Math.floor((distance % 3600000) / 60000);
        var secs = Math.floor((distance % 60000) / 1000);
        if (nodes.days) nodes.days.textContent = pad(days);
        if (nodes.hours) nodes.hours.textContent = pad(hours);
        if (nodes.mins) nodes.mins.textContent = pad(mins);
        if (nodes.secs) nodes.secs.textContent = pad(secs);
    }

    if (nodes.days) {
        tick();
        window.setInterval(tick, 1000);
    }

    /* ---------- Reveal on scroll ---------- */
    var reveals = document.querySelectorAll('.reveal');
    if (reveals.length) {
        if (!('IntersectionObserver' in window) ||
            window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            reveals.forEach(function (el) { el.classList.add('visible'); });
        } else {
            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('visible');
                        observer.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' });
            reveals.forEach(function (el) { observer.observe(el); });
        }
    }

    /* ---------- Contact form: open the visitor's mail client ---------- */
    var contactForm = document.getElementById('contactForm');
    if (contactForm) {
        contactForm.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!contactForm.reportValidity()) return;
            var data = new FormData(contactForm);
            var name = String(data.get('name') || '').trim();
            var email = String(data.get('email') || '').trim();
            var subject = String(data.get('subject') || 'General enquiry').trim();
            var message = String(data.get('message') || '').trim();
            var body = 'Name: ' + name + '\nEmail: ' + email + '\n\n' + message;
            var mailto = 'mailto:info@icnhaem2027.com?subject=' +
                encodeURIComponent('[ICNHAEM-2027] ' + subject) +
                '&body=' + encodeURIComponent(body);
            window.location.href = mailto;
            var status = document.getElementById('contactStatus');
            if (status) {
                status.textContent = 'Your mail app should open now. If it does not, e-mail info@icnhaem2027.com directly.';
                status.classList.add('show');
            }
        });
    }
})();
