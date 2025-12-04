<?php
/**
 * Block registration for Client Gallery Manager.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CGM_Gallery_Blocks {

    public static function init() {
        add_action( 'init', [ __CLASS__, 'register_blocks' ] );
    }

    public static function register_blocks() {

        wp_register_script(
            'cgm-client-gallery-block',
            CGM_PLUGIN_URL . 'assets/js/block-client-gallery-index.js',
            [
                'wp-blocks',
                'wp-element',
                'wp-i18n',
                'wp-block-editor',
                'wp-components',
            ],
            '0.1.2',
            true
        );

        register_block_type(
            'client-gallery/index',
            [
                'editor_script'   => 'cgm-client-gallery-block',
                'style'           => 'cgm-gallery-css',
                'editor_style'    => 'cgm-gallery-css',
                'render_callback' => [ __CLASS__, 'render_gallery_index_block' ],
                'attributes'      => [
                    'posts_per_page' => [
                        'type'    => 'number',
                        'default' => -1,
                    ],
                    'order'          => [
                        'type'    => 'string',
                        'default' => 'DESC',
                    ],
                    'orderby'        => [
                        'type'    => 'string',
                        'default' => 'date',
                    ],
                    'minWidth'       => [
                        'type'    => 'number',
                        'default' => 220,
                    ],
                ],
                'supports'        => [
                    'align' => [ 'wide', 'full' ],
                ],
            ]
        );
    }

    public static function render_gallery_index_block( $attributes, $content ) {
        if ( ! post_type_exists( 'client_gallery' ) ) {
            return '';
        }

        $atts = wp_parse_args(
            $attributes,
            [
                'posts_per_page' => -1,
                'order'          => 'DESC',
                'orderby'        => 'date',
                'minWidth'       => 220,
            ]
        );

        $query = new WP_Query( [
            'post_type'      => 'client_gallery',
            'post_status'    => 'publish',
            'posts_per_page' => (int) $atts['posts_per_page'],
            'orderby'        => sanitize_text_field( $atts['orderby'] ),
            'order'          => sanitize_text_field( $atts['order'] ),
        ] );

        if ( ! $query->have_posts() ) {
            return '<p>' . esc_html__( 'No client galleries available yet.', 'client-gallery' ) . '</p>';
        }

        $min_width = (int) $atts['minWidth'];
        if ( $min_width <= 0 ) {
            $min_width = 220;
        }

        ob_start();

        echo '<div class="cgm-gallery-index-grid" style="--cgm-min-width:' . esc_attr( $min_width ) . 'px">';

        while ( $query->have_posts() ) {
            $query->the_post();
            $gallery_id = get_the_ID();
            $title      = get_the_title();
            $permalink  = get_permalink( $gallery_id );

            $lead_html      = '';
            $cover_basename = get_post_meta( $gallery_id, '_cgm_cover_image', true );
            $files          = class_exists( 'CGM_Gallery_Storage' )
                ? CGM_Gallery_Storage::get_files( $gallery_id )
                : [];

            // Is this gallery view-protected?
            $is_protected = class_exists( 'CGM_Gallery_Access' )
                && method_exists( 'CGM_Gallery_Access', 'requires_view_password' )
                && CGM_Gallery_Access::requires_view_password( $gallery_id );

            if ( $cover_basename ) {
                // Use dedicated public cover endpoint.
                $cover_thumb_url = add_query_arg(
                    [
                        'action'     => 'cgm_cover_thumb',
                        'gallery_id' => (int) $gallery_id,
                    ],
                    admin_url( 'admin-post.php' )
                );

                $lead_html = sprintf(
                    '<img src="%s" class="cgm-gallery-index-image" alt="%s" loading="lazy" />',
                    esc_url( $cover_thumb_url ),
                    esc_attr( $title )
                );
            }

            // Fallback: first file's thumb ONLY for non-protected galleries.
            if ( ! $lead_html && ! empty( $files ) && ! $is_protected ) {
                $first = reset( $files );
                if ( ! empty( $first['thumb_url'] ) ) {
                    $lead_html = sprintf(
                        '<img src="%s" class="cgm-gallery-index-image" alt="%s" loading="lazy" />',
                        esc_url( $first['thumb_url'] ),
                        esc_attr( $title )
                    );
                }
            }


            if ( ! $lead_html && ! empty( $files ) ) {
                $first = reset( $files );
                if ( ! empty( $first['thumb_url'] ) ) {
                    $lead_html = sprintf(
                        '<img src="%s" class="cgm-gallery-index-image" alt="%s" loading="lazy" />',
                        esc_url( $first['thumb_url'] ),
                        esc_attr( $title )
                    );
                }
            }

            echo '<article class="cgm-gallery-index-item">';
            echo '<a href="' . esc_url( $permalink ) . '" class="cgm-gallery-index-link">';

            if ( $lead_html ) {
                echo '<div class="cgm-gallery-index-thumb-wrap">';
                echo $lead_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo '</div>';
            }

            echo '<h2 class="cgm-gallery-index-title">' . esc_html( $title ) . '</h2>';
            echo '</a>';
            echo '</article>';
        }

        echo '</div>';

        wp_reset_postdata();

        return ob_get_clean();
    }
}
