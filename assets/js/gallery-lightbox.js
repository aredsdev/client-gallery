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

    let currentIndex = -1;

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


    function showImageForIndex(index) {
        const count = triggers.length;
        if (!count) return;

        // Wrap around
        if (index < 0) {
            index = count - 1;
        } else if (index >= count) {
            index = 0;
        }

        currentIndex = index;
        const trigger = triggers[currentIndex];
        const thumbUrl = trigger.dataset.thumb || trigger.href;

        // Always show the image from the thumb URL
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
        const modal = document.getElementById('cgm-download-password-modal');
        const requiresPw = modal && modal.dataset.cgmRequiresPassword === '1';
        const unlocked = modal && modal.dataset.cgmDownloadUnlocked === '1';

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

    function openLightboxAt(index) {
        showImageForIndex(index);
        overlay.removeAttribute('hidden');
        overlay.classList.add('is-visible');
        document.body.classList.add('cgm-lightbox-open');
    }

    function closeLightbox() {
        overlay.classList.remove('is-visible');
        overlay.setAttribute('hidden', 'hidden');
        document.body.classList.remove('cgm-lightbox-open');
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
                showImageForIndex(currentIndex - 1);
            }
        });
    }

    if (nextBtn) {
        nextBtn.addEventListener('click', function (e) {
            e.preventDefault();
            if (currentIndex === -1) {
                openLightboxAt(0);
            } else {
                showImageForIndex(currentIndex + 1);
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
            showImageForIndex(currentIndex + (dx < 0 ? 1 : -1));
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
                showImageForIndex(currentIndex - 1);
            }
        } else if (e.key === 'ArrowRight') {
            e.preventDefault();
            if (currentIndex === -1) {
                openLightboxAt(0);
            } else {
                showImageForIndex(currentIndex + 1);
            }
        }
    });
});

// Soft-block context menu on gallery + lightbox images
document.addEventListener('contextmenu', function (e) {
    if (e.target.closest('.cgm-grid, .cgm-lightbox-image-wrap')) {
        e.preventDefault();
    }
});

