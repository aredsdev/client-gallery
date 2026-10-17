document.addEventListener('DOMContentLoaded', function () {
    const overlay = document.getElementById('cgm-lightbox');
    if (!overlay) return;

    const imageEl    = document.getElementById('cgm-lightbox-image');
    const downloadEl = document.getElementById('cgm-lightbox-download');
    const closeBtn   = document.getElementById('cgm-lightbox-close');
    const prevBtn    = document.getElementById('cgm-lightbox-prev');
    const nextBtn    = document.getElementById('cgm-lightbox-next');

    const triggers = Array.from(document.querySelectorAll('.cgm-lightbox-trigger'));
    if (!triggers.length) return;

    // iOS (Safari, Chrome, Meta in-app): an attachment download always lands in the
    // Files app. Photos is only offered for a real image shown inline, so on iOS the
    // single-image download URL opens a page with the photo + "press and hold" hint.
    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) ||
        (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

    if (isIOS && downloadEl) {
        downloadEl.removeAttribute('download'); // Safari would force a save to Files
    }

    function forPlatform(url) {
        if (isIOS && url && /action=cgm_download(?!_all)/.test(url) && url.indexOf('inline=1') === -1) {
            return url + '&inline=1';
        }
        return url;
    }

    let currentIndex = -1;
    let isAnimating  = false;

    function deriveDownloadUrlFromThumbUrl(url) {
        try {
            const u = new URL(url, window.location.href);

            // Your thumbs use ?cgm_thumb=1, not action=cgm_thumb
            if (u.searchParams.get('cgm_thumb') === '1') {

                const galleryId = u.searchParams.get('gallery_id');
                const file      = u.searchParams.get('file');

                if (!galleryId || !file) {
                    return '';
                }

                const adminPostUrl =
                    (window.cgmPasswordSettings && window.cgmPasswordSettings.adminPostUrl)
                    ? window.cgmPasswordSettings.adminPostUrl
                    : '/wp-admin/admin-post.php';

                return (
                    adminPostUrl +
                    '?action=cgm_download' +
                    '&gallery_id=' + encodeURIComponent(galleryId) +
                    '&file=' + encodeURIComponent(file)
                );
            }

        } catch (e) {}

        return '';
    }

    // Applies src + download state for the given (already-normalised) index.
    // Does NOT touch currentIndex — caller is responsible.
    function applyImageForIndex(index) {
        const trigger  = triggers[index];
        const thumbUrl = trigger.dataset.thumb || trigger.href;

        if (thumbUrl) {
            imageEl.src = thumbUrl;
        } else {
            imageEl.removeAttribute('src');
        }

        // Prefer real download URL when it exists (unlocked), otherwise derive it from thumb URL.
        let downloadUrl = trigger.dataset.download || '';
        if (!downloadUrl && thumbUrl) {
            downloadUrl = deriveDownloadUrlFromThumbUrl(thumbUrl);
        }

        // Check download lock state (set by your PHP data attributes)
        const modal      = document.getElementById('cgm-download-password-modal');
        const requiresPw = modal && modal.dataset.cgmRequiresPassword === '1';
        const unlocked   = modal && modal.dataset.cgmDownloadUnlocked === '1';

        downloadUrl = forPlatform(downloadUrl);

        if (downloadUrl) {
            if (requiresPw && !unlocked) {
                // Locked: store intended URL for password modal, but don't navigate
                downloadEl.dataset.cgmDownloadUrl = downloadUrl;
                downloadEl.setAttribute('data-cgm-download-url', downloadUrl); // for password-modal.js
                downloadEl.href = '#';
                downloadEl.removeAttribute('aria-disabled');
            } else {
                // Unlocked: allow direct download
                downloadEl.dataset.cgmDownloadUrl = '';
                downloadEl.removeAttribute('data-cgm-download-url');
                downloadEl.href = downloadUrl;
                downloadEl.removeAttribute('aria-disabled');
            }
        } else {
            downloadEl.dataset.cgmDownloadUrl = '';
            downloadEl.removeAttribute('data-cgm-download-url');
            downloadEl.removeAttribute('href');
            downloadEl.setAttribute('aria-disabled', 'true');
        }
    }

    function showImageForIndex(index, direction) {
        const count = triggers.length;
        if (!count) return;

        // Wrap around
        if (index < 0) {
            index = count - 1;
        } else if (index >= count) {
            index = 0;
        }

        // No direction = instant update (first open)
        if (!direction) {
            currentIndex = index;
            applyImageForIndex(index);
            return;
        }

        // Guard against rapid clicks during animation
        if (isAnimating) return;
        isAnimating = true;

        const outClass = direction === 'next' ? 'cgm-slide-out-left'  : 'cgm-slide-out-right';
        const inClass  = direction === 'next' ? 'cgm-slide-in-right'  : 'cgm-slide-in-left';

        imageEl.classList.add(outClass);

        imageEl.addEventListener('animationend', function onOut() {
            imageEl.removeEventListener('animationend', onOut);
            // Keep outClass (forwards fill = opacity:0) until new image is ready.

            currentIndex = index;
            applyImageForIndex(index); // sets imageEl.src

            function startInAnimation() {
                // Atomically swap: remove the out-hold, start the in-animation.
                imageEl.classList.remove(outClass);
                imageEl.classList.add(inClass);
                imageEl.addEventListener('animationend', function onIn() {
                    imageEl.removeEventListener('animationend', onIn);
                    imageEl.classList.remove(inClass);
                    isAnimating = false;
                });
            }

            // Wait for the new image to be decoded before sliding in.
            // If already cached, imageEl.complete + naturalWidth > 0 is true immediately.
            if (imageEl.complete && imageEl.naturalWidth > 0) {
                startInAnimation();
            } else {
                function onLoaded() {
                    imageEl.removeEventListener('load',  onLoaded);
                    imageEl.removeEventListener('error', onLoaded);
                    startInAnimation();
                }
                imageEl.addEventListener('load',  onLoaded);
                imageEl.addEventListener('error', onLoaded); // slide in even on error
            }
        });
    }

    function openLightboxAt(index) {
        showImageForIndex(index); // no direction — instant, no animation on first open
        overlay.removeAttribute('hidden');
        overlay.classList.add('is-visible');
        document.body.classList.add('cgm-lightbox-open');
    }

    function closeLightbox() {
        overlay.classList.remove('is-visible');
        overlay.setAttribute('hidden', 'hidden');
        document.body.classList.remove('cgm-lightbox-open');
        // Clean up any in-flight animation state
        imageEl.classList.remove(
            'cgm-slide-out-left', 'cgm-slide-out-right',
            'cgm-slide-in-right', 'cgm-slide-in-left'
        );
        isAnimating = false;
        imageEl.src = '';
        currentIndex = -1;
    }

    // Bind triggers
    triggers.forEach(function (trigger, index) {
        trigger.dataset.cgmIndex = index;
        trigger.addEventListener('click', function (e) {
            e.preventDefault();
            openLightboxAt(index);
        });
    });

    // Close button
    if (closeBtn) {
        closeBtn.addEventListener('click', function (e) {
            e.preventDefault();
            closeLightbox();
        });
    }

    // Click outside inner content to close
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) {
            closeLightbox();
        }
    });

    // Prev/next buttons
    if (prevBtn) {
        prevBtn.addEventListener('click', function (e) {
            e.preventDefault();
            if (currentIndex === -1) {
                openLightboxAt(0);
            } else {
                showImageForIndex(currentIndex - 1, 'prev');
            }
        });
    }

    if (nextBtn) {
        nextBtn.addEventListener('click', function (e) {
            e.preventDefault();
            if (currentIndex === -1) {
                openLightboxAt(0);
            } else {
                showImageForIndex(currentIndex + 1, 'next');
            }
        });
    }

    // Touch swipe: left = next, right = prev
    var touchStartX = 0;
    var touchStartY = 0;
    overlay.addEventListener('touchstart', function (e) {
        touchStartX = e.changedTouches[0].clientX;
        touchStartY = e.changedTouches[0].clientY;
    }, { passive: true });
    overlay.addEventListener('touchend', function (e) {
        if (!overlay.classList.contains('is-visible')) return;
        var dx = e.changedTouches[0].clientX - touchStartX;
        var dy = e.changedTouches[0].clientY - touchStartY;
        if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) {
            var dir = dx < 0 ? 'next' : 'prev';
            showImageForIndex(currentIndex + (dx < 0 ? 1 : -1), dir);
        }
    }, { passive: true });

    // Keyboard controls: ESC, Left, Right
    document.addEventListener('keydown', function (e) {
        if (!overlay.classList.contains('is-visible')) return;

        if (e.key === 'Escape') {
            e.preventDefault();
            closeLightbox();
        } else if (e.key === 'ArrowLeft') {
            e.preventDefault();
            if (currentIndex === -1) {
                openLightboxAt(0);
            } else {
                showImageForIndex(currentIndex - 1, 'prev');
            }
        } else if (e.key === 'ArrowRight') {
            e.preventDefault();
            if (currentIndex === -1) {
                openLightboxAt(0);
            } else {
                showImageForIndex(currentIndex + 1, 'next');
            }
        }
    });
});

