(function () {
  'use strict';

  // 1. Navigation Scroll Effect
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

  // 2. Interactive Pricing Toggle (Monthly vs Annual)
  function initPricingToggle() {
    var monthlyBtn = document.getElementById('btn-monthly');
    var annualBtn = document.getElementById('btn-annual');
    if (!monthlyBtn || !annualBtn) return;

    var starterPrice = document.getElementById('price-starter');
    var starterPeriod = document.getElementById('period-starter');
    var proPrice = document.getElementById('price-pro');
    var proPeriod = document.getElementById('period-pro');

    function setBilling(isAnnual) {
      if (isAnnual) {
        annualBtn.classList.add('active');
        monthlyBtn.classList.remove('active');
        if (starterPrice) starterPrice.textContent = '₦200,000';
        if (starterPeriod) starterPeriod.textContent = '/ year (2 Months Free)';
        if (proPrice) proPrice.textContent = '₦350,000';
        if (proPeriod) proPeriod.textContent = '/ year (2 Months Free)';
      } else {
        monthlyBtn.classList.add('active');
        annualBtn.classList.remove('active');
        if (starterPrice) starterPrice.textContent = '₦20,000';
        if (starterPeriod) starterPeriod.textContent = '/ month';
        if (proPrice) proPrice.textContent = '₦35,000';
        if (proPeriod) proPeriod.textContent = '/ month';
      }
    }

    monthlyBtn.addEventListener('click', function () { setBilling(false); });
    annualBtn.addEventListener('click', function () { setBilling(true); });
  }

  // 3. Interactive ROI & Time Saved Calculator
  function initRoiCalculator() {
    var slider = document.getElementById('roi-units-slider');
    var unitsDisplay = document.getElementById('roi-units-count');
    var hoursSaved = document.getElementById('roi-hours-saved');
    var revenueRecovered = document.getElementById('roi-revenue-recovered');
    var collectionRate = document.getElementById('roi-collection-rate');

    if (!slider || !unitsDisplay) return;

    function calculate() {
      var units = parseInt(slider.value, 10) || 50;
      unitsDisplay.textContent = units + ' Units';

      // Realistic calculations:
      // ~45 mins manual work saved per unit/month
      var hours = Math.round(units * 0.75);
      // ~₦18,000 average uncollected service charge recovered per delinquent unit
      var recovered = units * 18500;

      if (hoursSaved) hoursSaved.textContent = hours + ' hrs/mo';
      if (revenueRecovered) revenueRecovered.textContent = '₦' + recovered.toLocaleString('en-US');
      if (collectionRate) collectionRate.textContent = '99.4%';
    }

    slider.addEventListener('input', calculate);
    calculate();
  }

  // 4. FAQ Accordion
  function initFaq() {
    var faqItems = document.querySelectorAll('.faq-item');
    faqItems.forEach(function (item) {
      var question = item.querySelector('.faq-question');
      if (!question) return;
      question.addEventListener('click', function () {
        var isActive = item.classList.contains('active');
        faqItems.forEach(function (other) { other.classList.remove('active'); });
        if (!isActive) item.classList.add('active');
      });
    });
  }

  // 5. Smooth Scroll for Hash Links
  function initSmoothScroll() {
    document.querySelectorAll('a[href^="#"]').forEach(function (anchor) {
      anchor.addEventListener('click', function (e) {
        var href = this.getAttribute('href');
        if (href === '#' || !href) return;
        var target = document.querySelector(href);
        if (target) {
          e.preventDefault();
          var navHeight = (document.getElementById('nav') || {}).offsetHeight || 70;
          var targetPosition = target.offsetTop - navHeight - 20;
          window.scrollTo({
            top: targetPosition,
            behavior: 'smooth'
          });
        }
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    initNav();
    initPricingToggle();
    initRoiCalculator();
    initFaq();
    initSmoothScroll();
  });
})();
