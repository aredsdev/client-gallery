<?php
/**
 * Gallery access control: visibility + password protection.
 *
 * - Public vs password-protected viewing (no WP users)
 * - Separate password for downloads
 * - Passwords stored as hashes using wp_hash_password()
 * - Access remembered per-gallery via signed cookies
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CGM_Gallery_Access {

    // Meta keys
    const META_VISIBILITY          = '_cgm_visibility';
    const META_VIEW_PASS_HASH      = '_cgm_view_password_hash';
    const META_DOWNLOAD_PASS_HASH  = '_cgm_download_password_hash';

    // Visibility options (for viewing only)
    const VIS_PUBLIC   = 'public';
    const VIS_PASSWORD = 'password';

    /**
     * Bootstraps hooks.
     */
    public static function init() {

        // Admin meta box for visibility + passwords.
        add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_box' ] );
        add_action( 'save_post_client_gallery', [ __CLASS__, 'save_meta' ] );
    }

    /* ---------------------------------------------------------------------
     * Admin: meta box
     * ------------------------------------------------------------------ */

    /**
     * Register meta box on client_gallery post type.
     */
    public static function add_meta_box() {
        add_meta_box(
            'cgm_gallery_access',
            __( 'Gallery Access', 'client-gallery' ),
            [ __CLASS__, 'render_meta_box' ],
            'client_gallery',
            'side',
            'default'
        );
    }

    /**
     * Meta box markup.
     *
     * @param WP_Post $post
     */
    public static function render_meta_box( $post ) {

        wp_nonce_field( 'cgm_gallery_access_meta', 'cgm_gallery_access_nonce' );

        $visibility = get_post_meta( $post->ID, self::META_VISIBILITY, true );
        if ( ! $visibility ) {
            $visibility = self::VIS_PUBLIC;
        }

        $has_view_password     = (bool) get_post_meta( $post->ID, self::META_VIEW_PASS_HASH, true );
        $has_download_password = (bool) get_post_meta( $post->ID, self::META_DOWNLOAD_PASS_HASH, true );
        ?>
        <p><strong><?php esc_html_e( 'Viewing', 'client-gallery' ); ?></strong></p>

        <p>
            <label>
                <input type="radio"
                       name="cgm_visibility"
                       value="<?php echo esc_attr( self::VIS_PUBLIC ); ?>"
                    <?php checked( $visibility, self::VIS_PUBLIC ); ?> />
                <?php esc_html_e( 'Public (no password to view)', 'client-gallery' ); ?>
            </label><br/>

            <label>
                <input type="radio"
                       name="cgm_visibility"
                       value="<?php echo esc_attr( self::VIS_PASSWORD ); ?>"
                    <?php checked( $visibility, self::VIS_PASSWORD ); ?> />
                <?php esc_html_e( 'Password-protected (viewing)', 'client-gallery' ); ?>
            </label>
        </p>

        <p>
            <label for="cgm_new_view_password">
                <strong><?php esc_html_e( 'New viewing password', 'client-gallery' ); ?></strong>
            </label><br/>
            <input type="password"
                   id="cgm_new_view_password"
                   name="cgm_new_view_password"
                   class="widefat"
                   autocomplete="new-password"
                   placeholder="<?php esc_attr_e( 'Leave blank to keep existing', 'client-gallery' ); ?>" />
        </p>

        <?php if ( $has_view_password ) : ?>
            <p class="description">
                <?php esc_html_e( 'A viewing password is currently set.', 'client-gallery' ); ?>
            </p>
        <?php endif; ?>

        <hr/>

        <p><strong><?php esc_html_e( 'Downloads', 'client-gallery' ); ?></strong></p>

        <p class="description">
            <?php esc_html_e( 'Optional separate password required to download images (single or ZIP). If no download password is set, downloads follow the viewing rules.', 'client-gallery' ); ?>
        </p>

        <p>
            <label for="cgm_new_download_password">
                <strong><?php esc_html_e( 'New download password', 'client-gallery' ); ?></strong>
            </label><br/>
            <input type="password"
                   id="cgm_new_download_password"
                   name="cgm_new_download_password"
                   class="widefat"
                   autocomplete="new-password"
                   placeholder="<?php esc_attr_e( 'Leave blank to keep existing', 'client-gallery' ); ?>" />
        </p>

        <?php if ( $has_download_password ) : ?>
            <p class="description">
                <?php esc_html_e( 'A download password is currently set.', 'client-gallery' ); ?>
            </p>
        <?php endif; ?>

        <p class="description">
            <?php esc_html_e( 'Passwords are stored securely using WordPress’s password hashing (not in plain text).', 'client-gallery' ); ?>
        </p>
        <?php
    }

    /**
     * Save meta: visibility + view password + download password.
     *
     * @param int $post_id
     */
    public static function save_meta( $post_id ) {

        // Nonce.
        if (
            ! isset( $_POST['cgm_gallery_access_nonce'] ) ||
            ! wp_verify_nonce( $_POST['cgm_gallery_access_nonce'], 'cgm_gallery_access_meta' )
        ) {
            return;
        }

        // Autosave?
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        // Permissions.
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Visibility (viewing).
        $visibility = isset( $_POST['cgm_visibility'] )
            ? sanitize_text_field( wp_unslash( $_POST['cgm_visibility'] ) )
            : self::VIS_PUBLIC;

        if ( ! in_array( $visibility, [ self::VIS_PUBLIC, self::VIS_PASSWORD ], true ) ) {
            $visibility = self::VIS_PUBLIC;
        }

        update_post_meta( $post_id, self::META_VISIBILITY, $visibility );

        // New viewing password: only hash if provided.
        if ( ! empty( $_POST['cgm_new_view_password'] ) ) {
            $plain = (string) wp_unslash( $_POST['cgm_new_view_password'] );
            $hash  = wp_hash_password( $plain ); // same hasher WP core uses

            update_post_meta( $post_id, self::META_VIEW_PASS_HASH, $hash );
        }

        // New download password: only hash if provided.
        if ( ! empty( $_POST['cgm_new_download_password'] ) ) {
            $plain = (string) wp_unslash( $_POST['cgm_new_download_password'] );
            $hash  = wp_hash_password( $plain );

            update_post_meta( $post_id, self::META_DOWNLOAD_PASS_HASH, $hash );
        }
    }

    /* ---------------------------------------------------------------------
     * Core helpers
     * ------------------------------------------------------------------ */

    /**
     * Does this gallery require a viewing password?
     *
     * @param int $post_id
     * @return bool
     */
    public static function requires_view_password( $post_id ) {
        $visibility = get_post_meta( $post_id, self::META_VISIBILITY, true );
        return ( $visibility === self::VIS_PASSWORD );
    }

    /**
    * SEO helper: treat password-protected galleries as "private".
    *
    * "Private" in CGM means: not indexable without unlocking viewing.
    */
    public static function gallery_is_private( $post_id ) {
        return self::requires_view_password( (int) $post_id );
    }

    /**
     * Does this gallery require a download password?
     *
     * @param int $post_id
     * @return bool
     */
    public static function requires_download_password( $post_id ) {
        $hash = get_post_meta( $post_id, self::META_DOWNLOAD_PASS_HASH, true );
        return ! empty( $hash );
    }

    /**
     * Build signed token for cookie.
     *
     * Ties the token to the gallery ID, password hash, and a scope ("view" or "download").
     *
     * @param int    $post_id
     * @param string $hash
     * @param string $scope
     *
     * @return string
     */
    protected static function build_token( $post_id, $hash, $scope ) {
        if ( empty( $hash ) ) {
            return '';
        }

        $data = $post_id . '|' . $hash . '|' . $scope;

        return hash_hmac(
            'sha256',
            $data,
            wp_salt( 'cgm_gallery_access' )
        );
    }

    /**
     * Has this visitor already unlocked viewing for this gallery?
     *
     * @param int $post_id
     * @return bool
     */
    protected static function is_view_unlocked( $post_id ) {

        if ( ! self::requires_view_password( $post_id ) ) {
            return true;
        }

        $stored_hash = get_post_meta( $post_id, self::META_VIEW_PASS_HASH, true );
        if ( empty( $stored_hash ) ) {
            // No hash set: fail open or closed; we'll fail open here.
            return true;
        }

        $cookie_name  = 'cgm_gallery_view_' . intval( $post_id );
        $cookie_value = isset( $_COOKIE[ $cookie_name ] )
            ? sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) )
            : '';

        if ( empty( $cookie_value ) ) {
            return false;
        }

        $expected = self::build_token( $post_id, $stored_hash, 'view' );

        return hash_equals( $expected, $cookie_value );
    }

    /**
     * Has this visitor already unlocked downloads for this gallery?
     *
     * @param int $post_id
     * @return bool
     */
    protected static function is_download_unlocked( $post_id ) {

        // If no separate download password, downloads follow viewing rules.
        if ( ! self::requires_download_password( $post_id ) ) {
            return self::is_view_unlocked( $post_id );
        }

        $stored_hash = get_post_meta( $post_id, self::META_DOWNLOAD_PASS_HASH, true );
        if ( empty( $stored_hash ) ) {
            return self::is_view_unlocked( $post_id );
        }

        $cookie_name  = 'cgm_gallery_dl_' . intval( $post_id );
        $cookie_value = isset( $_COOKIE[ $cookie_name ] )
            ? sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) )
            : '';

        if ( empty( $cookie_value ) ) {
            return false;
        }

        $expected = self::build_token( $post_id, $stored_hash, 'download' );

        return hash_equals( $expected, $cookie_value );
    }

    /**
     * Can this visitor view the gallery right now?
     *
     * @param int $post_id
     * @return bool
     */
    public static function user_can_view( $post_id ) {
        if ( ! self::requires_view_password( $post_id ) ) {
            return true;
        }

        return self::is_view_unlocked( $post_id );
    }

    /**
     * Can this visitor download images from this gallery right now?
     *
     * @param int $post_id
     * @return bool
     */
    public static function user_can_download( $post_id ) {

        // If a separate download password exists, respect it.
        if ( self::requires_download_password( $post_id ) ) {
            return self::is_download_unlocked( $post_id );
        }

        // Otherwise, downloads ride on the viewing rules.
        return self::user_can_view( $post_id );
    }

    /**
     * Try to unlock viewing with submitted password.
     * On success: sets signed cookie for viewing.
     *
     * @param int    $post_id
     * @param string $submitted_password
     * @return bool Whether password was correct.
     */
    protected static function try_unlock_view_with_password( $post_id, $submitted_password ) {

        $stored_hash = get_post_meta( $post_id, self::META_VIEW_PASS_HASH, true );
        if ( empty( $stored_hash ) ) {
            return false;
        }

        if ( ! wp_check_password( $submitted_password, $stored_hash ) ) {
            return false;
        }

        $token       = self::build_token( $post_id, $stored_hash, 'view' );
        $cookie_name = 'cgm_gallery_view_' . intval( $post_id );
        $expire      = time() + DAY_IN_SECONDS;

        $cookie_args = [
            'expires'  => $expire,
            'path'     => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        // Only set domain if it’s a real domain (empty string breaks cookies on some setups)
        if ( defined('COOKIE_DOMAIN') && ! empty( COOKIE_DOMAIN ) ) {
            $cookie_args['domain'] = COOKIE_DOMAIN;
        }

        setcookie( $cookie_name, $token, $cookie_args );


        $_COOKIE[ $cookie_name ] = $token;

        return true;
    }

    /**
     * Try to unlock downloads with submitted password.
     * On success: sets signed cookie for downloads.
     *
     * @param int    $post_id
     * @param string $submitted_password
     * @return bool
     */
    public static function try_unlock_download_with_password( $post_id, $submitted_password ) {

        $stored_hash = get_post_meta( $post_id, self::META_DOWNLOAD_PASS_HASH, true );
        if ( empty( $stored_hash ) ) {
            return false;
        }

        if ( ! wp_check_password( $submitted_password, $stored_hash ) ) {
            return false;
        }

        $token       = self::build_token( $post_id, $stored_hash, 'download' );
        $cookie_name = 'cgm_gallery_dl_' . intval( $post_id );
        $expire      = time() + DAY_IN_SECONDS;

        $cookie_args = [
            'expires'  => $expire,
            'path'     => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        // Only set domain if it’s a real domain (empty string breaks cookies on some setups)
        if ( defined('COOKIE_DOMAIN') && ! empty( COOKIE_DOMAIN ) ) {
            $cookie_args['domain'] = COOKIE_DOMAIN;
        }

        setcookie( $cookie_name, $token, $cookie_args );


        $_COOKIE[ $cookie_name ] = $token;

        return true;
    }

    /* ---------------------------------------------------------------------
     * Front-end gates
     * ------------------------------------------------------------------ */

    /**
     * Handle viewing password form and decide if gallery should render.
     *
     * Usage (in a template or render callback):
     *
     *   if ( ! CGM_Gallery_Access::handle_frontend_gate( $post_id ) ) {
     *       // form already printed, don't render gallery.
     *       return;
     *   }
     *   // render gallery grid...
     *
     * @param int|null $post_id
     * @return bool True if gallery is unlocked for viewing, false if form printed.
     */
    public static function handle_frontend_gate( $post_id = null ) {
        if ( null === $post_id ) {
            $post_id = get_the_ID();
        }

        $post_id = intval( $post_id );

        // If no viewing password required, just render.
        if ( ! self::requires_view_password( $post_id ) ) {
            return true;
        }

        $password_error = false;

        // Handle form submit.
        if ( isset( $_POST['cgm_view_password'], $_POST['cgm_view_password_post'] ) ) {

            if (
                intval( $_POST['cgm_view_password_post'] ) === $post_id &&
                isset( $_POST['_wpnonce'] ) &&
                wp_verify_nonce(
                    sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ),
                    'cgm_view_password_' . $post_id
                )
            ) {

                $submitted = (string) wp_unslash( $_POST['cgm_view_password'] );

                if ( self::try_unlock_view_with_password( $post_id, $submitted ) ) {
                    // Scrub POST.
                    wp_safe_redirect( get_permalink( $post_id ) );
                    exit;
                } else {
                    $password_error = true;
                }
            } else {
                $password_error = true;
            }
        }

        // Check whether visitor is now "unlocked".
        if ( self::is_view_unlocked( $post_id ) ) {
            return true;
        }

        // Otherwise, output viewing password form and tell caller NOT to render gallery.
        self::render_view_password_form( $post_id, $password_error );

        return false;
    }

    /**
     * View password form markup.
     *
     * @param int  $post_id
     * @param bool $password_error
     */
    public static function render_view_password_form( $post_id, $password_error ) {
        ?>
        <div class="cgm-gallery-password-wrap">
            <h2><?php esc_html_e( 'Enter gallery password', 'client-gallery' ); ?></h2>

            <?php if ( $password_error ) : ?>
                <p style="color:#f55;">
                    <?php esc_html_e( 'Incorrect password. Please try again.', 'client-gallery' ); ?>
                </p>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'cgm_view_password_' . $post_id ); ?>
                <input type="hidden" name="cgm_view_password_post" value="<?php echo esc_attr( $post_id ); ?>" />

                <p>
                    <label for="cgm_view_password">
                        <?php esc_html_e( 'Password', 'client-gallery' ); ?>
                    </label><br/>
                    <input type="password"
                           id="cgm_view_password"
                           name="cgm_view_password"
                           style="max-width:260px;width:100%;" />
                </p>

                <p>
                    <button type="submit" class="wp-element-button">
                        <?php esc_html_e( 'View gallery', 'client-gallery' ); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Handle download password submission on the gallery page.
     *
     * Returns true/false to indicate whether there was an error,
     * so template can show an error message.
     *
     * @param int $post_id
     * @return bool $password_error
     */
    public static function handle_download_password_submission( $post_id ) {
        $post_id = intval( $post_id );
        $password_error = false;

        if ( ! isset( $_POST['cgm_download_password'], $_POST['cgm_download_password_post'] ) ) {
            return false;
        }

        if (
            intval( $_POST['cgm_download_password_post'] ) === $post_id &&
            isset( $_POST['_wpnonce'] ) &&
            wp_verify_nonce(
                sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ),
                'cgm_download_password_' . $post_id
            )
        ) {

            $submitted = (string) wp_unslash( $_POST['cgm_download_password'] );

            if ( self::try_unlock_download_with_password( $post_id, $submitted ) ) {

                // If the front-end provided an intended download URL, go there immediately (better UX).
                $redirect = isset( $_POST['cgm_download_redirect'] )
                    ? wp_unslash( $_POST['cgm_download_redirect'] )
                    : '';

                // Only allow safe redirects; fallback to gallery downloads section.
                $fallback = get_permalink( $post_id ) . '#downloads';
                $redirect = wp_validate_redirect( $redirect, $fallback );

                wp_safe_redirect( $redirect );
                exit;

            } else {
                $password_error = true;
            }

        } else {
            $password_error = true;
        }

        return $password_error;
    }
}
