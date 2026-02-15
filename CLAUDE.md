# Client Gallery Plugin — Claude Context

## Project Overview
WordPress plugin for managing private, secure image galleries for clients.
Images are stored **outside** the media library at `/var/www/client_galleries/`.

- **Plugin slug:** `client-gallery`
- **Custom post type:** `client_gallery`
- **Version:** 0.1.5
- **Author:** Allen Redshaw

---

## Directory Structure

```
client-gallery/
├── client-gallery.php              # Main bootstrap — CPT, taxonomy, asset enqueue, gallery render
├── includes/
│   ├── class-gallery-admin.php     # Admin UI, meta boxes, upload/sync/zip actions
│   ├── class-gallery-access.php    # Password validation, signed cookie tokens, access gating
│   ├── class-gallery-blocks.php    # Gutenberg block (client-gallery/index)
│   ├── class-gallery-download.php  # Single image + bulk ZIP download endpoints
│   ├── class-gallery-seo.php       # Robots meta, canonical, ALT text, JSON-LD ImageObject, OG tags
│   └── class-gallery-storage.php   # Image I/O, WebP thumbnail gen, watermarking, dimensions
├── assets/
│   ├── css/
│   │   ├── gallery.css             # CSS columns masonry grid + index block layout
│   │   ├── lightbox.css            # Lightbox overlay
│   │   └── password-modal.css      # Download password modal
│   └── js/
│       ├── gallery-lightbox.js     # Lightbox interaction, keyboard nav, touch swipe
│       ├── gallery-share.js        # Web Share API + clipboard fallback
│       ├── password-modal.js       # Download password modal logic
│       └── block-client-gallery-index.js  # Gutenberg block editor JS
└── fonts/
    └── JosefinSans-VariableFont_wght.ttf  # Used for watermarks
```

---

## Tech Stack
- **PHP 7.4+** — No Composer, no external PHP libs
- **WordPress** — hooks, post meta, nonces, password hashing
- **Vanilla JS** — No jQuery; ES5/ES6, no build step
- **CSS3** — Custom properties, CSS columns masonry, mobile-first
- **PHP GD** — Thumbnail generation + watermarking
- **ZipArchive** — Bulk download ZIP creation

---

## Two-Tier Password System
| Password Type | Post Meta Key | Cookie | Purpose |
|---|---|---|---|
| Viewing | `_cgm_view_password_hash` | `cgm_gallery_view_{id}` | Gate viewing images |
| Download | `_cgm_download_password_hash` | `cgm_gallery_dl_{id}` | Gate downloads |

- Passwords hashed with `wp_hash_password()` (bcrypt)
- Cookie tokens signed with `hash_hmac('sha256', ..., wp_salt())`
- Tokens expire after 1 day

---

## Storage Layout
```
/var/www/client_galleries/
└── {gallery-slug}/
    ├── original/     # Full-size uploaded images
    ├── thumbs/       # Auto-generated WebP thumbnails ({filename}.webp)
    └── gallery.zip   # Cached bulk download ZIP
```

---

## Post Meta Keys
| Key | Description |
|---|---|
| `_cgm_visibility` | `public` or `password` |
| `_cgm_view_password_hash` | Hashed viewing password |
| `_cgm_download_password_hash` | Hashed download password |
| `_cgm_folder_name` | Custom storage folder name |
| `_cgm_cover_image` | Cover image basename for index and OG image fallback |
| `_cgm_schema_location` | Venue/location name for JSON-LD `contentLocation` (e.g. "ByWard Market") |
| `_cgm_schema_social_urls` | Newline-separated social media URLs → `SocialMediaPosting` in `subjectOf` |
| `_cgm_schema_news_urls` | Newline-separated press/news URLs → `NewsArticle` in `subjectOf` |

---

## Taxonomy
- **Name:** `gallery_category`
- **Registered for:** `client_gallery` post type
- **Hierarchical:** yes (category-style)
- `public: false`, `rewrite: false` — no front-end archive URLs, no URL slugs exposed
- `show_in_rest: true` — required for Gutenberg `getEntityRecords` and admin sidebar panel
- Registered in `cgm_register_gallery_taxonomy()` hooked to `init`

---

## Admin-Post Endpoints
| Action | Description |
|---|---|
| `cgm_download` | Stream single image download |
| `cgm_download_all` | Stream ZIP download |
| `cgm_sync_gallery` | Sync images from server folder |
| `cgm_build_zip` | Build or rebuild download ZIP |

## Frontend Query Params
| Param | Description |
|---|---|
| `?cgm_thumb=1&gallery_id=X&file=Y` | Serve thumbnail (access-controlled, always WebP from `thumbs/`) |
| `?action=cgm_cover_thumb&gallery_id=X` | Public cover image for index block |

---

