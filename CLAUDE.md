# Client Gallery Plugin — Claude Context

## Project Overview
WordPress plugin for managing private, secure image galleries for clients.
Images are stored **outside** the media library at `/var/www/client_galleries/`.

- **Plugin slug:** `client-gallery`
- **Custom post type:** `client_gallery`
- **Version:** 0.1.2
- **Author:** Allen Redshaw

---

## Directory Structure

```
client-gallery/
├── client-gallery.php              # Main bootstrap (467 lines) — CPT, asset enqueue, gallery render
├── includes/
│   ├── class-gallery-admin.php     # Admin UI, meta boxes, upload/sync/zip actions
│   ├── class-gallery-access.php    # Password validation, signed cookie tokens, access gating
│   ├── class-gallery-blocks.php    # Gutenberg block (client-gallery/index)
│   ├── class-gallery-download.php  # Single image + bulk ZIP download endpoints
│   ├── class-gallery-seo.php       # Robots meta, canonical, ALT text, JSON-LD ImageObject, OG tags
│   └── class-gallery-storage.php   # Image I/O, WebP thumbnail gen, watermarking
├── assets/
│   ├── css/
│   │   ├── gallery.css             # Responsive grid layout
│   │   ├── lightbox.css            # Lightbox overlay
│   │   └── password-modal.css      # Download password modal
│   └── js/
│       ├── gallery-lightbox.js     # Lightbox interaction & keyboard nav
│       ├── gallery-share.js        # Web Share API + clipboard fallback
│       ├── password-modal.js       # Download password modal logic
│       └── block-client-gallery-index.js  # Gutenberg block editor JS
└── fonts/
    └── JosefinSans-VariableFont_wght.ttf  # Used for watermarks
```

---

## Tech Stack
- **PHP 7.0+** — No Composer, no external PHP libs
- **WordPress** — hooks, post meta, nonces, password hashing
- **Vanilla JS** — No jQuery; ES5/ES6, no build step
- **CSS3** — Custom properties, CSS Grid, mobile-first
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
    ├── thumbs/       # Auto-generated WebP thumbnails
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

## Gutenberg Block
- **Block name:** `client-gallery/index`
- **Attributes:** `postsPerPage`, `orderBy`, `order`, `minTileWidth`
- Shows cover images; password-protected galleries show a placeholder

---

## Security Patterns
- Nonce verification on all form submissions
- Path traversal protection via `wp_basename()` + strict character validation
- No right-click on gallery images (soft block)
- SEO: `noindex/nofollow` on password-protected galleries

---

## Recent Git History
```
(pending)  Cache-Control: public for public gallery thumbnails (Cloudflare caching)
(pending)  add Open Graph tags and Yoast OG image injection for gallery pages
03a84c9    add programmatic ImageObject JSON-LD schema to gallery pages
dccc298    fix download button initial pw input
9965fd4    stable — added share button
```

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
- Yoast detection: `did_action('wpseo_head') > 0` (NOT `defined('WPSEO_VERSION')` — that only checks installed, not if OG ran)
- If Yoast ran + no featured image → inject `og:image` / `twitter:image` using cover thumb URL
- Cover image injected into Yoast's own pipeline via `wpseo_add_opengraph_images` action
- No SEO plugin → full minimal OG + Twitter set output at `wp_head` priority 20
- Private galleries → no OG output

---

## OG Image Serving — Confirmed Working
The OG image URL (`?cgm_thumb=1&gallery_id=X&file=cover.jpg`) contains `.jpg` in the `file` param
(the original filename used as a lookup key), but `handle_thumb_request()` resolves this to
`thumbs/cover.jpg.webp` and serves it as `Content-Type: image/webp`. The watermark is visible,
confirming the thumbnail pipeline is operating correctly.

**The `.webp` extension does not appear in the URL** — that is expected. The endpoint is query-string
based and resolves to the WebP file internally. The original JPEG is never accessible through this
endpoint.

**Do NOT allow the original JPEG to be served through the thumb endpoint.** The access-controlled
thumbnail pipeline (watermarked WebP, access-gated) is the only intended path for frontend image delivery.

---

## SEO Audit — Completed Items
- ✅ Robots `noindex/nofollow` for private galleries (`filter_wp_robots`)
- ✅ Canonical fallback (skips if Yoast/RankMath active)
- ✅ Alt text: unique per image — `CGM_Gallery_SEO::build_image_alt($id, $i+1, $file)` called in client-gallery.php:347
- ✅ JSON-LD `ImageObject` schema on public gallery pages (admin UI: location, social URLs, news URLs)
- ✅ Open Graph + Twitter Card tags with Yoast integration
- ✅ `Cache-Control: public, max-age=86400` for public gallery thumbnails (Cloudflare edge caching)
- ✅ `Cache-Control: private, no-store` for private gallery thumbnails
- ✅ robots.txt: removed `Disallow: /*?cgm_thumb=` (access control handles private images server-side)

## Pending / Future SEO
- ⏳ Image sitemap — optional enhancement; images already discovered via `<img>` tags on gallery pages
- ⏳ File sort order — use zero-padded Lightroom export filenames (e.g. `name-001.jpg`) so `scandir()` alphabetical = intended order

---

## Known Conventions
- All class names prefixed `CGM_Gallery_*`
- All function names prefixed `cgm_`
- All post meta keys prefixed `_cgm_`
- All action/filter hooks prefixed `cgm_`
- No external PHP dependencies — pure WP core
- No build tools — edit CSS/JS directly
