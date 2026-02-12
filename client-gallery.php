<?php
/**
 * Plugin Name: Client Gallery Manager
 * Description: Private client galleries stored outside the Media Library with thumbnails and secure downloads.
 * Version: 0.1.2
 * Author: Allen Redshaw
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Define storage path on disk — customize if needed.
 */
define( 'CGM_STORAGE_PATH', '/var/www/client_galleries' );

/**
 * Plugin root URL and path.
 */
define( 'CGM_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'CGM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Load required classes.
 */
require_once CGM_PLUGIN_PATH . 'includes/class-gallery-storage.php';
require_once CGM_PLUGIN_PATH . 'includes/class-gallery-access.php';
require_once CGM_PLUGIN_PATH . 'includes/class-gallery-download.php';
require_once CGM_PLUGIN_PATH . 'includes/class-gallery-admin.php';
require_once CGM_PLUGIN_PATH . 'includes/class-gallery-blocks.php';
require_once CGM_PLUGIN_PATH . 'includes/class-gallery-seo.php';

/**
 * Initialize components on plugin load.
 */
function cgm_init_plugin() {
    CGM_Gallery_Admin::init();
    CGM_Gallery_Access::init();
    CGM_Gallery_Download::init();
    CGM_Gallery_Blocks::init();
    CGM_Gallery_SEO::init();
    CGM_Gallery_Storage::init();
}
add_action( 'plugins_loaded', 'cgm_init_plugin' );

/**
 * Register plugin assets (CSS/JS).
 */
function cgm_register_assets() {
    wp_register_style(
        'cgm-gallery-css',
        CGM_PLUGIN_URL . 'assets/css/gallery.css',
        [],
        '0.1.2'
    );

    wp_register_style(
        'cgm-lightbox-css',
        CGM_PLUGIN_URL . 'assets/css/lightbox.css',
        [],
        '0.1.16'
    );

    wp_register_style(
        'cgm-password-modal-css',
        CGM_PLUGIN_URL . 'assets/css/password-modal.css',
        ['cgm-gallery-css', 'cgm-lightbox-css'],
        '0.1.0'
    );

    wp_register_script(
        'cgm-gallery-lightbox',
        CGM_PLUGIN_URL . 'assets/js/gallery-lightbox.js',
        [],
        '0.1.2',
        true
    );

    wp_register_script(
        'cgm-password-modal-js',
        CGM_PLUGIN_URL . 'assets/js/password-modal.js',
        [],
        '0.1.4',
        true
    );

    wp_register_script(
        'cgm-gallery-share',
        CGM_PLUGIN_URL . 'assets/js/gallery-share.js',
        [],
        '0.1.3',
        true
    );

}
add_action( 'init', 'cgm_register_assets' );

/**
 * Enqueue front-end CSS/JS for single galleries.
 *
 * Note: the index block's CSS is attached to the block via register_block_type().
 */
function cgm_enqueue_frontend_assets() {
    if ( is_singular( 'client_gallery' ) ) {
        wp_enqueue_style( 'cgm-gallery-css' );
        wp_enqueue_style( 'cgm-lightbox-css' );
        wp_enqueue_style( 'cgm-password-modal-css' );

        wp_enqueue_script( 'cgm-gallery-lightbox' );
        wp_enqueue_script( 'cgm-password-modal-js' );
        wp_enqueue_script( 'cgm-gallery-share' );


        // Pass DOWNLOAD password state into JS so it knows when to intercept clicks.
        if ( class_exists( 'CGM_Gallery_Access' ) ) {
            $gallery_id = get_queried_object_id();

            $requires_download_password = method_exists( 'CGM_Gallery_Access', 'requires_download_password' )
                ? CGM_Gallery_Access::requires_download_password( $gallery_id )
                : false;

            $download_unlocked = method_exists( 'CGM_Gallery_Access', 'user_can_download' )
                ? CGM_Gallery_Access::user_can_download( $gallery_id )
                : false;

            wp_localize_script(
                'cgm-password-modal-js',
                'cgmPasswordSettings',
                [
                    'requiresPassword' => $requires_download_password ? 1 : 0,
                    'downloadUnlocked' => $download_unlocked ? 1 : 0,
                    'adminPostUrl'     => admin_url( 'admin-post.php' ),
                ]
            );
        }
    }
}
add_action( 'wp_enqueue_scripts', 'cgm_enqueue_frontend_assets' );

/**
 * Register the custom post type: client_gallery.
 */
function cgm_register_gallery_cpt() {
    $labels = [
        'name'          => 'Client Galleries',
        'singular_name' => 'Client Gallery',
        'add_new_item'  => 'Add New Client Gallery',
        'edit_item'     => 'Edit Client Gallery',
    ];

    $args = [
        'labels'              => $labels,
        'public'              => true,
        'show_ui'             => true,
        'show_in_menu'        => true,
        'show_in_rest'        => true,
        'exclude_from_search' => true,
        'show_in_nav_menus'   => false,
        'publicly_queryable'  => true,
        'supports'            => [ 'title', 'editor', 'excerpt', 'thumbnail' ],
        'rewrite'             => [ 'slug' => 'client-gallery' ],
        'has_archive'         => false,
    ];

    register_post_type( 'client_gallery', $args );
}
add_action( 'init', 'cgm_register_gallery_cpt' );

/**
 * Render Client Gallery content inside the_content so it plays nice with TT5 block templates.
 */
function cgm_render_client_gallery_content( $content ) {
    if ( ! is_singular( 'client_gallery' ) || ! in_the_loop() || ! is_main_query() ) {
        return $content;
    }

    // Ensure password modal assets are definitely loaded for this view
    wp_enqueue_style( 'cgm-password-modal-css' );
    wp_enqueue_script( 'cgm-password-modal-js' );

    $gallery_id = get_the_ID();
    $gallery_slug = get_post_field( 'post_name', $gallery_id );

    // Public = no viewing password required
    $is_public_gallery = class_exists( 'CGM_Gallery_Access' )
        ? ! CGM_Gallery_Access::requires_view_password( $gallery_id )
        : true;

    $cgm_share_url = add_query_arg(
        [
            'utm_source'   => 'social',
            'utm_medium'   => 'share',
            'utm_campaign' => 'gallery_share',
            'utm_content'  => $gallery_slug,
        ],
        get_permalink( $gallery_id )
    );

    ob_start();
    ?>
    <div class="cgm-gallery-wrapper alignwide">

        <?php
        /**
         * 1) Gate VIEWING via viewing password (if required).
         * handle_frontend_gate() should print its own viewing password form when needed.
         */
        $can_view = class_exists( 'CGM_Gallery_Access' )
            ? CGM_Gallery_Access::handle_frontend_gate( $gallery_id )
            : true;

        if ( ! $can_view ) {
            // Viewing password form is already printed by handle_frontend_gate().
            echo '</div><!-- .cgm-gallery-wrapper -->';
            return ob_get_clean();
        }

        /**
         * 2) Handle DOWNLOAD password submission (if any).
         * This is separate from viewing; allows proofing vs paid download.
         */
        $download_error = false;
        if ( class_exists( 'CGM_Gallery_Access' ) && method_exists( 'CGM_Gallery_Access', 'handle_download_password_submission' ) ) {
            $download_error = CGM_Gallery_Access::handle_download_password_submission( $gallery_id );
        }

        // For JS fallback (in addition to localized script), expose
        // password state as data attributes on the modal container.
        $requires_download_password = class_exists( 'CGM_Gallery_Access' )
            && method_exists( 'CGM_Gallery_Access', 'requires_download_password' )
            ? CGM_Gallery_Access::requires_download_password( $gallery_id )
            : false;

        $download_unlocked = class_exists( 'CGM_Gallery_Access' )
            && method_exists( 'CGM_Gallery_Access', 'user_can_download' )
            ? CGM_Gallery_Access::user_can_download( $gallery_id )
            : false;
        ?>

        <div class="cgm-gallery-header" id="downloads">
            <div class="cgm-gallery-actions">

                <?php if ( class_exists( 'CGM_Gallery_Access' )
                    && method_exists( 'CGM_Gallery_Access', 'requires_download_password' )
                    && CGM_Gallery_Access::requires_download_password( $gallery_id ) ) : ?>

                    <?php if ( method_exists( 'CGM_Gallery_Access', 'user_can_download' )
                        && CGM_Gallery_Access::user_can_download( $gallery_id ) ) : ?>

                        <p class="cgm-gallery-download-all">
                            <a class="wp-element-button cgm-download-trigger"
                            href="<?php echo esc_url( cgm_download_all_url( $gallery_id ) ); ?>">
                                <?php esc_html_e( 'Download all as ZIP', 'client-gallery' ); ?>
                            </a>
                        </p>

                    <?php else : ?>

                        <div class="cgm-gallery-download-all">
                            <a class="wp-element-button cgm-download-trigger"
                            href="#"
                            data-cgm-download-all="1">
                                <?php esc_html_e( 'Download all as ZIP', 'client-gallery' ); ?>
                            </a>
                        </div>

                    <?php endif; ?>

                <?php else : ?>

                    <p class="cgm-gallery-download-all">
                        <a class="wp-element-button cgm-download-trigger"
                        href="<?php echo esc_url( cgm_download_all_url( $gallery_id ) ); ?>">
                            <?php esc_html_e( 'Download all as ZIP', 'client-gallery' ); ?>
                        </a>
                    </p>

                <?php endif; ?>

                <?php if ( $is_public_gallery ) : ?>

                    <!-- Generic share (native share sheet in real browsers) -->
                    <button type="button"
                            class="wp-element-button cgm-share-trigger"
                            data-cgm-share-url="<?php echo esc_url( $cgm_share_url ); ?>">
                        <?php esc_html_e( 'Share', 'client-gallery' ); ?>
                    </button>

                    <!-- Meta fallback: Share to Facebook -->
                    <a class="wp-element-button cgm-share-facebook"
                    href="<?php echo esc_url( 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode( $cgm_share_url ) ); ?>"
                    target="_blank"
                    rel="noopener noreferrer"
                    hidden>
                        <?php esc_html_e( 'Share to Facebook', 'client-gallery' ); ?>
                    </a>

                    <!-- Meta fallback: Copy link -->
                    <button type="button"
                            class="wp-element-button cgm-copy-link"
                            data-cgm-share-url="<?php echo esc_url( $cgm_share_url ); ?>"
                            hidden>
                        <?php esc_html_e( 'Copy link', 'client-gallery' ); ?>
                    </button>

                <?php endif; ?>


            </div>
        </div>


        <?php
        $can_download = false;

        if ( class_exists( 'CGM_Gallery_Access' ) ) {
            $can_download = CGM_Gallery_Access::user_can_download( $gallery_id );
        }
        ?>


        <?php
        /**
         * 3) Render gallery grid (view access already granted at this point).
         */
        $files = CGM_Gallery_Storage::get_files( $gallery_id );

        if ( empty( $files ) ) :
            echo '<p>' . esc_html__( 'No images in this gallery yet.', 'client-gallery' ) . '</p>';
        else :
            ?>
            <div class="cgm-grid">
                <?php foreach ( $files as $i => $file ) : ?>
                    <div class="cgm-item">
                        <a
                            href="<?php echo esc_url( $file['thumb_url'] ); ?>"
                            class="cgm-lightbox-trigger"
                            data-thumb="<?php echo esc_url( $file['thumb_url'] ); ?>"
                            <?php if ( $can_download ) : ?>
                                data-download="<?php echo esc_url( $file['download_url'] ); ?>"
                            <?php endif; ?>
                        >
                            <img
                                src="<?php echo esc_url( $file['thumb_url'] ); ?>"
                                alt="<?php echo esc_attr(
                                    CGM_Gallery_SEO::build_image_alt( $gallery_id, $i + 1, $file )
                                ); ?>"
                                loading="lazy"
                            />
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php
        endif;
        ?>

    </div><!-- .cgm-gallery-wrapper -->

    <!-- Lightbox overlay -->
    <div class="cgm-lightbox-overlay" id="cgm-lightbox" hidden>
        <div class="cgm-lightbox-inner">
            <button type="button"
                    class="cgm-lightbox-close"
                    id="cgm-lightbox-close"
                    aria-label="<?php esc_attr_e( 'Close', 'client-gallery' ); ?>">×</button>

            <div class="cgm-lightbox-image-wrap">
                <img id="cgm-lightbox-image" src="" alt="" />
            </div>

            <div class="cgm-lightbox-actions">
                <a id="cgm-lightbox-download"
                   class="wp-element-button cgm-download-trigger"
                   href="#"
                   download>
                    <?php esc_html_e( 'Download image', 'client-gallery' ); ?>
                </a>
            </div>
        </div>

        <button type="button"
                class="cgm-lightbox-nav cgm-lightbox-prev"
                id="cgm-lightbox-prev"
                aria-label="<?php esc_attr_e( 'Previous image', 'client-gallery' ); ?>">‹</button>

        <button type="button"
                class="cgm-lightbox-nav cgm-lightbox-next"
                id="cgm-lightbox-next"
                aria-label="<?php esc_attr_e( 'Next image', 'client-gallery' ); ?>">›</button>
    </div>

    <!-- Download password modal -->
    <div
        class="cgm-password-modal-overlay"
        id="cgm-download-password-modal"
        data-cgm-gallery-id="<?php echo (int) $gallery_id; ?>"
        data-cgm-requires-password="<?php echo $requires_download_password ? '1' : '0'; ?>"
        data-cgm-download-unlocked="<?php echo $download_unlocked ? '1' : '0'; ?>"
        <?php echo $download_error ? ' data-cgm-download-error="1"' : ''; ?>
        hidden
    >
        <div class="cgm-password-modal-inner">
            <button type="button"
                    class="cgm-password-modal-close"
                    aria-label="<?php esc_attr_e( 'Close download password dialog', 'client-gallery' ); ?>">
                ×
            </button>

            <h2><?php esc_html_e( 'Enter download password', 'client-gallery' ); ?></h2>

            <?php if ( $download_error ) : ?>
                <p class="cgm-password-modal-error">
                    <?php esc_html_e( 'Incorrect password. Please try again.', 'client-gallery' ); ?>
                </p>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'cgm_download_password_' . $gallery_id ); ?>
                <input type="hidden"
                       name="cgm_download_password_post"
                       value="<?php echo esc_attr( $gallery_id ); ?>" />
                <input type="hidden" 
                        name="cgm_download_redirect" 
                        id="cgm_download_redirect" 
                        value="" />
                <p>
                    <label for="cgm_download_password">
                        <?php esc_html_e( 'Password', 'client-gallery' ); ?>
                    </label><br/>
                    <input type="password"
                           id="cgm_download_password"
                           name="cgm_download_password"
                           autocomplete="current-password" />
                </p>

                <p>
                    <button type="submit" class="wp-element-button">
                        <?php esc_html_e( 'Unlock downloads', 'client-gallery' ); ?>
                    </button>
                </p>
            </form>
        </div>
    </div>

    <?php

    $gallery_html = ob_get_clean();

    // Marker-controlled placement:
    // Put <!-- cgm:gallery --> in a Custom HTML block to control where the gallery renders.
    $marker = '<!-- cgm:gallery -->';

    if ( strpos( $content, $marker ) !== false ) {
        // Replace *all* markers (in case user accidentally adds two)
        return str_replace( $marker, $gallery_html, $content );
    }

    // Fallback: append gallery after Gutenberg content.
    return $content . $gallery_html;

}
add_filter( 'the_content', 'cgm_render_client_gallery_content' );