// Long-press / right-click on a gallery image: block the native save menu and
// point people at the Download button instead (that serves the original file;
// what's on screen is only the watermarked preview).
(function () {
    var HOLD_MS = 450;
    var MOVE_PX = 10;
    var SELECTOR = '.cgm-grid, .cgm-lightbox-image-wrap';
    var timer = null;
    var startX = 0;
    var startY = 0;
    var toastEl = null;
    var toastTimer = null;

    function showToast() {
        if (!toastEl) {
            toastEl = document.createElement('div');
            toastEl.className = 'cgm-toast';
            toastEl.setAttribute('role', 'status');
            toastEl.textContent = 'To save this photo, use the Download button.';
            document.body.appendChild(toastEl);
        }
        // Restart the fade if it's already showing.
        toastEl.classList.remove('is-visible');
        void toastEl.offsetWidth;
        toastEl.classList.add('is-visible');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () {
            toastEl.classList.remove('is-visible');
        }, 3000);
    }

    function cancel() {
        clearTimeout(timer);
        timer = null;
    }

    document.addEventListener('touchstart', function (e) {
        cancel();
        if (e.touches.length !== 1 || !e.target.closest || !e.target.closest(SELECTOR)) return;
        startX = e.touches[0].clientX;
        startY = e.touches[0].clientY;
        timer = setTimeout(showToast, HOLD_MS);
    }, { passive: true });

    document.addEventListener('touchmove', function (e) {
        if (!timer) return;
        var t = e.touches[0];
        if (Math.abs(t.clientX - startX) > MOVE_PX || Math.abs(t.clientY - startY) > MOVE_PX) {
            cancel();
        }
    }, { passive: true });

    document.addEventListener('touchend', cancel, { passive: true });
    document.addEventListener('touchcancel', cancel, { passive: true });

    // Android Chrome / desktop fire contextmenu on long-press / right-click.
    document.addEventListener('contextmenu', function (e) {
        if (e.target.closest && e.target.closest(SELECTOR)) {
            e.preventDefault();
            showToast();
        }
    });
})();
