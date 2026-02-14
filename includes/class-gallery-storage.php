<?php
/**
 * Storage layer for Client Gallery Manager.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CGM_Gallery_Storage {

    /**
     * Optional watermark text for thumbnails.
     * Change this to whatever you like, or set to '' to disable.
     */
    const WATERMARK_TEXT = 'Parfocal Media';

    /**
     * Boot storage-layer front-end endpoints.
     */
    public static function init() {
        add_action( 'template_redirect', [ __CLASS__, 'handle_thumb_request' ] );
    }

    /**
     * Serve thumbnail images with access control.
     *
     * URL format:
     *   /?cgm_thumb=1&gallery_id=123&file=image.jpg
     */
    public static function handle_thumb_request() {
        if ( empty( $_GET['cgm_thumb'] ) ) {
            return;
        }

        $gallery_id = isset( $_GET['gallery_id'] ) ? (int) $_GET['gallery_id'] : 0;

        // Do NOT sanitize into a different filename; decode and basename it safely.
        $file = isset( $_GET['file'] ) ? (string) wp_unslash( $_GET['file'] ) : '';
        $file = rawurldecode( $file );
        $file = wp_basename( $file ); // prevents path traversal without renaming

        // Hard block traversal/control chars.
        if ( $gallery_id <= 0 || $file === '' || strpos( $file, '..' ) !== false || strpos( $file, '/' ) !== false || strpos( $file, '\\' ) !== false || preg_match( '/[\x00-\x1F\x7F]/', $file ) ) {
            self::output_placeholder_image( false );
            exit;
        }

        // Access control (view permission).
        if ( class_exists( 'CGM_Gallery_Access' ) && method_exists( 'CGM_Gallery_Access', 'user_can_view' ) ) {
            if ( ! CGM_Gallery_Access::user_can_view( $gallery_id ) ) {
                self::maybe_redirect_or_placeholder();
                exit;
            }
        }

        $paths     = self::get_gallery_paths( $gallery_id );
        $thumbs_dir = trailingslashit( $paths['thumbs'] );

        // Try exact thumb first.
        $thumb = $thumbs_dir . $file . '.webp';

        if ( ! file_exists( $thumb ) ) {
            // Fallback: match auto-numbered thumbs (e.g. grace-harlie--97.jpg.webp).
            $pattern = $thumbs_dir . pathinfo( $file, PATHINFO_FILENAME ) . '*.webp';
            $matches = glob( $pattern );

            if ( ! empty( $matches ) && file_exists( $matches[0] ) ) {
                $thumb = $matches[0];
            }
        }

        if ( ! file_exists( $thumb ) ) {
            self::output_placeholder_image( false );
            exit;
        }

        $is_private = class_exists( 'CGM_Gallery_Access' )
            && method_exists( 'CGM_Gallery_Access', 'gallery_is_private' )
            && CGM_Gallery_Access::gallery_is_private( $gallery_id );

        header( 'Content-Type: image/webp' );
        header( 'Content-Length: ' . (string) filesize( $thumb ) );
        header( $is_private
            ? 'Cache-Control: private, no-store'
            : 'Cache-Control: public, max-age=86400'
        );

        readfile( $thumb );
        exit;
    }


    /**
     * If unauthorized: return an image placeholder for <img> requests,
     * otherwise redirect to a friendly "private image" page.
     */
    protected static function maybe_redirect_or_placeholder() {
        // If the client accepts images, it's probably an <img> tag -> return image bytes.
        $accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? (string) $_SERVER['HTTP_ACCEPT'] : '';
        if ( $accept && strpos( $accept, 'image' ) !== false ) {
            self::output_placeholder_image( true );
            exit;
        }

        // Otherwise, redirect to a friendly info page (you create this page with slug 'private-image').
        $private_page = get_page_by_path( 'private-image' );
        if ( $private_page instanceof WP_Post ) {
            wp_safe_redirect( get_permalink( $private_page ), 302 );
            exit;
        }

        // Fallback: if page doesn't exist, still return placeholder (safe).
        self::output_placeholder_image( true );
        exit;
    }

    /**
     * Output a placeholder image (SVG) instead of HTML.
     */
    protected static function output_placeholder_image( $private = false ) {
        header( 'Content-Type: image/svg+xml; charset=utf-8' );
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
        header( 'Pragma: no-cache' );

        // Critical: stop image indexing.
        header( 'X-Robots-Tag: noimageindex, noindex, nofollow' );

        $label = $private ? 'Private image' : 'Image unavailable';

        // Simple SVG placeholder.
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="300" viewBox="0 0 400 300" role="img" aria-label="' . esc_attr( $label ) . '">
  <rect width="400" height="300" fill="#e5e7eb"/>
  <text x="200" y="155" text-anchor="middle" font-family="sans-serif" font-size="18" fill="#6b7280">' . esc_html( $label ) . '</text>
</svg>';
        exit;
    }


    /**
     * Build absolute paths for a gallery.
     *
     * Uses a manually set folder name if available, otherwise falls back to
     * the post slug, then the numeric ID.
     */
    protected static function get_gallery_paths( $gallery_id ) {
        $gallery_id = intval( $gallery_id );

        $folder = '';

        if ( $gallery_id ) {
            // 1) Manually set folder name (meta).
            $folder = get_post_meta( $gallery_id, '_cgm_folder_name', true );

            // 2) Fallback: use the post slug.
            if ( ! $folder ) {
                $post = get_post( $gallery_id );
                if ( $post && ! empty( $post->post_name ) ) {
                    $folder = $post->post_name;
                }
            }
        }

        // 3) Last-resort fallback: numeric ID.
        if ( ! $folder ) {
            $folder = (string) $gallery_id;
        }

        // Make sure folder is filesystem-safe.
        $folder = sanitize_title( $folder );

        $base = trailingslashit( CGM_STORAGE_PATH ) . $folder;

        return [
            'base'     => $base,
            'original' => $base . '/original',
            'thumbs'   => $base . '/thumbs',
        ];
    }

    /**
     * Public helper: original directory path for a gallery.
     */
    public static function original_dir( $gallery_id ) {
        $paths = self::get_gallery_paths( $gallery_id );
        return trailingslashit( $paths['original'] );
    }

    /**
     * Ensure gallery directories exist.
     */
    protected static function ensure_gallery_dirs( $gallery_id ) {
        $paths = self::get_gallery_paths( $gallery_id );

        foreach ( [ $paths['base'], $paths['original'], $paths['thumbs'] ] as $dir ) {
            if ( ! is_dir( $dir ) ) {
                if ( ! wp_mkdir_p( $dir ) ) {
                    return new WP_Error(
                        'cgm_dir_create_failed',
                        sprintf( 'Failed to create directory: %s', $dir )
                    );
                }
            }
        }

        return true;
    }

    /**
     * Handle a single uploaded file (HTTP upload).
     */
    public static function add_uploaded_file( $gallery_id, $file ) {
        $ensure = self::ensure_gallery_dirs( $gallery_id );
        if ( is_wp_error( $ensure ) ) {
            return $ensure;
        }

        if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
            return new WP_Error( 'cgm_invalid_upload', 'Invalid uploaded file.' );
        }

        if ( ! empty( $file['error'] ) && intval( $file['error'] ) !== UPLOAD_ERR_OK ) {
            return new WP_Error(
                'cgm_upload_error',
                'Upload error code: ' . intval( $file['error'] )
            );
        }

        $paths    = self::get_gallery_paths( $gallery_id );
        $original = trailingslashit( $paths['original'] );

        $safe_name = sanitize_file_name( $file['name'] );
        if ( $safe_name === '' ) {
            $safe_name = 'file';
        }

        $info     = pathinfo( $safe_name );
        $basename = $info['filename'];
        $ext      = isset( $info['extension'] ) ? $info['extension'] : '';

        // Protect against duplicate names by auto-numbering.
        $candidate = $ext ? "$basename.$ext" : $basename;
        $target    = $original . $candidate;
        $suffix    = 1;

        while ( file_exists( $target ) ) {
            $candidate = $ext
                ? sprintf( '%s-%d.%s', $basename, $suffix, $ext )
                : sprintf( '%s-%d', $basename, $suffix );
            $target = $original . $candidate;
            $suffix++;
        }

        // Move uploaded file.
        if ( ! @move_uploaded_file( $file['tmp_name'], $target ) ) {
            return new WP_Error(
                'cgm_move_failed',
                'Failed to move uploaded file.'
            );
        }

        // Make thumbnail.
        $thumbs_dir = trailingslashit( $paths['thumbs'] );
        $thumb_path = $thumbs_dir . $candidate . '.webp';

        $thumb = self::generate_thumbnail( $target, $thumb_path );
        if ( is_wp_error( $thumb ) ) {
            error_log( 'CGM thumbnail error: ' . $thumb->get_error_message() );
        }

        return true;
    }

    /**
     * Generate thumbnail WebP with watermark.
     */
    protected static function generate_thumbnail( $source_path, $dest_path ) {
        if ( ! file_exists( $source_path ) ) {
            return new WP_Error( 'cgm_thumb_source_missing', 'Source file missing.' );
        }

        $info = getimagesize( $source_path );
        if ( ! $info ) {
            return new WP_Error( 'cgm_thumb_not_image', 'Not an image.' );
        }

        $mime = $info['mime'];

        switch ( $mime ) {
            case 'image/jpeg':
                $src = imagecreatefromjpeg( $source_path );
                break;
            case 'image/png':
                $src = imagecreatefrompng( $source_path );
                break;
            case 'image/webp':
                $src = function_exists( 'imagecreatefromwebp' )
                    ? imagecreatefromwebp( $source_path )
                    : null;
                break;
            default:
                return new WP_Error( 'cgm_thumb_bad_mime', "Unsupported mime: $mime" );
        }

        if ( ! $src ) {
            return new WP_Error( 'cgm_thumb_create_failed', 'GD failed to load image.' );
        }

        $orig_w = imagesx( $src );
        $orig_h = imagesy( $src );

        $target_w = 1080;

        // Do not upscale smaller images
        if ( $orig_w <= $target_w ) {
            $new_w = $orig_w;
            $new_h = $orig_h;
        } else {
            $scale = $target_w / $orig_w;
            $new_w = $target_w;
            $new_h = max( 1, intval( $orig_h * $scale ) );
        }

        $dst = imagecreatetruecolor( $new_w, $new_h );
        imagecopyresampled( $dst, $src, 0, 0, 0, 0, $new_w, $new_h, $orig_w, $orig_h );

        // 🔹 Apply watermark – prefer constant, fallback to site name.
        $watermark_text = self::WATERMARK_TEXT !== ''
            ? self::WATERMARK_TEXT
            : get_bloginfo( 'name' );

        self::apply_watermark( $dst, $watermark_text );

        // Ensure destination directory exists before writing.
        $dir = dirname( $dest_path );
        if ( ! is_dir( $dir ) ) {
            if ( ! wp_mkdir_p( $dir ) ) {
                imagedestroy( $src );
                imagedestroy( $dst );
                return new WP_Error(
                    'cgm_thumb_dest_dir_failed',
                    sprintf( 'Failed to create thumbnail directory: %s', $dir )
                );
            }
        }

        imagewebp( $dst, $dest_path, 80 );

        imagedestroy( $src );
        imagedestroy( $dst );

        return true;
    }

    /**
     * Apply a text watermark using Josefin Sans if available,
     * otherwise fall back to a built-in GD bitmap font.
     */
    protected static function apply_watermark( $dst, $text ) {
        if ( ! $dst || ! $text ) {
            return;
        }

        $w = imagesx( $dst );
        $h = imagesy( $dst );

        // Skip very small thumbs.
        if ( $w < 200 || $h < 150 ) {
            return;
        }

        // Common padding from edges.
        $pad = max( 10, intval( $w * 0.02 ) );

        // Try TTF (Josefin Sans) first.
        $font_path = CGM_PLUGIN_PATH . 'fonts/JosefinSans-VariableFont_wght.ttf';
        $can_ttf   = function_exists( 'imagettftext' ) && file_exists( $font_path );

        if ( $can_ttf ) {
            // Josefin Sans watermark.
            $font_size = max( 12, intval( $w * 0.035 ) ); // ~3.5% of width.
            $angle     = 0;

            // Semi-transparent white text.
            $color = imagecolorallocatealpha( $dst, 255, 255, 255, 70 ); // 0=opaque, 127=transparent.

            // Measure text box.
            $bbox = imagettfbbox( $font_size, $angle, $font_path, $text );
            $text_width  = abs( $bbox[4] - $bbox[0] );
            $text_height = abs( $bbox[5] - $bbox[1] );

            // Bottom-right.
            $x = $w - $text_width - $pad;
            $y = $h - $pad;

            imagettftext(
                $dst,
                $font_size,
                $angle,
                $x,
                $y,
                $color,
                $font_path,
                $text
            );
            return;
        }

        // 🔙 Fallback: built-in GD bitmap font.
        $font = 3; // GD internal font size (1–5).
        $fw   = imagefontwidth( $font );
        $fh   = imagefontheight( $font );
        $len  = strlen( $text );

        $text_width  = $fw * $len;
        $text_height = $fh;

        $x = $w - $text_width - $pad;
        $y = $h - $text_height - $pad;

        $color = imagecolorallocate( $dst, 255, 255, 255 );
        imagestring( $dst, $font, $x, $y, $text, $color );
    }

    /**
     * List all original files for a gallery.
     *
     * Returns an array of:
     *  - basename
     *  - original_path
     *  - thumb_path
     *  - download_url (single file)
     *  - thumb_url    (thumbnail)
     */
    public static function get_files( $gallery_id ) {
        $paths    = self::get_gallery_paths( $gallery_id );
        $original = trailingslashit( $paths['original'] );
        $thumbs   = trailingslashit( $paths['thumbs'] );

        if ( ! is_dir( $original ) ) {
            return [];
        }

        $list = scandir( $original );
        if ( ! $list ) {
            return [];
        }

        $out = [];

        foreach ( $list as $file ) {
            if ( $file === '.' || $file === '..' ) {
                continue;
            }

            $full = $original . $file;
            if ( ! is_file( $full ) ) {
                continue;
            }

            $thumb_file = $file . '.webp';
            $thumb_full = $thumbs . $thumb_file;

            // If thumbnail doesn't exist yet, try generating it now.
            if ( ! file_exists( $thumb_full ) ) {
                $thumb_result = self::generate_thumbnail( $full, $thumb_full );
                if ( is_wp_error( $thumb_result ) ) {
                    error_log( 'CGM thumbnail regeneration error: ' . $thumb_result->get_error_message() );
                    continue;
                }
            }

            // Only proceed if both original + thumb exist on disk.
            if ( ! file_exists( $full ) || ! file_exists( $thumb_full ) ) {
                continue;
            }

            // Browser URLs go through admin-post endpoints.
            // Thumbnails should NOT use admin-post (SEO + crawlers). Serve via a front-end endpoint.
            $thumb_url = add_query_arg(
                [
                    'cgm_thumb'  => 1,
                    'gallery_id' => (int) $gallery_id,
                    'file'       => rawurlencode( $file ),
                ],
                home_url( '/' )
            );


            $download_url = add_query_arg(
                [
                    'action'     => 'cgm_download',
                    'gallery_id' => (int) $gallery_id,
                    'file'       => $file,
                ],
                admin_url( 'admin-post.php' )
            );

            $out[] = [
                'basename'      => $file,
                'original_path' => $full,
                'thumb_path'    => $thumb_full,
                'download_url'  => $download_url,
                'thumb_url'     => $thumb_url,
            ];
        }

        return $out;
    }

    /**
     * Scan original/ folder, generate missing thumbs.
     *
     * This is used by the "Sync from Server Folder" button in the admin.
     * It is time-budgeted to avoid hitting PHP max_execution_time for very
     * large galleries; you can re-click sync to continue where it left off.
     */
    public static function sync_from_original( $gallery_id ) {
        $paths  = self::get_gallery_paths( $gallery_id );
        $orig   = trailingslashit( $paths['original'] );
        $thumbs = trailingslashit( $paths['thumbs'] );

        $ensure = self::ensure_gallery_dirs( $gallery_id );
        if ( is_wp_error( $ensure ) ) {
            return 0;
        }

        if ( ! is_dir( $orig ) ) {
            return 0;
        }

        $files = scandir( $orig );
        if ( ! is_array( $files ) ) {
            return 0;
        }

        $count      = 0;
        $start_time = microtime( true );
        $max_time   = (int) ini_get( 'max_execution_time' );
        if ( $max_time <= 0 ) {
            $max_time = 30; // sensible default
        }
        $budget = $max_time * 0.8; // leave a bit of headroom

        foreach ( $files as $file ) {
            if ( $file === '.' || $file === '..' ) {
                continue;
            }

            $source = $orig . $file;
            if ( ! is_file( $source ) ) {
                continue;
            }

            $thumb_path = $thumbs . $file . '.webp';

            if ( file_exists( $thumb_path ) ) {
                // already has a thumb; skip.
                continue;
            }

            $result = self::generate_thumbnail( $source, $thumb_path );
            if ( ! is_wp_error( $result ) ) {
                $count++;
            } else {
                error_log( 'CGM sync_from_original thumb error: ' . $result->get_error_message() );
            }

            // Time-budget guard.
            $elapsed = microtime( true ) - $start_time;
            if ( $elapsed > $budget ) {
                break;
            }
        }

        return $count;
    }
}
