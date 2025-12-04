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
        const trigger     = triggers[currentIndex];
        const thumbUrl    = trigger.dataset.thumb || trigger.href;
        const downloadUrl = trigger.dataset.download || trigger.href;

        if (thumbUrl) {
            imageEl.src = thumbUrl;
        } else {
            imageEl.removeAttribute('src');
        }

        if (downloadUrl) {
            downloadEl.href = downloadUrl;
            downloadEl.removeAttribute('aria-disabled');
        } else {
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

