/**
 * GA4 event tracking for client galleries.
 *
 * Fires through the page's own gtag() (loaded site-wide via Site Kit),
 * falling back to a dataLayer push for GTM — same site-agnostic pattern
 * as the Forminator bridge tag. No IDs are hardcoded here.
 *
 * Events:
 *   gallery_image_view    - an image shown full-size in the lightbox
 *                           (open + every next/prev/swipe/keyboard nav)
 *   gallery_image_download- single image download click
 *   gallery_zip_download  - "Download all as ZIP" click
 *
 * All events carry gallery_slug + gallery_id; image events carry image_file.
 */
(function () {
    'use strict';

    var settings = window.cgmAnalyticsSettings || {};
    var gallerySlug = settings.gallerySlug || '';
    var galleryId = settings.galleryId || 0;

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
                track('gallery_image_view', { image_file: fileFromUrl(src) });
            }).observe(lightboxImage, { attributes: true, attributeFilter: ['src'] });
        }

        // --- Downloads: delegate clicks, split single vs ZIP by the href.
        document.addEventListener('click', function (event) {
            var link = event.target && event.target.closest
                ? event.target.closest('a.cgm-download-trigger')
                : null;

            if (!link) {
                return;
            }

            var href = link.getAttribute('href') || '';

            // Locked state links to '#' (password modal intercepts) — the
            // unlocked click that follows will be counted instead.
            if (href === '#' || href === '') {
                return;
            }

            if (href.indexOf('cgm_download_all') !== -1) {
                track('gallery_zip_download', {});
            } else if (href.indexOf('cgm_download') !== -1) {
                track('gallery_image_download', { image_file: fileFromUrl(href) });
            }
        });
    });
})();
