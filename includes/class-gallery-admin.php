<?php
/**
 * Admin UI for Client Gallery Manager.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CGM_Gallery_Admin {

    public static function init() {
        add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_boxes' ] );
        add_action( 'save_post_client_gallery', [ __CLASS__, 'save_gallery_meta' ] );
        add_action( 'post_edit_form_tag', [ __CLASS__, 'add_multipart_enctype' ] );

        // Sync button handler
        add_action( 'admin_post_cgm_sync_gallery', [ __CLASS__, 'handle_sync_gallery' ] );

        // NEW: Build ZIP handler
        add_action( 'admin_post_cgm_build_zip', [ __CLASS__, 'handle_build_zip' ] );

        // Success notices
        add_action( 'admin_notices', [ __CLASS__, 'maybe_show_sync_notice' ] );
        add_action( 'admin_notices', [ __CLASS__, 'maybe_show_zip_notice' ] );
    }

    public static function add_multipart_enctype() {
        global $post;
        if ( $post && $post->post_type === 'client_gallery' ) {
            echo ' enctype="multipart/form-data"';
        }
    }

    public static function add_meta_boxes() {
        add_meta_box(
            'cgm_gallery_files',
            'Gallery Files',
            [ __CLASS__, 'render_files_metabox' ],
            'client_gallery',
            'normal',
            'default'
        );

        add_meta_box(
            'cgm_gallery_schema',
            'Schema Markup',
            [ __CLASS__, 'render_schema_metabox' ],
            'client_gallery',
            'normal',
            'default'
        );
    }

    public static function render_files_metabox( $post ) {
        wp_nonce_field( 'cgm_save_gallery_' . $post->ID, 'cgm_gallery_nonce' );

        echo '<p>Upload images for this gallery. Thumbnails will be generated automatically.</p>';
        echo '<input type="file" name="cgm_gallery_files[]" multiple="multiple" />';

        $files = CGM_Gallery_Storage::get_files( $post->ID );

        if ( ! empty( $files ) ) {
            $current_cover = get_post_meta( $post->ID, '_cgm_cover_image', true );

            echo '<h4>Existing Files</h4>';
            echo '<p style="margin-bottom:6px;">Select a cover image for this gallery (used on the gallery index).</p>';

            echo '<ul style="max-height:260px; overflow:auto; border:1px solid #ddd; padding:8px; margin-top:0;">';

            foreach ( $files as $f ) {
                $basename = $f['basename'];
                $is_cover = ( $current_cover && $current_cover === $basename );

                echo '<li style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">';
                echo '<label style="display:flex; align-items:center; gap:10px; cursor:pointer;">';
                echo '<input type="radio" name="cgm_cover_image" value="' . esc_attr( $basename ) . '" ' . checked( $is_cover, true, false ) . ' />';
                echo '<img src="' . esc_url( $f['thumb_url'] ) . '" style="width:50px; height:auto; border:1px solid #ccc;" />';
                echo '<span>' . esc_html( $basename ) . '</span>';
                echo '</label>';
                echo '</li>';
            }

            echo '</ul>';

            echo '<p style="margin-top:6px;">';
            echo '<label><input type="radio" name="cgm_cover_image" value="" ' . checked( ! $current_cover, true, false ) . ' /> ';
            echo esc_html__( 'No cover image', 'client-gallery' ) . '</label>';
            echo '</p>';
        } else {
            echo '<p><em>No files yet.</em></p>';
        }

        // --- Folder name / slug preview ---

        $current_folder = get_post_meta( $post->ID, '_cgm_folder_name', true );
        if ( ! $current_folder ) {
            // show something sensible by default
            $current_folder = $post->post_name ? $post->post_name : (string) $post->ID;
        }
        $folder_slug = sanitize_title( $current_folder );

        echo '<hr />';

        echo '<p><label for="cgm_folder_name"><strong>' . esc_html__( 'Folder name', 'client-gallery' ) . '</strong></label></p>';
        echo '<p>';
        echo '<input type="text" id="cgm_folder_name" name="cgm_folder_name" value="' . esc_attr( $current_folder ) . '" style="width:100%;" />';
        echo '<br><span style="font-size:11px;color:#555;">';
        printf(
            esc_html__( 'Files will be read from: %s', 'client-gallery' ),
            '/client_galleries/' . $folder_slug . '/original'
        );
        echo '</span>';
        echo '</p>';

        // --- Sync button ---

        $sync_url = wp_nonce_url(
            add_query_arg(
                [
                    'action'     => 'cgm_sync_gallery',
                    'gallery_id' => $post->ID,
                ],
                admin_url( 'admin-post.php' )
            ),
            'cgm_sync_gallery_' . $post->ID,
            'cgm_sync_nonce'
        );

        echo '<p><a class="button" href="' . esc_url( $sync_url ) . '">Sync from Server Folder</a>';
        echo '<br><span style="font-size:11px;color:#555;">';
        esc_html_e( 'Upload via FTP/Samba into the folder shown above, then click Sync.', 'client-gallery' );
        echo '</span></p>';

        // --- Build / Rebuild ZIP button ---

        $zip_path = '';
        if ( class_exists( 'CGM_Gallery_Download' ) && method_exists( 'CGM_Gallery_Download', 'get_zip_path' ) ) {
            $zip_path = CGM_Gallery_Download::get_zip_path( $post->ID );
        }

        $build_zip_url = wp_nonce_url(
            add_query_arg(
                [
                    'action'     => 'cgm_build_zip',
                    'gallery_id' => $post->ID,
                ],
                admin_url( 'admin-post.php' )
            ),
            'cgm_build_zip_' . $post->ID,
            'cgm_build_zip_nonce'
        );

        echo '<p style="margin-top:10px;">';
        echo '<a class="button" href="' . esc_url( $build_zip_url ) . '">';
        echo esc_html__( 'Build / Rebuild Download ZIP', 'client-gallery' );
        echo '</a><br>';

        echo '<span style="font-size:11px;color:#555;">';
        if ( $zip_path && file_exists( $zip_path ) ) {
            echo esc_html__( 'A ZIP archive already exists and will be overwritten when rebuilding.', 'client-gallery' );
        } else {
            echo esc_html__( 'Generate a ZIP containing all originals for this gallery.', 'client-gallery' );
        }
        echo '</span></p>';
    }

    public static function render_schema_metabox( $post ) {
        $location    = get_post_meta( $post->ID, '_cgm_schema_location', true );
        $social_urls = get_post_meta( $post->ID, '_cgm_schema_social_urls', true );

        echo '<p>';
        echo '<label for="cgm_schema_location"><strong>Location / Venue</strong></label><br>';
        echo '<input type="text" id="cgm_schema_location" name="cgm_schema_location"'
            . ' value="' . esc_attr( $location ) . '" style="width:100%;"'
            . ' placeholder="e.g. ByWard Market" />';
        echo '<br><span style="font-size:11px;color:#555;">'
            . 'Where the photos were taken. Ottawa, ON, CA address is added automatically.'
            . '</span>';
        echo '</p>';

        $news_urls = get_post_meta( $post->ID, '_cgm_schema_news_urls', true );

        echo '<p>';
        echo '<label for="cgm_schema_social_urls"><strong>Social media appearances</strong></label><br>';
        echo '<textarea id="cgm_schema_social_urls" name="cgm_schema_social_urls"'
            . ' style="width:100%;height:90px;"'
            . ' placeholder="https://www.instagram.com/p/...' . "\n" . 'https://www.reddit.com/r/ottawa/...">'
            . esc_textarea( $social_urls )
            . '</textarea>';
        echo '<br><span style="font-size:11px;color:#555;">'
            . 'One URL per line. Instagram, Reddit, Facebook, etc.'
            . '</span>';
        echo '</p>';

        echo '<p>';
        echo '<label for="cgm_schema_news_urls"><strong>News / press coverage</strong></label><br>';
        echo '<textarea id="cgm_schema_news_urls" name="cgm_schema_news_urls"'
            . ' style="width:100%;height:90px;"'
            . ' placeholder="https://www.cbc.ca/...">'
            . esc_textarea( $news_urls )
            . '</textarea>';
        echo '<br><span style="font-size:11px;color:#555;">'
            . 'One URL per line. Articles, blog posts, press coverage featuring this gallery.'
            . '</span>';
        echo '</p>';
    }

    public static function save_gallery_meta( $post_id ) {

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( wp_is_post_revision( $post_id ) ) return;
        if ( ! isset( $_POST['cgm_gallery_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['cgm_gallery_nonce'], 'cgm_save_gallery_' . $post_id ) ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        // Handle uploads
        if ( isset( $_FILES['cgm_gallery_files'] ) && ! empty( $_FILES['cgm_gallery_files']['name'] ) ) {
            $files = $_FILES['cgm_gallery_files'];
            $count = count( $files['name'] );

            for ( $i = 0; $i < $count; $i++ ) {
                if ( empty( $files['name'][ $i ] ) ) continue;

                $single = [
                    'name'     => $files['name'][ $i ],
                    'type'     => $files['type'][ $i ],
                    'tmp_name' => $files['tmp_name'][ $i ],
                    'error'    => $files['error'][ $i ],
                    'size'     => $files['size'][ $i ],
                ];

                CGM_Gallery_Storage::add_uploaded_file( $post_id, $single );
            }
        }

        // Save cover image selection (basename from CGM_Gallery_Storage::get_files()).
        if ( isset( $_POST['cgm_cover_image'] ) ) {
            $cover = sanitize_text_field( wp_unslash( $_POST['cgm_cover_image'] ) );
            if ( $cover === '' ) {
                delete_post_meta( $post_id, '_cgm_cover_image' );
            } else {
                update_post_meta( $post_id, '_cgm_cover_image', $cover );
            }
        }

        // Save folder name (used for /client_galleries/{folder}/...).
        if ( isset( $_POST['cgm_folder_name'] ) ) {
            $folder_raw  = trim( wp_unslash( $_POST['cgm_folder_name'] ) );
            $folder_slug = sanitize_title( $folder_raw );

            if ( $folder_slug === '' ) {
                // If user clears it, fall back to slug or ID via get_gallery_paths().
                delete_post_meta( $post_id, '_cgm_folder_name' );
            } else {
                update_post_meta( $post_id, '_cgm_folder_name', $folder_slug );
            }
        }

        // Save schema location (venue name for contentLocation).
        if ( isset( $_POST['cgm_schema_location'] ) ) {
            $location = sanitize_text_field( wp_unslash( $_POST['cgm_schema_location'] ) );
            if ( $location === '' ) {
                delete_post_meta( $post_id, '_cgm_schema_location' );
            } else {
                update_post_meta( $post_id, '_cgm_schema_location', $location );
            }
        }

        // Save social appearance URLs (newline-separated, sanitized on output).
        if ( isset( $_POST['cgm_schema_social_urls'] ) ) {
            $urls_raw = sanitize_textarea_field( wp_unslash( $_POST['cgm_schema_social_urls'] ) );
            if ( $urls_raw === '' ) {
                delete_post_meta( $post_id, '_cgm_schema_social_urls' );
            } else {
                update_post_meta( $post_id, '_cgm_schema_social_urls', $urls_raw );
            }
        }

        // Save news / press coverage URLs.
        if ( isset( $_POST['cgm_schema_news_urls'] ) ) {
            $urls_raw = sanitize_textarea_field( wp_unslash( $_POST['cgm_schema_news_urls'] ) );
            if ( $urls_raw === '' ) {
                delete_post_meta( $post_id, '_cgm_schema_news_urls' );
            } else {
                update_post_meta( $post_id, '_cgm_schema_news_urls', $urls_raw );
            }
        }
    }

    /**
     * Handle Sync button.
     */
    public static function handle_sync_gallery() {

        if ( ! isset( $_GET['gallery_id'], $_GET['cgm_sync_nonce'] ) ) {
            wp_die( 'Missing parameters.' );
        }

        $gallery_id = intval( $_GET['gallery_id'] );

        if ( ! current_user_can( 'edit_post', $gallery_id ) ) {
            wp_die( 'Permission denied.' );
        }

        if ( ! wp_verify_nonce( $_GET['cgm_sync_nonce'], 'cgm_sync_gallery_' . $gallery_id ) ) {
            wp_die( 'Nonce error.' );
        }

        $processed = CGM_Gallery_Storage::sync_from_original( $gallery_id );

        $redirect = add_query_arg(
            [
                'post'             => $gallery_id,
                'action'           => 'edit',
                'cgm_synced'       => 1,
                'cgm_synced_count' => $processed,
            ],
            admin_url( 'post.php' )
        );

        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Success notice after syncing.
     */
    public static function maybe_show_sync_notice() {

        if ( ! isset( $_GET['cgm_synced'], $_GET['cgm_synced_count'], $_GET['post'] ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || $screen->base !== 'post' ) return;

        $count = intval( $_GET['cgm_synced_count'] );

        echo '<div class="notice notice-success is-dismissible">';
        printf( '<p>Synced from server folder: generated %d thumbnail(s).</p>', $count );
        echo '</div>';
    }

    /**
     * Handle "Build ZIP" button click.
     */
    public static function handle_build_zip() {

        if ( ! isset( $_GET['gallery_id'], $_GET['cgm_build_zip_nonce'] ) ) {
            wp_die( 'Missing parameters.' );
        }

        $gallery_id = intval( $_GET['gallery_id'] );

        if ( ! current_user_can( 'edit_post', $gallery_id ) ) {
            wp_die( 'Permission denied.' );
        }

        if ( ! wp_verify_nonce( $_GET['cgm_build_zip_nonce'], 'cgm_build_zip_' . $gallery_id ) ) {
            wp_die( 'Nonce error.' );
        }

        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }

        if ( ! class_exists( 'CGM_Gallery_Download' ) ) {
            $status  = 'error';
            $message = 'Download handler class is missing.';
        } else {
            $result = CGM_Gallery_Download::generate_zip( $gallery_id );

            if ( is_wp_error( $result ) ) {
                $status  = 'error';
                $message = $result->get_error_message();
            } else {
                $status  = 'success';
                $message = 'Download ZIP created successfully.';
            }
        }

        $redirect = add_query_arg(
            [
                'post'           => $gallery_id,
                'action'         => 'edit',
                'cgm_zip_status' => $status,
                'cgm_zip_msg'    => rawurlencode( $message ),
            ],
            admin_url( 'post.php' )
        );

        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Admin notice after (re)building ZIP.
     */
    public static function maybe_show_zip_notice() {

        if ( ! isset( $_GET['cgm_zip_status'], $_GET['cgm_zip_msg'], $_GET['post'] ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || $screen->base !== 'post' ) {
            return;
        }

        $status  = sanitize_text_field( wp_unslash( $_GET['cgm_zip_status'] ) );
        $message = rawurldecode( sanitize_text_field( wp_unslash( $_GET['cgm_zip_msg'] ) ) );

        $class = ( $status === 'success' ) ? 'notice-success' : 'notice-error';

        echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible">';
        echo '<p>' . esc_html( $message ) . '</p>';
        echo '</div>';
    }
}
