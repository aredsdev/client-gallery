(function (wp) {
    // Bail early if block APIs are not available for some reason.
    if (
        !wp ||
        !wp.blocks ||
        !wp.element ||
        !wp.components ||
        !(wp.blockEditor || wp.editor) ||
        !wp.i18n
    ) {
        return;
    }

    const { registerBlockType } = wp.blocks;
    const { __ } = wp.i18n;
    const InspectorControls = (wp.blockEditor || wp.editor).InspectorControls;
    const { PanelBody, RangeControl, SelectControl, ColorPalette } = wp.components;
    const el = wp.element.createElement;
    const Fragment = wp.element.Fragment || function Fragment(props) { return props.children; };
    const withSelect = wp.data && wp.data.withSelect;

    registerBlockType('client-gallery/index', {
        title: __('Client Gallery Index', 'client-gallery'),
        description: __('Displays a grid of client galleries.', 'client-gallery'),
        icon: 'images-alt2',
        category: 'widgets',

        attributes: {
            posts_per_page: {
                type: 'number',
                default: -1,
            },
            order: {
                type: 'string',
                default: 'DESC',
            },
            orderby: {
                type: 'string',
                default: 'date',
            },
            minWidth: {
                type: 'number',
                default: 220,
            },
            gap: {
                type: 'number',
                default: 16,
            },
            category: {
                type: 'number',
                default: 0,
            },
            tileBg: {
                type: 'string',
                default: '',
            },
        },

        supports: {
            align: ['wide', 'full'],
        },

        edit: (function () {
            function EditComponent(props) {
                const attributes = props.attributes || {};
                const setAttributes = props.setAttributes || function () {};

                const posts_per_page = attributes.posts_per_page;
                const order = attributes.order;
                const orderby = attributes.orderby;
                const minWidth = attributes.minWidth || 220;
                const gap = (typeof attributes.gap === 'number') ? attributes.gap : 16;
                const category = (typeof attributes.category === 'number') ? attributes.category : 0;
                const tileBg = attributes.tileBg || '';

                const themeColors = props.themeColors || [];

                const galleryCategories = props.galleryCategories || [];
                const catOptions = [{ label: __('All categories', 'client-gallery'), value: 0 }]
                    .concat(galleryCategories.map(function (t) {
                        return { label: t.name, value: t.id };
                    }));

                // Inspector sidebar (Query + Layout)
                const inspector = el(
                    InspectorControls,
                    {},
                    el(
                        PanelBody,
                        {
                            title: __('Query', 'client-gallery'),
                            initialOpen: true,
                        },
                        el(RangeControl, {
                            label: __('Galleries to show', 'client-gallery'),
                            help: __(
                                'On the front-end, -1 means "all galleries".',
                                'client-gallery'
                            ),
                            value: posts_per_page,
                            onChange: function (value) {
                                setAttributes({ posts_per_page: value });
                            },
                            min: -1,
                            max: 50,
                        }),
                        el(SelectControl, {
                            label: __('Order by', 'client-gallery'),
                            value: orderby,
                            options: [
                                { label: __('Date', 'client-gallery'), value: 'date' },
                                { label: __('Title', 'client-gallery'), value: 'title' },
                                { label: __('Menu order', 'client-gallery'), value: 'menu_order' },
                            ],
                            onChange: function (value) {
                                setAttributes({ orderby: value });
                            },
                        }),
                        el(SelectControl, {
                            label: __('Order', 'client-gallery'),
                            value: order,
                            options: [
                                { label: __('Descending', 'client-gallery'), value: 'DESC' },
                                { label: __('Ascending', 'client-gallery'), value: 'ASC' },
                            ],
                            onChange: function (value) {
                                setAttributes({ order: value });
                            },
                        }),
                        el(SelectControl, {
                            label: __('Category', 'client-gallery'),
                            value: category,
                            options: catOptions,
                            onChange: function (value) {
                                setAttributes({ category: parseInt(value, 10) || 0 });
                            },
                        })
                    ),
                    el(
                        PanelBody,
                        {
                            title: __('Layout', 'client-gallery'),
                            initialOpen: false,
                        },
                        el(RangeControl, {
                            label: __('Minimum tile width (px)', 'client-gallery'),
                            value: minWidth,
                            onChange: function (value) {
                                setAttributes({ minWidth: value });
                            },
                            min: 160,
                            max: 480,
                        }),
                        el(RangeControl, {
                            label: __('Grid Gap (px)', 'client-gallery'),
                            value: gap,
                            onChange: function (value) {
                                setAttributes({ gap: value });
                            },
                            min: 0,
                            max: 64,
                        }),
                        el('p', {
                            style: { margin: '16px 0 4px', fontWeight: 500 },
                        }, __('Tile background', 'client-gallery')),
                        el(ColorPalette, {
                            colors: themeColors,
                            value: tileBg || undefined,
                            onChange: function (value) {
                                setAttributes({ tileBg: value || '' });
                            },
                            clearable: true,
                            disableCustomColors: false,
                        })
                    )
                );

                // Simple editor preview: dummy cards using same grid + CSS var
                const sampleTiles = [];

                for (let i = 1; i <= 3; i++) {
                    sampleTiles.push(
                        el(
                            'article',
                            { key: i, className: 'cgm-gallery-index-item' },
                            el(
                                'div',
                                { className: 'cgm-gallery-index-link' },
                                el(
                                    'div',
                                    { className: 'cgm-gallery-index-thumb-wrap' },
                                    el(
                                        'div',
                                        {
                                            className: 'cgm-gallery-index-block-placeholder',
                                            style: { minHeight: '120px' },
                                        },
                                        __('Sample gallery ' + i, 'client-gallery')
                                    )
                                ),
                                el(
                                    'h2',
                                    { className: 'cgm-gallery-index-title' },
                                    __('Sample title ' + i, 'client-gallery')
                                )
                            )
                        )
                    );
                }

                const gridStyle = {
                    '--cgm-min-width': minWidth + 'px',
                    '--cgm-gap': gap + 'px',
                };
                if (tileBg) { gridStyle['--cgm-tile-bg'] = tileBg; }

                const previewGrid = el(
                    'div',
                    {
                        className: 'cgm-gallery-index-grid',
                        style: gridStyle,
                    },
                    sampleTiles
                );

                return el(
                    Fragment,
                    null,
                    inspector,
                    previewGrid
                );
            }

            if (withSelect) {
                return withSelect(function (select) {
                    var editorSettings = select('core/block-editor')
                        ? select('core/block-editor').getSettings()
                        : {};
                    return {
                        galleryCategories: select('core').getEntityRecords(
                            'taxonomy', 'gallery_category', { per_page: -1 }
                        ) || [],
                        themeColors: editorSettings.colors || [],
                    };
                })(EditComponent);
            }
            return EditComponent;
        })(),

        // Dynamic block – front-end markup is rendered in PHP.
        save: function () {
            return null;
        },
    });
})(window.wp);
