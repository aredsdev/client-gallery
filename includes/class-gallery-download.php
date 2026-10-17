<?php
/**
 * File download and thumbnail endpoints for Client Gallery Manager.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CGM_Gallery_Download {

    public static function init() {
        // Thumbnails
        add_action( 'admin_post_nopriv_cgm_thumb', [ __CLASS__, 'handle_thumb' ] );
        add_action( 'admin_post_cgm_thumb',        [ __CLASS__, 'handle_thumb' ] );

        // NEW: public cover thumbnail (index only)
        add_action( 'admin_post_nopriv_cgm_cover_thumb', [ __CLASS__, 'handle_cover_thumb' ] );
        add_action( 'admin_post_cgm_cover_thumb',        [ __CLASS__, 'handle_cover_thumb' ] );

        // Single-file download
        add_action( 'admin_post_nopriv_cgm_download', [ __CLASS__, 'handle_download' ] );
        add_action( 'admin_post_cgm_download',        [ __CLASS__, 'handle_download' ] );

        // Download all as ZIP
        add_action( 'admin_post_nopriv_cgm_download_all', [ __CLASS__, 'handle_download_all' ] );
        add_action( 'admin_post_cgm_download_all',        [ __CLASS__, 'handle_download_all' ] );
    }

    /**
     * Stream a thumbnail image to the browser.
     */
    public static function handle_thumb() {
        $gallery_id = isset( $_GET['gallery_id'] ) ? (int) $_GET['gallery_id'] : 0;
        $file       = isset( $_GET['file'] ) ? rawurldecode( $_GET['file'] ) : '';

        if ( ! $gallery_id || ! $file ) {
            status_header( 400 );
            exit;
        }

        // 🔐 Access check: thumbnails follow viewing rules.
        if ( get_post_type( $gallery_id ) !== 'client_gallery' ) {
            status_header( 404 );
            exit;
        }
        if ( class_exists( 'CGM_Gallery_Access' ) && ! CGM_Gallery_Access::user_can_view( $gallery_id ) ) {
            status_header( 403 );
            exit;
        }

        $files      = CGM_Gallery_Storage::get_files( $gallery_id );
        $thumb_path = null;

        foreach ( $files as $f ) {
            if ( isset( $f['basename'], $f['thumb_path'] ) && $f['basename'] === $file ) {
                $thumb_path = $f['thumb_path'];
                break;
            }
        }

        if ( ! $thumb_path || ! file_exists( $thumb_path ) ) {
            status_header( 404 );
            exit;
        }

        $ext = strtolower( pathinfo( $thumb_path, PATHINFO_EXTENSION ) );

        if ( $ext === 'webp' ) {
            header( 'Content-Type: image/webp' );
        } elseif ( $ext === 'png' ) {
            header( 'Content-Type: image/png' );
        } else {
            header( 'Content-Type: image/jpeg' );
        }

        header( 'Content-Length: ' . filesize( $thumb_path ) );
        // 1 day, not 1 year - matches CGM_Gallery_Storage's public-thumb cache lifetime.
        // A year-long cache with no revalidation meant a visitor who ever loaded a
        // broken/missing thumb once (e.g. before cgm-thumbd had generated it yet) would
        // keep seeing that broken result in their own browser for up to a year, even
        // after the server started serving a good file.
        header( 'Cache-Control: public, max-age=86400' );

        readfile( $thumb_path );
        exit;
    }

    /**
     * Public cover thumbnail for gallery index.
     * Only ever serves the designated cover image thumb.
     */
    public static function handle_cover_thumb() {
        $gallery_id = isset( $_GET['gallery_id'] ) ? (int) $_GET['gallery_id'] : 0;

        if ( ! $gallery_id || get_post_type( $gallery_id ) !== 'client_gallery' ) {
            status_header( 404 );
            exit;
        }

        // Read the stored cover basename (radio in the editor).
        $cover_basename = get_post_meta( $gallery_id, '_cgm_cover_image', true );
        if ( ! $cover_basename ) {
            status_header( 404 );
            exit;
        }

        // Find that file in storage (so we can get its thumb path).
        $files      = CGM_Gallery_Storage::get_files( $gallery_id );
        $thumb_path = null;

        foreach ( $files as $f ) {
            if ( isset( $f['basename'], $f['thumb_path'] ) && $f['basename'] === $cover_basename ) {
                $thumb_path = $f['thumb_path'];
                break;
            }
        }

        if ( ! $thumb_path || ! file_exists( $thumb_path ) ) {
            status_header( 404 );
            exit;
        }

        $ext = strtolower( pathinfo( $thumb_path, PATHINFO_EXTENSION ) );

        if ( $ext === 'webp' ) {
            header( 'Content-Type: image/webp' );
        } elseif ( $ext === 'png' ) {
            header( 'Content-Type: image/png' );
        } else {
            header( 'Content-Type: image/jpeg' );
        }

        header( 'Content-Length: ' . filesize( $thumb_path ) );
        // 1 day, not 1 year - matches CGM_Gallery_Storage's public-thumb cache lifetime.
        // A year-long cache with no revalidation meant a visitor who ever loaded a
        // broken/missing thumb once (e.g. before cgm-thumbd had generated it yet) would
        // keep seeing that broken result in their own browser for up to a year, even
        // after the server started serving a good file.
        header( 'Cache-Control: public, max-age=86400' );

        readfile( $thumb_path );
        exit;
    }

    /**
     * Stream a single original file as an attachment.
     */
    public static function handle_download() {

        $gallery_id = isset( $_GET['gallery_id'] ) ? (int) $_GET['gallery_id'] : 0;
        $file       = isset( $_GET['file'] ) ? rawurldecode( $_GET['file'] ) : '';

        if ( ! $gallery_id || ! $file ) {
            status_header( 400 );
            exit;
        }

        if ( get_post_type( $gallery_id ) !== 'client_gallery' ) {
            status_header( 404 );
            exit;
        }

        if ( class_exists( 'CGM_Gallery_Access' ) && ! CGM_Gallery_Access::user_can_download( $gallery_id ) ) {
            status_header( 403 );
            exit;
        }

        // Resolve the requested file the SAME way ZIP does: from storage metadata.
        $requested = basename( $file );
        $files     = CGM_Gallery_Storage::get_files( $gallery_id );

        $path = '';
        $basename = '';

        foreach ( $files as $f ) {
            if ( empty( $f['basename'] ) || empty( $f['original_path'] ) ) {
                continue;
            }

            if ( $f['basename'] === $requested ) {
                $path     = $f['original_path'];
                $basename = $f['basename'];
                break;
            }
        }

        if ( ! $path || ! file_exists( $path ) || ! is_readable( $path ) ) {
            status_header( 404 );
            exit;
        }

        // Clear buffers (critical).
        while ( ob_get_level() ) {
            ob_end_clean();
        }

        // Disable compression (helps avoid corrupted output / blank responses).
        if ( function_exists( 'apache_setenv' ) ) {
            @apache_setenv( 'no-gzip', '1' );
        }
        @ini_set( 'zlib.output_compression', 'Off' );
        if ( function_exists( 'header_remove' ) ) {
            @header_remove( 'Content-Encoding' );
        }

        $download_name = sanitize_file_name( $basename );
        $filesize      = filesize( $path );

        // iOS: an attachment always lands in the Files app. Photos is only offered
        // (long-press > Save to Photos) for a real image shown inline. The JS adds
        // ?inline=1 on iOS (page wrapper with instructions) and the wrapper's <img>
        // requests ?raw=1 (the image itself, Content-Disposition: inline).
        $image_types = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
        ];
        $ext        = strtolower( pathinfo( $basename, PATHINFO_EXTENSION ) );
        $image_type = isset( $image_types[ $ext ] ) ? $image_types[ $ext ] : '';

        header( 'CDN-Cache-Control: no-store' );
        header( 'Cloudflare-CDN-Cache-Control: no-store' );
        header( 'X-Content-Type-Options: nosniff' );

        if ( $image_type && isset( $_GET['inline'] ) ) {
            $query = wp_unslash( $_GET );
            unset( $query['inline'] );
            $query['raw'] = 1;
            $img_src      = admin_url( 'admin-post.php' ) . '?' . http_build_query( $query );
            $back_url     = get_permalink( $gallery_id );

            header( 'Content-Type: text/html; charset=utf-8' );
            header( 'X-Robots-Tag: noindex, nofollow' );
            header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
            ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Save photo</title>
<style>
html,body{margin:0;background:#111;color:#fff;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;text-align:center}
.bar{padding:14px 16px;font-size:16px;line-height:1.4;background:#222}
.bar strong{display:block;font-size:18px;margin-bottom:2px}
.back{display:inline-block;margin-top:8px;color:#9cf;font-size:15px}
img{display:block;max-width:100%;height:auto;margin:0 auto}
</style>
</head>
<body>
<div class="bar">
<strong>Press and hold the photo, then tap &ldquo;Save to Photos&rdquo;</strong>
(it may say &ldquo;Add to Photos&rdquo; on some devices)
<br><a class="back" href="<?php echo esc_url( $back_url ); ?>" onclick="if(history.length>1){history.back();return false;}">&larr; Back to gallery</a>
</div>
<img src="<?php echo esc_url( $img_src ); ?>" alt="">
</body>
</html>
            <?php
            exit;
        }

        if ( $image_type && isset( $_GET['raw'] ) ) {
            header( 'Content-Type: ' . $image_type );
            header( 'Content-Disposition: inline; filename="' . $download_name . '"' );
        } else {
            header( 'Content-Type: application/octet-stream' );
            header( 'Content-Disposition: attachment; filename="' . $download_name . '"; filename*=UTF-8\'\'' . rawurlencode( $download_name ) );
        }
        header( 'Content-Length: ' . $filesize );
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
        header( 'Pragma: public' );
        header( 'Expires: 0' );

        $fp = fopen( $path, 'rb' );
        if ( $fp ) {
            while ( ! feof( $fp ) ) {
                echo fread( $fp, 8192 );
                flush();
            }
            fclose( $fp );
        }

        exit;
    }



    /**
     * Helper: path to the persistent ZIP on disk for a gallery.
     */
    public static function get_zip_path( $gallery_id ) {
        $gallery_id = (int) $gallery_id;

        // Use original_dir() and go one level up for "base".
        $orig_dir = CGM_Gallery_Storage::original_dir( $gallery_id );
        $base_dir = trailingslashit( dirname( rtrim( $orig_dir, '/\\' ) ) );

        $download_name = sanitize_title( get_the_title( $gallery_id ) );
        if ( ! $download_name ) {
            $download_name = 'gallery-' . $gallery_id;
        }

        return $base_dir . $download_name . '.zip';
    }

    /**
     * Build/overwrite the ZIP for a gallery and return the ZIP path or WP_Error.
     */
    public static function generate_zip( $gallery_id, $zip_path = null ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return new WP_Error( 'cgm_zip_no_ziparchive', 'ZipArchive is not available on this server.' );
        }

        $gallery_id = (int) $gallery_id;

        if ( ! $gallery_id || get_post_type( $gallery_id ) !== 'client_gallery' ) {
            return new WP_Error( 'cgm_zip_bad_gallery', 'Invalid gallery ID.' );
        }

        $files = CGM_Gallery_Storage::get_files( $gallery_id );
        if ( empty( $files ) ) {
            return new WP_Error( 'cgm_zip_no_files', 'No files to include in the ZIP.' );
        }

        if ( null === $zip_path ) {
            $zip_path = self::get_zip_path( $gallery_id );
        }

        $zip_dir = dirname( $zip_path );
        if ( ! wp_mkdir_p( $zip_dir ) ) {
            return new WP_Error( 'cgm_zip_dir_fail', 'Failed to create directory for ZIP.' );
        }

        // Allow long-running zip builds when triggered by admin / front end.
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }

        $zip = new ZipArchive();
        if ( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
            return new WP_Error( 'cgm_zip_open_fail', 'Could not create ZIP archive.' );
        }

        foreach ( $files as $file ) {
            if ( empty( $file['original_path'] ) || empty( $file['basename'] ) ) {
                continue;
            }

            $orig_path = $file['original_path'];
            $basename  = $file['basename'];

            if ( file_exists( $orig_path ) ) {
                $zip->addFile( $orig_path, $basename );
            }
        }

        $zip->close();

        if ( ! file_exists( $zip_path ) ) {
            return new WP_Error( 'cgm_zip_not_created', 'ZIP file was not created properly.' );
        }

        return $zip_path;
    }

    /**
     * Download-all endpoint: stream persistent ZIP (build on demand if missing).
     */
    public static function handle_download_all() {
        $gallery_id = isset( $_GET['gallery_id'] ) ? (int) $_GET['gallery_id'] : 0;

        if ( ! $gallery_id ) {
            status_header( 400 );
            exit;
        }

        // 🔐 Access check
        if ( get_post_type( $gallery_id ) !== 'client_gallery' ) {
            status_header( 404 );
            exit;
        }
        if ( class_exists( 'CGM_Gallery_Access' ) && ! CGM_Gallery_Access::user_can_download( $gallery_id ) ) {
            status_header( 403 );
            exit;
        }

        // Compute path to persistent ZIP
        $zip_path = self::get_zip_path( $gallery_id );

        // If ZIP does not exist yet, build it once.
        if ( ! file_exists( $zip_path ) ) {
            $result = self::generate_zip( $gallery_id, $zip_path );
            if ( is_wp_error( $result ) ) {
                wp_die( esc_html( $result->get_error_message() ) );
            }
        }

        if ( ! file_exists( $zip_path ) ) {
            wp_die( 'ZIP file not found.' );
        }

        $download_name = basename( $zip_path );

        while ( ob_get_level() ) {
            ob_end_clean();
        }

        header( 'CDN-Cache-Control: no-store' );
        header( 'Cloudflare-CDN-Cache-Control: no-store' );

        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="' . $download_name . '"' );
        header( 'Content-Length: ' . filesize( $zip_path ) );
        header( 'Cache-Control: private' );

        readfile( $zip_path );
        exit;
    }
}

/**
 * Helper for building the download-all URL for a gallery.
 *
 * If the current request holds a URL download grant (cookie-dropping
 * WebView flow), it rides along so the ZIP request isn't denied.
 */
function cgm_download_all_url( $gallery_id ) {
    $url = add_query_arg(
        [
            'action'     => 'cgm_download_all',
            'gallery_id' => (int) $gallery_id,
        ],
        admin_url( 'admin-post.php' )
    );

    if ( class_exists( 'CGM_Gallery_Access' )
        && method_exists( 'CGM_Gallery_Access', 'active_download_grant' ) ) {
        $grant = CGM_Gallery_Access::active_download_grant( (int) $gallery_id );
        if ( $grant ) {
            $url = add_query_arg(
                CGM_Gallery_Access::GRANT_PARAM_DOWNLOAD,
                rawurlencode( $grant ),
                $url
            );
        }
    }

    return $url;
}