## Gutenberg Block (`client-gallery/index`)
- **Attributes:** `posts_per_page`, `orderBy`, `order`, `minWidth`, `gap`, `category`, `tileBg`
- `category` — `gallery_category` term ID; 0 = all; renders `tax_query` in WP_Query
- `tileBg` — hex colour string; sets `--cgm-tile-bg` CSS var inline; shown as `ColorPalette` in Layout panel using TT5 theme colours via `withSelect`
- `gap` — sets `--cgm-gap` inline (overrides theme block-gap)
- `minWidth` — sets `--cgm-min-width` inline for index grid `auto-fit` columns
- JS uses `withSelect` HOC to inject both `galleryCategories` (REST) and `themeColors` (editor settings)
- Shows cover images; password-protected galleries show a placeholder

---

## Gallery Grid Layout
- **Individual gallery:** CSS `columns: 220px` masonry — images at native aspect ratio, no cropping
  - `break-inside: avoid` on `.cgm-item`; `margin-bottom` replaces `gap` (columns don't support gap)
  - `<img>` tags include `width`/`height` from `getimagesize()` on the WebP thumb → prevents CLS
- **Index block:** CSS Grid `auto-fit minmax(var(--cgm-min-width), 1fr)`; cover images letterboxed with `object-fit: contain`
- **Tile background:** `--cgm-tile-bg: #131813` default; all tiles/letterbox bars use `var(--cgm-tile-bg)`

---

## Lightbox
- Keyboard: ESC closes, ArrowLeft/ArrowRight navigates
- Touch swipe left/right to navigate (passive listeners, 50px threshold)
- **Carousel animation:** fade + 60px translate, 0.2s, driven by CSS keyframes in `lightbox.css`
  - `showImageForIndex(index, direction)` — `direction` is `'next'`/`'prev'`; omit for instant (first open)
  - `applyImageForIndex(index)` — pure src + download-button update, no animation
  - `isAnimating` guard prevents queuing rapid clicks mid-animation
  - Out-animation class (`forwards` fill) keeps image at `opacity:0` while new src loads
  - Slide-in starts only after `load`/`error` fires (or `imageEl.complete` if cached) — no flash
  - `closeLightbox()` strips all four animation classes and resets `isAnimating`

---

## Security Patterns
- Nonce verification on all form submissions
- Path traversal protection via `wp_basename()` + strict character validation
- No right-click on gallery images (soft block)
- SEO: `noindex/nofollow` on password-protected galleries

---

## SEO Implementation (class-gallery-seo.php)

### JSON-LD Schema
- `@type: ImageObject` (matches the site's existing hand-coded schema pattern — NOT ImageGallery)
- `@id`: `{permalink}#image-set`
- Linked graph nodes: `author` → `/about/#allen-redshaw`, `creator` → `/#organization`
- `contentLocation` populated from `_cgm_schema_location` meta (Ottawa, ON, CA address added automatically)
- `subjectOf` built from `_cgm_schema_social_urls` (→ `SocialMediaPosting`) + `_cgm_schema_news_urls` (→ `NewsArticle`)
- Private galleries → no schema output

### Open Graph / Twitter Cards
- Yoast detection: `did_action('wpseo_head') > 0` (NOT `defined('WPSEO_VERSION')`)
- If Yoast ran + no featured image → inject `og:image` / `twitter:image` using cover thumb URL
- Cover image injected into Yoast's own pipeline via `wpseo_add_opengraph_images` action
- No SEO plugin → full minimal OG + Twitter set output at `wp_head` priority 20
- Private galleries → no OG output

---

## OG Image Serving — Confirmed Working
The OG image URL (`?cgm_thumb=1&gallery_id=X&file=cover.jpg`) serves from `thumbs/cover.jpg.webp`
as `Content-Type: image/webp`. The `.webp` extension does not appear in the URL — expected.
**Do NOT serve originals through the thumb endpoint.**

---

## SEO Audit — Completed Items
- ✅ Robots `noindex/nofollow` for private galleries
- ✅ Canonical fallback (skips if Yoast/RankMath active)
- ✅ Alt text: unique per image via `CGM_Gallery_SEO::build_image_alt()`
- ✅ JSON-LD `ImageObject` schema on public gallery pages
- ✅ Open Graph + Twitter Card tags with Yoast integration
- ✅ `Cache-Control: public` for public thumbnails; `private, no-store` for private
- ✅ `<img width height>` attributes from `getimagesize()` on WebP thumbs — eliminates CLS

## Pending / Future
- ⏳ Image sitemap
- ⏳ Zero-padded Lightroom filenames for correct `scandir()` sort order
- ⏳ Theme colour picker for tile bg not pulling child-theme palette correctly (investigate `withSelect` + `core/block-editor` store timing)

---

## Known Conventions
- All class names prefixed `CGM_Gallery_*`
- All function names prefixed `cgm_`
- All post meta keys prefixed `_cgm_`
- All action/filter hooks prefixed `cgm_`
- No external PHP dependencies — pure WP core
- No build tools — edit CSS/JS directly
