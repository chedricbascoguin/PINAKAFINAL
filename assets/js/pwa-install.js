(function () {
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('sw.js').catch(function (error) {
        console.error('Service worker registration failed:', error);
      });
    });
  }

  var deferredInstallPrompt = null;
  window.addEventListener('beforeinstallprompt', function (event) {
    event.preventDefault();
    deferredInstallPrompt = event;
    document.querySelectorAll('[data-pwa-install]').forEach(function (button) {
      button.hidden = false;
      button.disabled = false;
    });
  });

  window.installResihunityApp = function () {
    if (!deferredInstallPrompt) return;
    deferredInstallPrompt.prompt();
    deferredInstallPrompt.userChoice.finally(function () {
      deferredInstallPrompt = null;
      document.querySelectorAll('[data-pwa-install]').forEach(function (button) {
        button.hidden = true;
      });
    });
  };
})();
