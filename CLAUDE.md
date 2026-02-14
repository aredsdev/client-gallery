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
│   ├── class-gallery-seo.php       # Robots meta, canonical, ALT text
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
| `_cgm_cover_image` | Cover image basename for index |

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
| `?cgm_thumb=1&gallery_id=X&file=Y` | Serve thumbnail (access-controlled) |
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
dccc298  fix download button initial pw input
9965fd4  stable — added share button
c53f911  working links only render if pw entered
931bf70  commit before SEO changes
b335f7b  Merge from GitHub
```

---

## Known Conventions
- All class names prefixed `CGM_Gallery_*`
- All function names prefixed `cgm_`
- All post meta keys prefixed `_cgm_`
- All action/filter hooks prefixed `cgm_`
- No external PHP dependencies — pure WP core
- No build tools — edit CSS/JS directly
