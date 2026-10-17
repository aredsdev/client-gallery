/**
 * GA4 event tracking for client galleries.
 *
 * Fires through the page's own gtag() (loaded site-wide via Site Kit),
 * falling back to a dataLayer push for GTM — same site-agnostic pattern
 * as the Forminator bridge tag. No IDs are hardcoded here.
 *
 * Events (all carry gallery_slug + gallery_id; image events also carry
 * image_file, image_index, total_images so view-depth is visible):
 *   gallery_image_view     - an image shown full-size in the lightbox
 *                            (open + every next/prev/swipe/keyboard nav)
 *   gallery_image_download - single image download
 *   gallery_zip_download   - "Download all as ZIP"
 *   gallery_unlock         - correct password entered {unlock_scope: view|download}
 *   gallery_unlock_failed  - wrong password {unlock_scope: view|download}
 *   gallery_share          - share button used {share_method: native|copy_link|facebook}
 */
(function () {
    'use strict';

    var settings = window.cgmAnalyticsSettings || {};
    var gallerySlug = settings.gallerySlug || '';
    var galleryId = settings.galleryId || 0;

    var VIEW_UNLOCK_FLAG = 'cgm_unlock_view_' + galleryId;

    function track(name, params) {
        params = params || {};
        params.gallery_slug = gallerySlug;
        params.gallery_id = galleryId;

        if (typeof window.gtag === 'function') {
            window.gtag('event', name, params);
        } else if (window.dataLayer && typeof window.dataLayer.push === 'function') {
            params.event = name;
            window.dataLayer.push(params);
        }
    }

    function fileFromUrl(url) {
        try {
            var u = new URL(url, window.location.href);
            return u.searchParams.get('file') || '';
        } catch (e) {
            return '';
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var grid = document.querySelector('.cgm-grid');
        var totalImages = grid ? parseInt(grid.getAttribute('data-cgm-total') || '0', 10) : 0;

        // Map thumb URL -> 1-based position, for view-depth on image events.
        var indexByThumb = {};
        var triggers = document.querySelectorAll('.cgm-lightbox-trigger');
        for (var i = 0; i < triggers.length; i++) {
            var t = triggers[i].getAttribute('data-thumb') || triggers[i].getAttribute('href') || '';
            if (t) {
                indexByThumb[t] = i + 1;
            }
        }

        function imageParams(url) {
            var params = { image_file: fileFromUrl(url) };
            if (indexByThumb[url]) {
                params.image_index = indexByThumb[url];
            }
            if (totalImages) {
                params.total_images = totalImages;
            }
            return params;
        }

        // --- View-password unlock outcome. The submit handler below flags
        // the attempt in sessionStorage; the resulting page load either
        // shows the grid (success) or the form again (wrong password).
        try {
            if (sessionStorage.getItem(VIEW_UNLOCK_FLAG)) {
                sessionStorage.removeItem(VIEW_UNLOCK_FLAG);
                if (grid) {
                    track('gallery_unlock', { unlock_scope: 'view' });
                } else if (document.querySelector('.cgm-gallery-password-wrap')) {
                    track('gallery_unlock_failed', { unlock_scope: 'view' });
                }
            }
        } catch (e) {
            // sessionStorage unavailable (rare) — skip unlock tracking.
        }

        var viewForm = document.querySelector('.cgm-gallery-password-wrap form');
        if (viewForm) {
            viewForm.addEventListener('submit', function () {
                try {
                    sessionStorage.setItem(VIEW_UNLOCK_FLAG, '1');
                } catch (e) {}
            });
        }

        // --- Download-password unlock. Fire at submit time with beacon
        // transport and credit the intended download too (the reloaded page
        // starts it by navigation, not a click, so it isn't counted twice);
        // a wrong password re-renders the page with the error attribute,
        // which nets out as unlock_failed.
        var dlModal = document.getElementById('cgm-download-password-modal');

        if (dlModal && dlModal.getAttribute('data-cgm-download-error') === '1') {
            track('gallery_unlock_failed', { unlock_scope: 'download' });
        }

        var dlForm = dlModal ? dlModal.querySelector('form') : null;
        if (dlForm) {
            dlForm.addEventListener('submit', function () {
                track('gallery_unlock', { unlock_scope: 'download', transport_type: 'beacon' });

                var intended = document.getElementById('cgm_download_redirect');
                var url = intended ? intended.value : '';
                if (url.indexOf('cgm_download_all') !== -1) {
                    track('gallery_zip_download', { via_unlock: 1, transport_type: 'beacon' });
                } else if (url.indexOf('cgm_download') !== -1) {
                    var p = imageParams(url);
                    p.via_unlock = 1;
                    p.transport_type = 'beacon';
                    track('gallery_image_download', p);
                }
            });
        }

        // --- Image views: watch the lightbox image's src. One observer
        // covers open, arrows, keyboard, and swipe navigation alike.
        var lightboxImage = document.getElementById('cgm-lightbox-image');
        var lastTrackedSrc = '';

        if (lightboxImage && 'MutationObserver' in window) {
            new MutationObserver(function () {
                var src = lightboxImage.getAttribute('src') || '';
                if (!src || src === lastTrackedSrc) {
                    return;
                }
                lastTrackedSrc = src;
                track('gallery_image_view', imageParams(src));
            }).observe(lightboxImage, { attributes: true, attributeFilter: ['src'] });
        }

        // --- Clicks: downloads (direct, unlocked state) + share buttons.
        document.addEventListener('click', function (event) {
            if (!event.target || !event.target.closest) {
                return;
            }

            var share = event.target.closest('.cgm-share-trigger, .cgm-copy-link, .cgm-share-facebook');
            if (share) {
                var method = share.classList.contains('cgm-share-facebook') ? 'facebook'
                    : share.classList.contains('cgm-copy-link') ? 'copy_link'
                    : 'native';
                track('gallery_share', { share_method: method });
                return;
            }

            var link = event.target.closest('a.cgm-download-trigger');
            if (!link) {
                return;
            }

            var href = link.getAttribute('href') || '';

            // Locked state links to '#' (password modal intercepts) — that
            // flow is counted by the modal submit handler above instead.
            if (href === '#' || href === '') {
                return;
            }

            if (href.indexOf('cgm_download_all') !== -1) {
                track('gallery_zip_download', totalImages ? { total_images: totalImages } : {});
            } else if (href.indexOf('cgm_download') !== -1) {
                track('gallery_image_download', imageParams(href));
            }
        });
    });
})();
