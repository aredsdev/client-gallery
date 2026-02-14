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
        add_action( 'wp_head', [ __CLASS__, 'output_schema_json_ld' ], 5 );
        add_action( 'wp_head', [ __CLASS__, 'output_og_tags' ], 20 );
        // Inject cover image into Yoast's own OG image pipeline (fires before wp_head priority 20).
        add_action( 'wpseo_add_opengraph_images', [ __CLASS__, 'inject_yoast_og_image' ] );
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

    /**
     * Output JSON-LD ImageObject structured data for public galleries.
     *
     * Matches the manual schema pattern already used on the site:
     *   @type ImageObject with author, creator, contentLocation, subjectOf.
     *
     * Only fires on public singular client_gallery pages.
     * Skips contentLocation when no venue is set; skips subjectOf when no URLs saved.
     */
    public static function output_schema_json_ld() {
        if ( ! is_singular( 'client_gallery' ) ) {
            return;
        }

        $gallery_id = get_queried_object_id();
        if ( $gallery_id <= 0 ) {
            return;
        }

        // Private galleries get noindex — no point adding schema.
        if ( self::is_gallery_private( $gallery_id ) ) {
            return;
        }

        $home    = untrailingslashit( get_home_url() );
        $title   = get_the_title( $gallery_id );
        $permalink = get_permalink( $gallery_id );
        $excerpt = wp_strip_all_tags( get_the_excerpt( $gallery_id ) );
        $date    = get_the_date( 'c', $gallery_id );

        $location         = get_post_meta( $gallery_id, '_cgm_schema_location', true );
        $social_urls_raw  = get_post_meta( $gallery_id, '_cgm_schema_social_urls', true );
        $news_urls_raw    = get_post_meta( $gallery_id, '_cgm_schema_news_urls', true );

        $schema = [
            '@context'           => 'https://schema.org',
            '@type'              => 'ImageObject',
            '@id'                => $permalink . '#image-set',
            'name'               => $title,
            'author'             => [ '@id' => $home . '/about/#allen-redshaw' ],
            'creator'            => [ '@id' => $home . '/#organization' ],
            'dateCreated'        => $date,
            'acquireLicensePage' => $home . '/contact/',
            'creditText'         => 'Photo by Allen Redshaw / Parfocal Media',
            'copyrightNotice'    => '© ' . gmdate( 'Y' ) . ' Parfocal Media',
        ];

        if ( $excerpt ) {
            $schema['description'] = $excerpt;
        }

        if ( $location ) {
            $schema['contentLocation'] = [
                '@type'   => 'Place',
                'name'    => $location,
                'address' => [
                    '@type'           => 'PostalAddress',
                    'addressLocality' => 'Ottawa',
                    'addressRegion'   => 'ON',
                    'addressCountry'  => 'CA',
                ],
            ];
        }

        // Build subjectOf from social + news URLs — one URL per line in each field.
        $subjects = [];

        foreach ( preg_split( '/\r?\n/', (string) $social_urls_raw ) as $line ) {
            $clean = esc_url_raw( trim( $line ) );
            if ( $clean ) {
                $subjects[] = [ '@type' => 'SocialMediaPosting', 'url' => $clean ];
            }
        }

        foreach ( preg_split( '/\r?\n/', (string) $news_urls_raw ) as $line ) {
            $clean = esc_url_raw( trim( $line ) );
            if ( $clean ) {
                $subjects[] = [ '@type' => 'NewsArticle', 'url' => $clean ];
            }
        }

        if ( $subjects ) {
            $schema['subjectOf'] = $subjects;
        }

        echo "\n" . '<script type="application/ld+json">'
            . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT )
            . '</script>' . "\n";
    }

    /**
     * Open Graph / Twitter Card tags for gallery pages.
     *
     * Strategy:
     *  - Yoast/RankMath active + featured image set → do nothing, SEO plugin handles it.
     *  - Yoast/RankMath active + NO featured image → inject og:image / twitter:image
     *    using the gallery cover thumb as the fallback (watermarked, publicly accessible).
     *  - No SEO plugin → output a minimal but complete OG + Twitter set.
     *
     * Fires at priority 20, after Yoast (priority 1–11), so did_action checks are reliable.
     */
    public static function output_og_tags() {
        if ( ! is_singular( 'client_gallery' ) ) {
            return;
        }

        $gallery_id = get_queried_object_id();
        if ( $gallery_id <= 0 || self::is_gallery_private( $gallery_id ) ) {
            return;
        }

        // Build the cover image thumbnail URL (public for public galleries).
        $cover_url  = '';
        $cover_base = get_post_meta( $gallery_id, '_cgm_cover_image', true );

        if ( $cover_base ) {
            $cover_url = add_query_arg( [
                'cgm_thumb'  => 1,
                'gallery_id' => $gallery_id,
                'file'       => $cover_base,
            ], home_url( '/' ) );
        }

        $has_featured = has_post_thumbnail( $gallery_id );
        // Check if Yoast actually ran its head output (not just installed).
        $yoast_ran    = did_action( 'wpseo_head' ) > 0;
        $rankmath_ran = did_action( 'rank_math/head' ) > 0;

        if ( $yoast_ran || $rankmath_ran ) {
            // SEO plugin ran — it handles title/description/URL.
            // Only inject image if no featured image (Yoast may have skipped it).
            if ( ! $has_featured && $cover_url ) {
                echo '<meta property="og:image" content="' . esc_url( $cover_url ) . '" />' . "\n";
                echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
                echo '<meta name="twitter:image" content="' . esc_url( $cover_url ) . '" />' . "\n";
            }
            return;
        }

        // No SEO plugin — output a full minimal OG + Twitter set.
        $title  = get_the_title( $gallery_id );
        $desc   = wp_strip_all_tags( get_the_excerpt( $gallery_id ) );
        $url    = get_permalink( $gallery_id );
        $image  = $has_featured
            ? get_the_post_thumbnail_url( $gallery_id, 'large' )
            : $cover_url;

        echo '<meta property="og:type"  content="article" />' . "\n";
        echo '<meta property="og:title" content="' . esc_attr( $title ) . '" />' . "\n";
        echo '<meta property="og:url"   content="' . esc_url( $url ) . '" />' . "\n";

        if ( $desc ) {
            echo '<meta property="og:description" content="' . esc_attr( $desc ) . '" />' . "\n";
        }

        if ( $image ) {
            echo '<meta property="og:image"        content="' . esc_url( $image ) . '" />' . "\n";
            echo '<meta name="twitter:card"        content="summary_large_image" />' . "\n";
            echo '<meta name="twitter:image"       content="' . esc_url( $image ) . '" />' . "\n";
            echo '<meta name="twitter:title"       content="' . esc_attr( $title ) . '" />' . "\n";
            if ( $desc ) {
                echo '<meta name="twitter:description" content="' . esc_attr( $desc ) . '" />' . "\n";
            }
        }
    }

    /**
     * Inject the gallery cover image into Yoast's OG image pipeline.
     *
     * Fires via wpseo_add_opengraph_images — only used when Yoast IS active and
     * handling OG for this CPT. Skips if a featured image already covers it.
     *
     * @param \WPSEO_OpenGraph_Image $image_container Yoast image manager.
     */
    public static function inject_yoast_og_image( $image_container ) {
        if ( ! is_singular( 'client_gallery' ) ) {
            return;
        }

        $gallery_id = get_queried_object_id();
        if ( ! $gallery_id || self::is_gallery_private( $gallery_id ) ) {
            return;
        }

        if ( has_post_thumbnail( $gallery_id ) ) {
            return; // Yoast picks up the featured image automatically.
        }

        $cover_base = get_post_meta( $gallery_id, '_cgm_cover_image', true );
        if ( ! $cover_base ) {
            return;
        }

        $cover_url = add_query_arg( [
            'cgm_thumb'  => 1,
            'gallery_id' => $gallery_id,
            'file'       => $cover_base,
        ], home_url( '/' ) );

        $image_container->add_image_by_url( $cover_url );
    }
}
