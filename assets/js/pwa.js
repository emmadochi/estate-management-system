/**
 * EstatePro PWA Registration and Smart Install Prompt
 */

(function () {
  'use strict';

  // 1. Register Service Worker
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      // Resolve path to root sw.js regardless of current page subfolder
      var swPath = window.location.pathname.includes('/pages/') ? '../../sw.js' : './sw.js';
      navigator.serviceWorker.register(swPath).then(
        function (reg) {
          console.log('[EstatePro PWA] Service Worker registered with scope:', reg.scope);
        },
        function (err) {
          console.log('[EstatePro PWA] Service Worker registration failed:', err);
        }
      );
    });
  }

  // 2. Smart Install Prompt Banner
  var deferredPrompt = null;
  var isDismissed = localStorage.getItem('estatepro_pwa_dismissed') === 'true';

  // Listen for beforeinstallprompt on Android / Chrome / Edge
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferredPrompt = e;

    if (!isDismissed) {
      showInstallBanner();
    }
  });

  function showInstallBanner() {
    if (document.getElementById('pwa-install-banner')) return;

    var banner = document.createElement('div');
    banner.id = 'pwa-install-banner';
    banner.style.position = 'fixed';
    banner.style.bottom = '20px';
    banner.style.left = '50%';
    banner.style.transform = 'translateX(-50%)';
    banner.style.zIndex = '99999';
    banner.style.width = 'calc(100% - 32px)';
    banner.style.maxWidth = '460px';
    banner.style.background = '#0f172a';
    banner.style.color = '#ffffff';
    banner.style.borderRadius = '16px';
    banner.style.boxShadow = '0 20px 40px rgba(0, 0, 0, 0.35)';
    banner.style.border = '1px solid rgba(255, 255, 255, 0.15)';
    banner.style.padding = '14px 18px';
    banner.style.display = 'flex';
    banner.style.alignItems = 'center';
    banner.style.justifyContent = 'space-between';
    banner.style.gap = '12px';
    banner.style.fontFamily = 'system-ui, -apple-system, sans-serif';
    banner.style.animation = 'pwaSlideUp 0.4s ease-out';

    banner.innerHTML = `
      <div style="display: flex; align-items: center; gap: 12px;">
        <div style="width: 42px; height: 42px; border-radius: 10px; background: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0; box-shadow: 0 4px 12px rgba(37,99,235,0.4);">
          📱
        </div>
        <div>
          <div style="font-weight: 700; font-size: 14px; color: #ffffff; line-height: 1.2;">Install EstatePro App</div>
          <div style="font-size: 12px; color: #94a3b8; line-height: 1.3; margin-top: 2px;">Add to Home Screen for 1-tap instant access</div>
        </div>
      </div>
      <div style="display: flex; align-items: center; gap: 8px; flex-shrink: 0;">
        <button id="pwa-install-btn" style="background: #2563eb; color: #fff; border: none; padding: 8px 14px; border-radius: 8px; font-weight: 700; font-size: 12px; cursor: pointer; transition: transform 0.2s;">
          Install
        </button>
        <button id="pwa-close-btn" style="background: transparent; color: #94a3b8; border: none; font-size: 18px; cursor: pointer; padding: 4px;">
          ✕
        </button>
      </div>
    `;

    document.body.appendChild(banner);

    // Style animation
    var style = document.createElement('style');
    style.innerHTML = '@keyframes pwaSlideUp { from { transform: translate(-50%, 60px); opacity: 0; } to { transform: translate(-50%, 0); opacity: 1; } }';
    document.head.appendChild(style);

    // Install Button Click
    document.getElementById('pwa-install-btn').addEventListener('click', function () {
      if (deferredPrompt) {
        deferredPrompt.prompt();
        deferredPrompt.userChoice.then(function (choiceResult) {
          if (choiceResult.outcome === 'accepted') {
            console.log('[EstatePro PWA] User accepted installation');
          }
          deferredPrompt = null;
          banner.remove();
        });
      }
    });

    // Close Button Click
    document.getElementById('pwa-close-btn').addEventListener('click', function () {
      banner.remove();
      localStorage.setItem('estatepro_pwa_dismissed', 'true');
    });
  }

  // Detect iOS Safari and show instructions if not in standalone mode
  var isIos = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
  var isStandalone = window.navigator.standalone === true;

  if (isIos && !isStandalone && !isDismissed) {
    // Show iOS tip after 4 seconds
    setTimeout(function () {
      if (document.getElementById('pwa-install-banner')) return;
      var iosBanner = document.createElement('div');
      iosBanner.id = 'pwa-install-banner';
      iosBanner.style.position = 'fixed';
      iosBanner.style.bottom = '20px';
      iosBanner.style.left = '50%';
      iosBanner.style.transform = 'translateX(-50%)';
      iosBanner.style.zIndex = '99999';
      iosBanner.style.width = 'calc(100% - 32px)';
      iosBanner.style.maxWidth = '420px';
      iosBanner.style.background = '#0f172a';
      iosBanner.style.color = '#ffffff';
      iosBanner.style.borderRadius = '16px';
      iosBanner.style.boxShadow = '0 20px 40px rgba(0, 0, 0, 0.35)';
      iosBanner.style.border = '1px solid rgba(255, 255, 255, 0.15)';
      iosBanner.style.padding = '14px 18px';
      iosBanner.style.display = 'flex';
      iosBanner.style.alignItems = 'center';
      iosBanner.style.justifyContent = 'space-between';
      iosBanner.style.fontFamily = 'system-ui, -apple-system, sans-serif';

      iosBanner.innerHTML = `
        <div style="display: flex; align-items: center; gap: 10px;">
          <span style="font-size: 20px;">📲</span>
          <div style="font-size: 12px; color: #e2e8f0;">
            Install on iPhone: tap <strong style="color: #38bdf8;">Share [↑]</strong> then select <strong style="color: #38bdf8;">'Add to Home Screen'</strong>.
          </div>
        </div>
        <button id="pwa-ios-close" style="background: transparent; color: #94a3b8; border: none; font-size: 18px; cursor: pointer; margin-left: 8px;">✕</button>
      `;

      document.body.appendChild(iosBanner);
      document.getElementById('pwa-ios-close').addEventListener('click', function () {
        iosBanner.remove();
        localStorage.setItem('estatepro_pwa_dismissed', 'true');
      });
    }, 4000);
  }
})();
