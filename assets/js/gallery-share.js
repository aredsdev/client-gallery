/**
 * CGM Share UX
 *
 * Normal browsers:
 * - Show "Share" (native share sheet if available; else copy)
 *
 * Meta in-app browsers (FB/IG/Messenger):
 * - Hide "Share"
 * - Show "Share to Facebook" + "Copy link"
 */

(function () {

  function ua() {
    return (navigator.userAgent || '').toLowerCase();
  }

  function isMetaWebview() {
    const u = ua();
    return (
      u.includes('fb_iab') ||
      u.includes('fban') ||
      u.includes('fbav') ||
      u.includes('messenger') ||
      u.includes('instagram')
    );
  }

  function show(el) {
    if (!el) return;
    el.hidden = false;
  }

  function hide(el) {
    if (!el) return;
    el.hidden = true;
  }

  async function nativeShare(url) {
    if (!window.isSecureContext) return false;
    if (!navigator.share) return false;

    try {
      await navigator.share({ title: document.title, url });
      return true;
    } catch (err) {
      if (err && err.name === 'AbortError') return true;
      return false;
    }
  }

  async function copyToClipboard(btn, url) {
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        await navigator.clipboard.writeText(url);

        const old = btn.textContent;
        btn.textContent = 'Copied';
        setTimeout(() => (btn.textContent = old), 1200);

        return true;
      }
    } catch (e) {}

    window.prompt('Copy this link:', url);
    return true;
  }

  function initVisibility() {
    const meta = isMetaWebview();

    document.querySelectorAll('.cgm-gallery-actions').forEach(function (wrap) {
      const btnShare = wrap.querySelector('.cgm-share-trigger');
      const aFacebook = wrap.querySelector('.cgm-share-facebook');
      const btnCopy = wrap.querySelector('.cgm-copy-link');

      if (meta) {
        // Meta webview: prefer explicit FB + Copy
        hide(btnShare);
        show(aFacebook);
        show(btnCopy);
      } else {
        // Normal browsers
        show(btnShare);
        hide(aFacebook);
        hide(btnCopy);
      }
    });
  }

  document.addEventListener('click', function (e) {

    // Generic Share button
    const btnShare = e.target.closest('.cgm-share-trigger');
    if (btnShare) {
      const url = btnShare.getAttribute('data-cgm-share-url');
      if (!url) return;

      nativeShare(url).then(function (ok) {
        if (ok) return;
        copyToClipboard(btnShare, url);
      });

      return;
    }

    // Copy link button (Meta webview)
    const btnCopy = e.target.closest('.cgm-copy-link');
    if (btnCopy) {
      const url = btnCopy.getAttribute('data-cgm-share-url');
      if (!url) return;

      copyToClipboard(btnCopy, url);
      return;
    }

    // Share to Facebook is just a normal <a> link; no JS needed
  });

  // Run once on load
  initVisibility();

})();
