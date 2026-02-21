(function () {
  'use strict';

  var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function initReveal() {
    if (reducedMotion) {
      document.querySelectorAll('[data-reveal]').forEach(function (el) { el.classList.add('visible'); });
      document.querySelectorAll('section[data-reveal-type]').forEach(function (el) { el.classList.add('section-in'); });
      return;
    }
    var observer = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add('visible');
            if (entry.target.classList.contains('module-card')) {
              var cards = Array.from(document.querySelectorAll('.module-card'));
              var index = cards.indexOf(entry.target);
              entry.target.style.transitionDelay = (index % 6) * 0.05 + 's';
            }
          }
        });
      },
      { rootMargin: '0px 0px -40px 0px', threshold: 0.1 }
    );
    document.querySelectorAll('[data-reveal]').forEach(function (el) { observer.observe(el); });

    var sectionObserver = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) entry.target.classList.add('section-in');
        });
      },
      { rootMargin: '0px 0px -80px 0px', threshold: 0.05 }
    );
    document.querySelectorAll('section[data-reveal-type]').forEach(function (el) { sectionObserver.observe(el); });
  }

  function initNav() {
    var nav = document.getElementById('nav');
    if (!nav) return;
    function update() {
      if (window.scrollY > 20) nav.classList.add('scrolled');
      else nav.classList.remove('scrolled');
    }
    window.addEventListener('scroll', update, { passive: true });
    update();
  }

  function initParallax() {
    if (reducedMotion) return;
    var heroVisual = document.querySelector('.hero-visual');
    if (!heroVisual) return;
    window.addEventListener('scroll', function () {
      var scrolled = window.pageYOffset;
      var rate = scrolled * 0.3;
      heroVisual.style.transform = 'translateY(' + rate + 'px)';
    }, { passive: true });
  }

  function initHeroBadge() {
    var badge = document.querySelector('.hero-badge');
    if (!badge || reducedMotion) return;
    badge.addEventListener('mouseenter', function () {
      this.style.transform = 'scale(1.05)';
    });
    badge.addEventListener('mouseleave', function () {
      this.style.transform = 'scale(1)';
    });
  }

  function initSmoothScroll() {
    document.querySelectorAll('a[href^="#"]').forEach(function (anchor) {
      anchor.addEventListener('click', function (e) {
        var href = this.getAttribute('href');
        if (href === '#') return;
        var target = document.querySelector(href);
        if (target) {
          e.preventDefault();
          var navHeight = document.getElementById('nav').offsetHeight;
          var targetPosition = target.offsetTop - navHeight - 20;
          window.scrollTo({
            top: targetPosition,
            behavior: 'smooth'
          });
        }
      });
    });
  }

  initReveal();
  initNav();
  initParallax();
  initHeroBadge();
  initSmoothScroll();
})();
