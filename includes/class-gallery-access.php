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
    const META_HIDDEN              = '_cgm_hidden';

    // Visibility options (for viewing only)
    const VIS_PUBLIC   = 'public';
    const VIS_PASSWORD = 'password';

    /**
     * URL-grant params: cookie-independent unlock fallback.
     *
     * Meta's in-app browser (Facebook/Instagram WebView) drops Set-Cookie on
     * the 302 redirect that follows a successful password POST, so the very
     * next request looked locked again and users were denied right after
     * entering a correct password. The redirect now also carries a signed,
     * short-lived grant token in the URL; every access check accepts either
     * the cookie or a valid grant, and the cookie is re-issued on the
     * following 200 response (which WebViews do honour).
     */
    const GRANT_PARAM_VIEW     = 'cgm_grant';
    const GRANT_PARAM_DOWNLOAD = 'cgm_grant_dl';
    const GRANT_TTL            = 900; // 15 minutes

    // Download to start once the gallery reloads after a download unlock.
    const DL_NEXT_PARAM = 'cgm_dl_next';

    /**
     * Bootstraps hooks.
     */
    public static function init() {

        // Admin meta box for visibility + passwords.
        add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_box' ] );
        add_action( 'save_post_client_gallery', [ __CLASS__, 'save_meta' ] );

        // Re-issue unlock cookies from a URL grant before any output
        // (Set-Cookie on a 200 works in WebViews that dropped it on the 302).
        add_action( 'template_redirect', [ __CLASS__, 'maybe_reissue_cookies_from_grant' ], 0 );

        // Hidden galleries: reachable only by their exact link.
        add_action( 'pre_get_posts', [ __CLASS__, 'exclude_hidden_from_queries' ] );
        add_filter( 'rest_prepare_client_gallery', [ __CLASS__, 'rest_block_hidden' ], 10, 3 );
        add_action( 'template_redirect', [ __CLASS__, 'block_hidden_id_lookup' ], 1 );
        add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', [ __CLASS__, 'yoast_exclude_hidden' ] );
        add_filter( 'wpseo_opengraph_image', [ __CLASS__, 'strip_hidden_social_image' ] );
        add_filter( 'wpseo_twitter_image', [ __CLASS__, 'strip_hidden_social_image' ] );
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

        <p>
            <label>
                <input type="checkbox"
                       name="cgm_hidden"
                       value="1"
                    <?php checked( self::is_hidden( $post->ID ) ); ?> />
                <strong><?php esc_html_e( 'Hidden (link only)', 'client-gallery' ); ?></strong>
            </label>
        </p>
        <p class="description">
            <?php esc_html_e( 'Kept out of the gallery feed, RSS, sitemaps, search engines, the REST API and link-preview images. Cover image is not served publicly. Only someone with the exact link can open it — pair with a viewing password, and use a non-guessable slug.', 'client-gallery' ); ?>
        </p>

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

        if ( ! empty( $_POST['cgm_hidden'] ) ) {
            update_post_meta( $post_id, self::META_HIDDEN, '1' );
        } else {
            delete_post_meta( $post_id, self::META_HIDDEN );
        }

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
        return self::requires_view_password( (int) $post_id ) || self::is_hidden( (int) $post_id );
    }

    /**
     * Is this gallery hidden (link-only)?
     */
    public static function is_hidden( $post_id ) {
        return get_post_meta( (int) $post_id, self::META_HIDDEN, true ) === '1';
    }

    /* ---------------------------------------------------------------------
     * Hidden galleries
     * ------------------------------------------------------------------ */

    /**
     * Drop hidden galleries from every non-singular gallery query on the
     * front end and REST: gallery index block, RSS feeds, archives, the
     * plugin's own sitemap, REST collections. wp-admin lists are untouched,
     * and editors still see them in REST (block editor).
     */
    public static function exclude_hidden_from_queries( $query ) {
        if ( is_admin() && ! wp_doing_ajax() ) {
            return;
        }
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST && current_user_can( 'edit_posts' ) ) {
            return;
        }
        if ( $query->get( 'cgm_include_hidden' ) ) {
            return;
        }

        $types = (array) $query->get( 'post_type' );
        if ( ! in_array( 'client_gallery', $types, true ) && ! in_array( 'any', $types, true ) ) {
            return;
        }

        // Single gallery requested by its slug: let it load.
        if ( $query->get( 'client_gallery' ) || $query->get( 'name' ) ) {
            return;
        }

        $meta_query = $query->get( 'meta_query' );
        if ( ! is_array( $meta_query ) ) {
            $meta_query = [];
        }
        $meta_query[] = [
            'relation' => 'OR',
            [ 'key' => self::META_HIDDEN, 'compare' => 'NOT EXISTS' ],
            [ 'key' => self::META_HIDDEN, 'value' => '1', 'compare' => '!=' ],
        ];
        $query->set( 'meta_query', $meta_query );
    }

    /**
     * REST single fetch (/wp/v2/client_gallery/<id>) doesn't go through
     * WP_Query - 404 hidden galleries for anyone who can't edit them.
     */
    public static function rest_block_hidden( $response, $post, $request ) {
        if ( self::is_hidden( $post->ID ) && ! current_user_can( 'edit_post', $post->ID ) ) {
            return new WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.' ), [ 'status' => 404 ] );
        }
        return $response;
    }

    /**
     * Gallery IDs are sequential, and ?p=<id> / ?client_gallery=<id> would
     * redirect_canonical (priority 10) straight to the pretty URL, leaking the
     * slug. Only the exact slug link may open a hidden gallery.
     */
    public static function block_hidden_id_lookup() {
        if ( ! is_singular( 'client_gallery' ) ) {
            return;
        }
        $post_id = get_queried_object_id();
        if ( ! self::is_hidden( $post_id ) || current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $slug = (string) get_post_field( 'post_name', $post_id );
        $requested = (string) get_query_var( 'client_gallery' );
        if ( $requested === '' || $requested !== $slug ) {
            global $wp_query;
            $wp_query->set_404();
            status_header( 404 );
            nocache_headers();
        }
    }

    public static function yoast_exclude_hidden( $ids ) {
        $hidden = get_posts( [
            'post_type'        => 'client_gallery',
            'post_status'      => 'any',
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'meta_key'         => self::META_HIDDEN,
            'meta_value'         => '1',
            'suppress_filters'   => true,
            'cgm_include_hidden' => true,
        ] );
        return array_merge( (array) $ids, $hidden );
    }

    /**
     * Yoast adds the featured image to og:image / twitter:image on its own -
     * keep hidden galleries' photos out of link previews.
     */
    public static function strip_hidden_social_image( $image ) {
        if ( is_singular( 'client_gallery' ) && self::is_hidden( get_queried_object_id() ) ) {
            return '';
        }
        return $image;
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

    /* ---------------------------------------------------------------------
     * URL grants (cookie-independent unlock, for cookie-dropping WebViews)
     * ------------------------------------------------------------------ */

    /**
     * Build a short-lived signed URL grant: "{expiry}.{hmac}".
     *
     * Tied to gallery ID + current password hash + scope + expiry, so a
     * password change invalidates outstanding grants immediately.
     */
    protected static function build_url_grant( $post_id, $hash, $scope ) {
        if ( empty( $hash ) ) {
            return '';
        }

        $expiry = time() + self::GRANT_TTL;
        $data   = $post_id . '|' . $hash . '|' . $scope . '|' . $expiry;

        return $expiry . '.' . hash_hmac( 'sha256', $data, wp_salt( 'cgm_gallery_access' ) );
    }

    /**
     * Validate a URL grant string for a gallery + scope.
     */
    protected static function check_url_grant( $post_id, $hash, $scope, $grant ) {
        if ( empty( $hash ) || empty( $grant ) || strpos( $grant, '.' ) === false ) {
            return false;
        }

        list( $expiry, $sig ) = explode( '.', $grant, 2 );
        $expiry = (int) $expiry;

        if ( $expiry < time() ) {
            return false;
        }

        $data     = $post_id . '|' . $hash . '|' . $scope . '|' . $expiry;
        $expected = hash_hmac( 'sha256', $data, wp_salt( 'cgm_gallery_access' ) );

        return hash_equals( $expected, $sig );
    }

    /**
     * Read a grant param off the current request.
     */
    protected static function current_url_grant( $param ) {
        return isset( $_GET[ $param ] )
            ? sanitize_text_field( wp_unslash( $_GET[ $param ] ) )
            : '';
    }

    /**
     * Does the current request carry a valid VIEW grant for this gallery?
     */
    protected static function has_valid_view_grant( $post_id ) {
        $grant = self::current_url_grant( self::GRANT_PARAM_VIEW );
        if ( ! $grant ) {
            return false;
        }

        $hash = get_post_meta( $post_id, self::META_VIEW_PASS_HASH, true );

        return self::check_url_grant( $post_id, $hash, 'view', $grant );
    }

    /**
     * Does the current request carry a valid DOWNLOAD grant for this gallery?
     */
    protected static function has_valid_download_grant( $post_id ) {
        $grant = self::current_url_grant( self::GRANT_PARAM_DOWNLOAD );
        if ( ! $grant ) {
            return false;
        }

        $hash = get_post_meta( $post_id, self::META_DOWNLOAD_PASS_HASH, true );

        return self::check_url_grant( $post_id, $hash, 'download', $grant );
    }

    /**
     * Public: active grant strings for the current request (or '' if none),
     * so templates can propagate them onto thumb/download URLs. Without this,
     * a cookie-less WebView would unlock the page but every <img> request
     * would still be denied.
     */
    public static function active_view_grant( $post_id ) {
        return self::has_valid_view_grant( $post_id )
            ? self::current_url_grant( self::GRANT_PARAM_VIEW )
            : '';
    }

    public static function active_download_grant( $post_id ) {
        return self::has_valid_download_grant( $post_id )
            ? self::current_url_grant( self::GRANT_PARAM_DOWNLOAD )
            : '';
    }

    /**
     * template_redirect (priority 0): if the request carries a valid grant
     * but the matching cookie is missing, set the cookie now — this response
     * is a 200, which cookie-dropping WebViews accept.
     */
    public static function maybe_reissue_cookies_from_grant() {
        if ( ! is_singular( 'client_gallery' ) || headers_sent() ) {
            return;
        }

        $post_id = get_queried_object_id();
        if ( $post_id <= 0 ) {
            return;
        }

        if ( self::has_valid_view_grant( $post_id ) ) {
            $hash        = get_post_meta( $post_id, self::META_VIEW_PASS_HASH, true );
            $cookie_name = 'cgm_gallery_view_' . intval( $post_id );

            if ( $hash && empty( $_COOKIE[ $cookie_name ] ) ) {
                self::set_unlock_cookie( $cookie_name, self::build_token( $post_id, $hash, 'view' ) );
            }
        }

        if ( self::has_valid_download_grant( $post_id ) ) {
            $hash        = get_post_meta( $post_id, self::META_DOWNLOAD_PASS_HASH, true );
            $cookie_name = 'cgm_gallery_dl_' . intval( $post_id );

            if ( $hash && empty( $_COOKIE[ $cookie_name ] ) ) {
                self::set_unlock_cookie( $cookie_name, self::build_token( $post_id, $hash, 'download' ) );
            }
        }
    }

    /**
     * Shared cookie-setting logic for view/download unlock cookies.
     */
    protected static function set_unlock_cookie( $cookie_name, $token ) {
        $cookie_args = [
            'expires'  => time() + DAY_IN_SECONDS,
            'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        if ( defined( 'COOKIE_DOMAIN' ) && ! empty( COOKIE_DOMAIN ) ) {
            $cookie_args['domain'] = COOKIE_DOMAIN;
        }

        setcookie( $cookie_name, $token, $cookie_args );
        $_COOKIE[ $cookie_name ] = $token;
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

        if ( ! empty( $cookie_value ) ) {
            $expected = self::build_token( $post_id, $stored_hash, 'view' );
            if ( hash_equals( $expected, $cookie_value ) ) {
                return true;
            }
        }

        // Cookie missing/invalid: accept a valid URL grant (cookie-dropping WebViews).
        return self::has_valid_view_grant( $post_id );
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

        if ( ! empty( $cookie_value ) ) {
            $expected = self::build_token( $post_id, $stored_hash, 'download' );
            if ( hash_equals( $expected, $cookie_value ) ) {
                return true;
            }
        }

        // Cookie missing/invalid: accept a valid URL grant (cookie-dropping WebViews).
        return self::has_valid_download_grant( $post_id );
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

        self::set_unlock_cookie(
            'cgm_gallery_view_' . intval( $post_id ),
            self::build_token( $post_id, $stored_hash, 'view' )
        );

        return true;
    }

    /**
     * Public: mint a fresh VIEW grant for redirect URLs after a successful
     * password unlock (cookie-dropping WebView fallback).
     */
    public static function fresh_view_grant( $post_id ) {
        $hash = get_post_meta( $post_id, self::META_VIEW_PASS_HASH, true );
        return self::build_url_grant( (int) $post_id, $hash, 'view' );
    }

    /**
     * Public: mint a fresh DOWNLOAD grant for redirect URLs after a
     * successful password unlock (cookie-dropping WebView fallback).
     */
    public static function fresh_download_grant( $post_id ) {
        $hash = get_post_meta( $post_id, self::META_DOWNLOAD_PASS_HASH, true );
        return self::build_url_grant( (int) $post_id, $hash, 'download' );
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

        self::set_unlock_cookie(
            'cgm_gallery_dl_' . intval( $post_id ),
            self::build_token( $post_id, $stored_hash, 'download' )
        );

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
                    // Scrub POST. Carry a URL grant through the redirect:
                    // Meta's in-app browser drops Set-Cookie on 302s, so the
                    // cookie alone isn't enough to survive this hop.
                    $redirect = add_query_arg(
                        self::GRANT_PARAM_VIEW,
                        rawurlencode( self::fresh_view_grant( $post_id ) ),
                        get_permalink( $post_id )
                    );
                    wp_safe_redirect( $redirect );
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

                // Intended download URL from the front-end, safe hosts only.
                $next = isset( $_POST['cgm_download_redirect'] )
                    ? wp_validate_redirect( wp_unslash( $_POST['cgm_download_redirect'] ), '' )
                    : '';

                // Land back on the gallery, not straight in the file: a file
                // response never replaces the page, so the modal stayed open
                // and the page kept its locked state, re-prompting on every
                // click (2026-10-16). The reloaded page starts the download
                // itself (password-modal.js).
                $redirect = get_permalink( $post_id );

                // Carry URL grants through the redirect: Meta's in-app
                // browser drops Set-Cookie on 302s, so the reloaded page (and
                // the download it starts) would otherwise be locked again.
                $view_grant = self::active_view_grant( $post_id );
                if ( $view_grant ) {
                    $redirect = add_query_arg( self::GRANT_PARAM_VIEW, rawurlencode( $view_grant ), $redirect );
                }
                $redirect = add_query_arg(
                    self::GRANT_PARAM_DOWNLOAD,
                    rawurlencode( self::fresh_download_grant( $post_id ) ),
                    $redirect
                );
                if ( $next ) {
                    $redirect = add_query_arg( self::DL_NEXT_PARAM, rawurlencode( $next ), $redirect );
                }

                wp_safe_redirect( $redirect . '#downloads' );
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
