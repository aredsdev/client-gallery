<?php
/**
 * Image sitemap for Client Gallery Manager.
 *
 * Serves /cgm-images.xml listing every PUBLIC gallery page with an
 * <image:image> entry per photo, so Google Images can discover the
 * watermarked gallery thumbs at scale (they render in-page via query-string
 * URLs that Google otherwise only finds by crawling each gallery page).
 *
 * NOTE the filename deliberately does NOT end in "-sitemap.xml": Yoast
 * registers a rewrite `([^/]+?)-sitemap([0-9]+)?\.xml$` that swallows any
 * such URL before this plugin's rule can match (confirmed live 2026-07-08 —
 * /cgm-image-sitemap.xml 404'd through Yoast while the query-string
 * fallback worked).
 *
 * Private (password-protected) galleries are excluded entirely — they are
 * noindex'd and their images must not be advertised.
 *
 * Image URLs listed here are byte-identical to the on-page <img src> URLs
 * (the WebP-serving cgm_thumb endpoint, no format param) so the sitemap
 * matches what the crawler sees in the HTML.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CGM_Gallery_Sitemap {

    const QUERY_VAR = 'cgm_image_sitemap';

    public static function init() {
        add_action( 'init', [ __CLASS__, 'register_rewrite' ] );
        add_filter( 'query_vars', [ __CLASS__, 'register_query_var' ] );
        add_action( 'template_redirect', [ __CLASS__, 'maybe_render' ], 0 );
        // Advertise alongside WP's robots.txt output (only affects WP-generated robots.txt).
        add_filter( 'robots_txt', [ __CLASS__, 'append_to_robots' ], 20, 1 );
    }

    public static function register_rewrite() {
        add_rewrite_rule(
            '^cgm-images\.xml$',
            'index.php?' . self::QUERY_VAR . '=1',
            'top'
        );
    }

    public static function register_query_var( $vars ) {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    public static function append_to_robots( $output ) {
        $output .= "\nSitemap: " . home_url( '/cgm-images.xml' ) . "\n";
        return $output;
    }

    /**
     * Render the sitemap XML when requested.
     *
     * Supports both the pretty URL (rewrite) and the raw query string
     * (?cgm_image_sitemap=1) as a fallback if rewrites are stale.
     */
    public static function maybe_render() {
        $via_rewrite = (string) get_query_var( self::QUERY_VAR ) === '1';
        $via_query   = isset( $_GET[ self::QUERY_VAR ] ) && $_GET[ self::QUERY_VAR ] === '1';

        if ( ! $via_rewrite && ! $via_query ) {
            return;
        }

        header( 'Content-Type: application/xml; charset=UTF-8' );
        header( 'Cache-Control: public, max-age=3600' );
        // The sitemap itself should not appear in search results.
        header( 'X-Robots-Tag: noindex' );

        echo self::build_xml(); // phpcs:ignore WordPress.Security.EscapeOutput -- XML built with esc_url/esc_html per node.
        exit;
    }

    /**
     * Build the full sitemap XML string.
     */
    public static function build_xml() {
        $galleries = get_posts( [
            'post_type'      => 'client_gallery',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'modified',
            'order'          => 'DESC',
        ] );

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
              . ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

        foreach ( $galleries as $gallery ) {
            // Skip private galleries — noindex'd, images not advertised.
            if ( class_exists( 'CGM_Gallery_Access' )
                && CGM_Gallery_Access::gallery_is_private( $gallery->ID ) ) {
                continue;
            }

            $files = CGM_Gallery_Storage::get_files( $gallery->ID );
            if ( empty( $files ) ) {
                continue;
            }

            $permalink = get_permalink( $gallery );
            $lastmod   = get_post_modified_time( 'c', true, $gallery );

            $xml .= "\t<url>\n";
            $xml .= "\t\t<loc>" . esc_url( $permalink ) . "</loc>\n";
            $xml .= "\t\t<lastmod>" . esc_html( $lastmod ) . "</lastmod>\n";

            foreach ( $files as $file ) {
                $xml .= "\t\t<image:image>\n";
                $xml .= "\t\t\t<image:loc>" . esc_url( $file['thumb_url'] ) . "</image:loc>\n";
                $xml .= "\t\t</image:image>\n";
            }

            $xml .= "\t</url>\n";
        }

        $xml .= '</urlset>' . "\n";

        return $xml;
    }
}
