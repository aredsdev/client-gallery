(function () {
    function initCgmPasswordModal() {
        var modal = document.getElementById('cgm-download-password-modal');
        if (!modal) {
            return;
        }

        var closeBtn      = modal.querySelector('.cgm-password-modal-close');
        var passwordField = modal.querySelector('input[name="cgm_download_password"]');
        var triggers      = document.querySelectorAll('.cgm-download-trigger');
        var galleryId     = modal.dataset.cgmGalleryId || '';
        var STORAGE_KEY   = 'cgmPendingDownload';

        // The modal gets its own history entry, so the phone/browser Back
        // button closes it instead of leaving the gallery.
        var historyPushed = false;

        function openModal() {
            modal.removeAttribute('hidden');
            modal.classList.add('is-visible');
            document.body.classList.add('cgm-password-modal-open');

            if (!historyPushed) {
                try {
                    history.pushState({ cgmPasswordModal: 1 }, '');
                    historyPushed = true;
                } catch (e) {}
            }

            if (passwordField) {
                setTimeout(function () {
                    passwordField.focus();
                    passwordField.select();
                }, 10);
            }
        }

        function hideModal() {
            modal.classList.remove('is-visible');
            document.body.classList.remove('cgm-password-modal-open');
            modal.setAttribute('hidden', 'hidden');

            // User backed out, forget pending download.
            try {
                sessionStorage.removeItem(STORAGE_KEY);
            } catch (e) {}
        }

        // Closed by X / Esc / outside click: also drop the modal's history
        // entry, so Back afterwards behaves normally.
        function closeModal() {
            hideModal();
            if (historyPushed) {
                historyPushed = false;
                history.back();
            }
        }

        // Back button while open: the entry is already popped, just hide.
        window.addEventListener('popstate', function () {
            if (historyPushed) {
                historyPushed = false;
                hideModal();
            }
        });

        function getPasswordState() {
            var settings = window.cgmPasswordSettings || {};

            // Robustly interpret true/false from various forms (bool, 1, '1', 0, '0').
            var hasRequiresSetting = typeof settings.requiresPassword !== 'undefined';
            var hasUnlockedSetting = typeof settings.downloadUnlocked !== 'undefined';

            var requiresFromSettings =
                settings.requiresPassword === true ||
                settings.requiresPassword === 1 ||
                settings.requiresPassword === '1';

            var unlockedFromSettings =
                settings.downloadUnlocked === true ||
                settings.downloadUnlocked === 1 ||
                settings.downloadUnlocked === '1';

            var requiresFromData =
                modal.dataset.cgmRequiresPassword === '1';

            var unlockedFromData =
                modal.dataset.cgmDownloadUnlocked === '1';

            var requires = hasRequiresSetting ? requiresFromSettings : requiresFromData;
            var unlocked = hasUnlockedSetting ? unlockedFromSettings : unlockedFromData;

            return {
                requiresPassword: !!requires,
                downloadUnlocked: !!unlocked
            };
        }

        // Always bind to download triggers.
        if (triggers.length) {
            triggers.forEach(function (el) {
                el.addEventListener('click', function (e) {
                    var state = getPasswordState();

                    // If no password required, or already unlocked, let the link behave normally.
                    if (!state.requiresPassword || state.downloadUnlocked) {
                        return;
                    }

                    // Otherwise intercept:
                    // 1) remember what we were trying to download,
                    // 2) open the modal.
                    // Prefer an explicit intended download URL when available (lightbox sets data-cgm-download-url).
                    var intendedHref =
                        el.getAttribute('data-cgm-download-url') ||
                        el.dataset.cgmDownloadUrl || // for safety (same thing)
                        el.getAttribute('href') ||
                        '';

                    // If this is the locked "Download all" button, build the correct ZIP download URL.
                    if ((!intendedHref || intendedHref === '#') && el.hasAttribute('data-cgm-download-all')) {
                        var settings = window.cgmPasswordSettings || {};
                        var adminPostUrl = settings.adminPostUrl || '/wp-admin/admin-post.php';

                        intendedHref =
                            adminPostUrl +
                            '?action=cgm_download_all&gallery_id=' +
                            encodeURIComponent(galleryId);
                    }

                    if (galleryId && intendedHref) {
                        try {
                            sessionStorage.setItem(
                                STORAGE_KEY,
                                JSON.stringify({
                                    galleryId: galleryId,
                                    href: intendedHref
                                })
                            );
                        } catch (err) {
                            // ignore storage errors
                        }
                    }

                    // Also write it into the hidden redirect field so the PHP handler can redirect immediately.
                    var redirectInput = document.getElementById('cgm_download_redirect');
                    if (redirectInput) {
                        redirectInput.value = intendedHref;
                    }


                    e.preventDefault();
                    openModal();
                });
            });
        }

        // Close button
        if (closeBtn) {
            closeBtn.addEventListener('click', function (e) {
                e.preventDefault();
                closeModal();
            });
        }

        // Click outside the dialog to close
        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                closeModal();
            }
        });

        // ESC to close
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('is-visible')) {
                closeModal();
            }
        });

        // If PHP flagged a password error, auto-open modal with message visible.
        if (modal.dataset.cgmDownloadError === '1') {
            openModal();
        }

        // Clear any pending download so it can't fire twice.
        try {
            sessionStorage.removeItem(STORAGE_KEY);
        } catch (e) {}

        // Just unlocked: PHP sent us back here (unlocked) with the intended
        // download, so confirm it and start it from this page.
        var startUrl = modal.dataset.cgmStartDownload;
        if (startUrl) {
            var toast = document.createElement('div');
            toast.className = 'cgm-toast';
            toast.setAttribute('role', 'status');
            toast.textContent = 'Downloads unlocked. Your download is starting…';
            document.body.appendChild(toast);
            void toast.offsetWidth;
            toast.classList.add('is-visible');
            setTimeout(function () {
                toast.classList.remove('is-visible');
            }, 6000);

            // Drop the param so a refresh doesn't download again.
            try {
                var clean = new URL(window.location.href);
                clean.searchParams.delete('cgm_dl_next');
                history.replaceState(null, '', clean.toString());
            } catch (e) {}

            setTimeout(function () {
                window.location.href = startUrl;
            }, 400);
        }

    }

    // Run init immediately if DOM is ready, otherwise on DOMContentLoaded.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCgmPasswordModal);
    } else {
        initCgmPasswordModal();
    }
})();
