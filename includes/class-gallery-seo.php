<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * SEO handling for Client Gallery Manager.
 *
 * - Robots meta for public vs private galleries
 * - Canonical fallback for gallery CPT
 * - ALT text helper for gallery images
 */
class CGM_Gallery_SEO {

    public static function init() {
        add_filter( 'wp_robots', [ __CLASS__, 'filter_wp_robots' ], 999 );
        add_action( 'wp_head', [ __CLASS__, 'output_canonical_fallback' ], 1 );
    }

    /**
     * Wrapper: decide if a gallery is private.
     */
    protected static function is_gallery_private( $gallery_id ) {
        $gallery_id = (int) $gallery_id;

        if ( $gallery_id <= 0 ) {
            return false;
        }

        if ( class_exists( 'CGM_Gallery_Access' )
            && method_exists( 'CGM_Gallery_Access', 'gallery_is_private' )
        ) {
            return (bool) CGM_Gallery_Access::gallery_is_private( $gallery_id );
        }

        // Fail open (public) to avoid accidental noindex.
        return false;
    }

    /**
     * Robots meta control for gallery pages.
     */
    public static function filter_wp_robots( array $robots ) {
        if ( ! is_singular( 'client_gallery' ) ) {
            return $robots;
        }

        $gallery_id = get_queried_object_id();
        if ( $gallery_id <= 0 ) {
            return $robots;
        }

        if ( self::is_gallery_private( $gallery_id ) ) {
            $robots['noindex']  = true;
            $robots['nofollow'] = true;
            unset( $robots['index'], $robots['follow'] );
        }

        return $robots;
    }

    /**
     * Canonical fallback for gallery CPT pages.
     */
    public static function output_canonical_fallback() {
        if ( ! is_singular( 'client_gallery' ) ) {
            return;
        }

        // Let major SEO plugins handle canonicals if present.
        if ( did_action( 'wpseo_head' ) || did_action( 'rank_math/head' ) ) {
            return;
        }

        $gallery_id = get_queried_object_id();
        if ( $gallery_id <= 0 ) {
            return;
        }

        $canonical = get_permalink( $gallery_id );
        if ( ! $canonical ) {
            return;
        }

        echo "\n" . '<link rel="canonical" href="' . esc_url( $canonical ) . '" />' . "\n";
    }

    /**
     * Build automatic ALT text for gallery images.
     *
     * Public:  "{Gallery Title} – Photo {n}"
     * Private: "Private client photo {n}"
     */
    public static function build_image_alt( $gallery_id, $index, $file ) {

        $gallery_id = intval( $gallery_id );
        $index      = max( 1, intval( $index ) );

        // If private, keep it generic (don’t leak names/venue).
        if ( self::is_gallery_private( $gallery_id ) ) {
            return sprintf( 'Private client photo %d', $index );
        }

        // Gallery title as the base (best primary signal).
        $title = get_the_title( $gallery_id );
        if ( ! $title ) {
            $title = 'Client gallery';
        }

        // Optional filename hint (only if it looks human).
        $filename = isset( $file['basename'] )
            ? pathinfo( $file['basename'], PATHINFO_FILENAME )
            : '';

        $filename = str_replace( [ '-', '_' ], ' ', $filename );
        $filename = trim( preg_replace( '/\s+/', ' ', $filename ) );

        // If filename is basically numbers/DSC style, ignore it.
        if ( $filename && preg_match( '/^(img|dsc|p\d+|image)\s*\d*$/i', $filename ) ) {
            $filename = '';
        }

        // Avoid repeating title
        if ( $filename && stripos( $title, $filename ) !== false ) {
            $filename = '';
        }

        $alt_parts = [ $title ];

        if ( $filename ) {
            $alt_parts[] = $filename;
        } else {
            // Always include an index so alts are unique.
            $alt_parts[] = sprintf( 'Photo %d', $index );
        }

        // Light local signal without forcing service type.
        $alt_parts[] = 'Ottawa';

        return implode( ' – ', $alt_parts );
    }
}
