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
    public static function build_image_alt( $gallery_id, $index = 0, $file = [] ) {
        $gallery_id = (int) $gallery_id;
        $index      = (int) $index;

        $n = $index > 0 ? $index : 0;

        if ( self::is_gallery_private( $gallery_id ) ) {
            $alt = $n > 0
                ? sprintf( 'Private client photo %d', $n )
                : 'Private client photo';
        } else {
            $title = get_the_title( $gallery_id );
            $title = $title ? $title : 'Client gallery';

            $alt = $n > 0
                ? sprintf( '%s – Photo %d', $title, $n )
                : $title;
        }

        return apply_filters(
            'cgm_image_alt',
            $alt,
            $gallery_id,
            $index,
            $file
        );
    }
}
