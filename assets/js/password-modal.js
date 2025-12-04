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

        function openModal() {
            modal.removeAttribute('hidden');
            modal.classList.add('is-visible');
            document.body.classList.add('cgm-password-modal-open');

            if (passwordField) {
                setTimeout(function () {
                    passwordField.focus();
                    passwordField.select();
                }, 10);
            }
        }

        function closeModal() {
            modal.classList.remove('is-visible');
            document.body.classList.remove('cgm-password-modal-open');
            modal.setAttribute('hidden', 'hidden');

            // User backed out, forget pending download.
            try {
                sessionStorage.removeItem(STORAGE_KEY);
            } catch (e) {}
        }

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
                    var href = el.getAttribute('href') || '';
                    if (galleryId && href) {
                        try {
                            sessionStorage.setItem(
                                STORAGE_KEY,
                                JSON.stringify({
                                    galleryId: galleryId,
                                    href: href
                                })
                            );
                        } catch (err) {
                            // ignore storage errors
                        }
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

        // After a successful unlock + redirect, auto-run the pending download once.
        try {
            var raw = sessionStorage.getItem(STORAGE_KEY);
            if (raw && galleryId) {
                var data = JSON.parse(raw);
                var state = getPasswordState();

                if (
                    data &&
                    data.galleryId === galleryId &&
                    state.requiresPassword &&
                    state.downloadUnlocked
                ) {
                    // Clear before navigating so it doesn't repeat.
                    sessionStorage.removeItem(STORAGE_KEY);
                    window.location.href = data.href;
                }
            }
        } catch (e) {
            // ignore JSON / storage errors
        }
    }

    // Run init immediately if DOM is ready, otherwise on DOMContentLoaded.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCgmPasswordModal);
    } else {
        initCgmPasswordModal();
    }
})();
