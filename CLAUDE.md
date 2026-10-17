---
tags:
  - dev/wp-plugins
type: index
related:
  - "[[dev/wp-plugins/CLAUDE|dev/wp-plugins index]]"
  - "[[dev/websites/parfocal/CLAUDE|parfocal]]"
  - "[[TODO]]"
updated: 2026-07-02
---

# Client Gallery Plugin — Claude Context

**Used on:** [[dev/websites/parfocal/CLAUDE|parfocal]] (parfocalmedia.ca) — confirmed active on the live server, but on the **old pre-migration path** (`/var/www/parfocalmedia/`, not the per-user-isolated `/var/www/homes/parfocalmedia/` the rest of the migration uses — see [[projects|dev/server projects]]).

**Version reconciled 2026-07-08 (the 2026-07-02 "local ahead of live" claim was backwards):** md5-compared every file live-vs-local — all includes were identical; the only drift was live having a hot-edited licensing paragraph in the download modal (+ a css cache-bust bump) that had never been pulled back into this repo. That edit is now committed here, and 0.2.0 deployed live. Live and local are in sync.

**User note (2026-07-02):** this plugin is more complex than the others — the gallery/download functionality spills out of the normal WP-plugin environment into what's effectively a standalone server-side app (external file storage at `/var/www/client_galleries/`, its own password/token system, ZIP streaming, etc. — see Storage Layout and Two-Tier Password System below). Expect ongoing changes here; treat this one as a live project, not a settled utility like the others in [[dev/wp-plugins/CLAUDE|dev/wp-plugins]].

## Project Overview
WordPress plugin for managing private, secure image galleries for clients.
Images are stored **outside** the media library at `/var/www/client_galleries/`.

- **Plugin slug:** `client-gallery`
- **Custom post type:** `client_gallery`
- **Version:** 0.3.2 (deployed live 2026-08-08)
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
│   ├── class-gallery-sitemap.php   # /cgm-images.xml image sitemap (public galleries only)
│   └── class-gallery-storage.php   # Image I/O, WebP thumbnail gen, watermarking, dimensions, JPEG og:image variant
├── assets/
│   ├── css/
│   │   ├── gallery.css             # CSS columns masonry grid + index block layout
│   │   ├── lightbox.css            # Lightbox overlay
│   │   └── password-modal.css      # Download password modal
│   └── js/
│       ├── gallery-lightbox.js     # Lightbox interaction, keyboard nav, touch swipe
│       ├── gallery-share.js        # Web Share API + clipboard fallback
│       ├── password-modal.js       # Download password modal logic
│       ├── gallery-analytics.js    # GA4 events: image views + downloads
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

### URL grants (Meta in-app browser fix, 2026-07-08)
**This was the real "Facebook bug":** Meta's in-app browser (FB/IG WebView) drops
`Set-Cookie` on the 302 redirect after a successful password POST, so users entered a
correct password and were immediately denied again — Meta browser only. Fix: the
redirect now also carries a signed, short-lived grant in the URL (`?cgm_grant=` for
view, `cgm_grant_dl=` for download; format `{expiry}.{hmac}`, 15-min TTL, keyed to
gallery ID + current password hash so a password change invalidates outstanding
grants). Every access check accepts cookie OR valid grant; a `template_redirect`
priority-0 hook re-issues the cookie on the following 200 (which WebViews honour);
at render time active grants are propagated onto thumb/download URLs so `<img>`
requests aren't denied in a fully cookie-less client. Verified live with a cookie-less
curl simulation: password POST → grant redirect → gallery + images render with zero
cookies; tampered grant → denied; no grant/cookie → denied.

---

## Storage Layout
```
/var/www/client_galleries/
└── {gallery-slug}/
    ├── original/     # Full-size uploaded images
    ├── thumbs/       # Auto-generated WebP thumbnails ({filename}.webp)
    │                 # (+ lazily cached {filename}.webp.jpg og:image variants)
    └── gallery.zip   # Cached bulk download ZIP
```

## Server-side thumbnail service — cgm-thumbd (2026-07-08)
Bulk thumbnail generation used to run inline in PHP-FPM (upload handler, the Sync
button, and `get_files()`'s lazy regeneration) and **took the site down** on big
galleries. It's now split out per Allen's ask:

- **Server program:** `/opt/cgm-thumbd/` on funheim — `cgm_thumbd.py` (Python 3 +
  Pillow, source lives in this repo under `server/`), run by
  `cgm-thumbd.timer` every 60s as `www-data` at idle CPU/IO priority
  (`Nice=19`, `IOSchedulingClass=idle`). Each pass: process `queue/` entries first,
  then sweep all galleries for missing thumbs; 50s time budget; flock prevents
  overlap; writes `status.json` heartbeat. Watermark/output parity with the GD code
  (1080px max width, Josefin Sans bottom-right ~45% white, WebP q80) verified by eye
  on a generated thumb. One improvement over GD: EXIF orientation is applied.
  Needed `apt install python3-pil` (user-level Pillow wasn't visible to www-data).
- **Plugin side:** settings page at Client Galleries → Thumbnail Service with the
  service-directory field (option `cgm_thumb_service_dir`, set to `/opt/cgm-thumbd`
  live). When `status.json` is fresh (<5 min) the plugin **never** generates thumbs
  in PHP — upload/sync/lazy-regen all just touch `queue/{gallery-folder}` instead;
  the Sync button notice says "queued" rather than a count. If the service dies,
  the plugin silently falls back to the old in-PHP path so galleries keep working.
- **Ops:** `systemctl status cgm-thumbd.timer`, logs via
  `journalctl -u cgm-thumbd.service`. Deploy of the .py does NOT go through the
  WP plugin dir — copy to `/opt/cgm-thumbd/` and it picks up next timer tick.

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
| `cgm_download` | Stream single image download (attachment). `&inline=1` = HTML wrapper page showing the original + save hint (used on iOS); `&raw=1` = the original served inline with its real image Content-Type (the wrapper's `<img>`) |
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
- **Deploy gotcha (2026-10-06):** auto mode's classifier blocks scp-to-live as "Production Deploy" — deploys need Allen to switch to manual approval first. Also, comparing live vs local via PowerShell `Out-File` hashes gives a false mismatch (re-encodes); diff from Git Bash with `tr -d '\r'` instead.
- **Attributes:** `posts_per_page`, `orderBy`, `order`, `minWidth`, `gap`, `category`, `tileBg`
- `category` — `gallery_category` term ID; 0 = all; renders `tax_query` in WP_Query
- `tileBg` — hex colour string; sets `--cgm-tile-bg` CSS var inline; shown as `ColorPalette` in Layout panel using TT5 theme colours via `withSelect`
- `gap` — sets `--cgm-gap` inline (overrides theme block-gap)
- `minWidth` — sets `--cgm-min-width` inline for index grid `auto-fit` columns
- JS uses `withSelect` HOC to inject both `galleryCategories` (REST) and `themeColors` (editor settings)
- Tile image priority (deployed live 2026-10-06 via scp + `php -l`, verified by curl on /client-gallery/; backup of previous file at `/tmp/class-gallery-blocks.php.bak-20261006` on funheim; not committed to git yet): **WP Featured Image** (`medium_large`) → plugin cover radio (`_cgm_cover_image`, via `cgm_cover_thumb`) → first file's thumb. Before this the index ignored Featured Images entirely, even though most galleries had one set (og:image already used them).

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
- **iOS downloads:** JS detects iOS (any browser) and appends `&inline=1` to single-image download URLs + drops the anchor's `download` attr, so Download opens a page with the full original for long-press > Save to Photos (see "iOS single-image download" section below)
- **Long-press notice:** hold/right-click on a grid or lightbox image shows a toast pointing at the Download button; transparent `::after` shield over the lightbox image (see "Long-press notice" section)
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
OG/Twitter image URLs now carry `&format=jpg` (added 2026-07-08): the thumb endpoint converts
the watermarked WebP thumb to a JPEG on first request (cached on disk as `{thumb}.webp.jpg`)
and serves `Content-Type: image/jpeg`. **This was the "Facebook bug":** Facebook's link-preview
crawler does not render WebP og:images, so any gallery relying on the cgm_thumb cover fallback
(no featured image) shared with no preview image at all. Confirmed fixed live via curl as
`facebookexternalhit/1.1` — real JPEG bytes now come back. On-page `<img>` tags still use the
smaller WebP (no format param). **Do NOT serve originals through the thumb endpoint.**

---

## SEO Audit — Completed Items
- ✅ Robots `noindex/nofollow` for private galleries
- ✅ Canonical fallback (skips if Yoast/RankMath active)
- ✅ Alt text: unique per image via `CGM_Gallery_SEO::build_image_alt()`
- ✅ JSON-LD `ImageObject` schema on public gallery pages
- ✅ Open Graph + Twitter Card tags with Yoast integration
- ✅ `Cache-Control: public` for public thumbnails; `private, no-store` for private
- ✅ `<img width height>` attributes from `getimagesize()` on WebP thumbs — eliminates CLS

## Image Sitemap (class-gallery-sitemap.php, added 2026-07-08)
- URL: `https://parfocalmedia.ca/cgm-images.xml` (+ `/?cgm_image_sitemap=1` fallback)
- Lists every **public** published gallery page with one `<image:image>` per photo,
  using the exact same WebP `?cgm_thumb` URLs as the on-page `<img>` tags.
  Private galleries fully excluded (verified live: 15 galleries, 2,317 images, 0 private leaked).
- **Filename gotcha:** must NOT end in `-sitemap.xml` — Yoast's rewrite
  `([^/]+?)-sitemap([0-9]+)?\.xml$` swallows any such URL first (found live: the original
  `/cgm-image-sitemap.xml` 404'd through Yoast).
- Submitted to GSC 2026-07-08 via the API (`sc-domain:parfocalmedia.ca`). **Why it matters,
  from real GSC data:** in the 90 days before this, individual gallery pages had essentially
  zero Google Images impressions (index page: 2) while service pages got hundreds — the
  watermarked images served fine to Googlebot but were never discovered at scale.
- The `robots_txt` filter appends the Sitemap line only to WP-*generated* robots.txt;
  parfocal has a physical/custom robots.txt so that filter is inert there — GSC submission
  covers Google; add the line to the real robots.txt manually if Bing ever matters.

## GA4 analytics (gallery-analytics.js, added 2026-07-08)
Events fire through the page's own `gtag()` (Site Kit loads it site-wide), dataLayer
fallback for GTM — same no-hardcoded-ID pattern as the Forminator bridge:
- `gallery_image_view` — every image shown full-size in the lightbox. One
  MutationObserver on the lightbox `<img>` src catches open + arrows + keyboard +
  swipe uniformly. Carries `image_index` + `total_images` (from `data-cgm-total` on
  the grid div) so view-depth is queryable ("looked at 45 of 228").
- `gallery_image_download` / `gallery_zip_download` — download clicks, split by URL.
- `gallery_unlock` / `gallery_unlock_failed` (`unlock_scope: view|download`) — did
  clients actually get in. View outcome detected via a sessionStorage flag across
  the POST reload (grid rendered = success, form again = wrong password). Download
  unlock fires at submit time with beacon transport (success 302s straight into the
  file attachment, no reload to hook) and also credits the intended download event
  with `via_unlock: 1`; a wrong password re-renders with the error attribute →
  `gallery_unlock_failed`, netting out the optimistic submit-time count.
- `gallery_share` (`share_method: native|copy_link|facebook`).
- All events carry `gallery_slug` + `gallery_id` (via `wp_localize_script`).
- Outbound share URLs were ALREADY UTM-tagged (`utm_campaign=gallery_share`,
  `utm_content={slug}`) — that existed before this.
- **Verified deployed** (script + settings + data-cgm-total in live HTML) but
  **live event flow not yet verified** — needs a real browser visit; check GA4
  Realtime (`run_realtime_report` on eventName) while someone browses a gallery.
  To make these show as metrics in standard reports later, register `gallery_slug`
  (and optionally `image_index`/`total_images`) as custom dimensions in GA4 Admin
  (not done — events collect fine without it).

## Fixed: year-long cover-thumb cache could get stuck broken (0.3.2, 2026-08-08)

**Symptom:** Allen spotted broken image icons for some galleries' cover thumbs on the `event-photography` client-gallery index block on mobile (Proptech, Tommy's Going Away), while others (Girl Bar Vol.2) rendered fine.

**Root cause:** `class-gallery-download.php`'s `handle_thumb()` and `handle_cover_thumb()` both sent `Cache-Control: public, max-age=31536000` (1 year) with no ETag/Last-Modified validator. Confirmed via curl that the live endpoint was already serving valid, correctly-sized `image/webp` bytes for the "broken" galleries - the server-side data was fine. Cloudflare wasn't caching it either (`cf-cache-status: DYNAMIC`). The only place a bad result could be stuck was the visitor's own browser: if a phone ever loaded a given `gallery_id`'s cover before its thumbnail existed (or during any earlier broken window - the thumbnail generation pipeline moved to the async `cgm-thumbd` service in 0.3.0, so a first-page-load race was plausible), the browser had zero way to know that URL now returns something different for up to a year.

**Fix:** both cache headers reduced to `max-age=86400` (1 day) - matches the cache lifetime `CGM_Gallery_Storage` already uses for its own public-thumb case, not a new pattern. Deployed live via SCP + `php -l` syntax check, verified via curl (`Cache-Control: public, max-age=86400` now on the live response). **Not committed to git yet** - working tree has this plus the still-pending 0.3.0 commits noted below.

**If this recurs:** ask whoever's seeing it to try an incognito/private tab or clear site data for parfocalmedia.ca first - that isolates "stale browser cache" from "actually broken on the server" in about 10 seconds, before spending time on server-side diagnosis.

## Download password re-prompts after a correct password (diagnosed 2026-10-16, fix STAGED, not deployed)
**Symptom (Allen watched a client):** after entering the correct download password, the modal stays open and every Download click asks again. On Android the file does download, so the unlock worked, but there's no confirmation.
**Root cause:**
- On success, `handle_download_password_submission()` 302'd straight to the file URL.
- A navigation that ends in `Content-Disposition: attachment` never replaces the current document, so the page stayed as rendered: modal open, field filled.
- `cgmPasswordSettings.downloadUnlocked` / `data-cgm-download-unlocked` stayed `0` until a manual reload, so `password-modal.js` kept intercepting clicks even though the unlock cookie was set.

**Fix:**
- PHP redirects back to the gallery permalink instead, with `cgm_grant_dl` (plus `cgm_grant` if a view grant is active) and `cgm_dl_next=<validated intended URL>`.
- The template adds `data-cgm-start-download` (re-validated, plus the download grant) when unlocked.
- JS shows a `.cgm-toast` saying "Downloads unlocked. Your download is starting…", strips `cgm_dl_next` via `replaceState`, then navigates to the file.
- `password-modal.js` enqueue bumped 0.1.4 → 0.1.5.
- Analytics unchanged: the unlock is still tracked at submit, and the auto-start is a navigation, not a click, so it isn't double-counted.

**Status:**
- Local repo edited: `client-gallery.php`, `class-gallery-access.php`, `password-modal.js`, and an analytics comment.
- **Local can NOT be scp'd as-is.** Live vs local differs far beyond this fix: undeployed hidden-gallery work, different asset versions, 177-line diff in `class-gallery-access.php`.
- A live-only patch (applied to fresh copies of the live files, `php -l` + `node --check` clean) is saved at `patches/2026-10-16-download-unlock-live.diff`. That's 148 lines over `client-gallery.php`, `class-gallery-access.php` and `password-modal.js`. Re-pull live and re-check it applies before using it.
- 2026-10-16 progress:
  - Local branch `live-snapshot-2026-10-16` (commit `5f2d98d`, made in a worktree) = exact tar copy of the live plugin dir. It shows CLAUDE.md and `server/` as deleted because live doesn't carry them.
  - Committing local work to `download-unlock-fix` and pushing either branch to GitHub were **blocked by the auto-mode classifier ("Out-of-Place Publication")**. Git commit/push in this repo needs manual approval mode, same as deploys.
  - The patch doesn't touch password hashes, meta, cookies or `try_unlock_*` (grep-verified), so existing download passwords and unlock cookies stay valid.
- Deploy needs manual approval mode. Back up the live files to `/tmp/*.bak-YYYYMMDD` first.
- Test plan: correct password on desktop, Android, iOS, and FB/IG in-app browser; wrong password; Download all; refresh after the download.

## Fixed: iOS single-image download went to Files, not Photos (2026-09-23)

**Symptom:** on iPhone/iPad the lightbox Download button saved the photo to Files > Downloads instead of Photos; Android was fine.
**Root cause:** `handle_download()` sends `application/octet-stream` + `Content-Disposition: attachment`; iOS Safari treats that as a generic file and never offers Photos.
**Fix:** `gallery-lightbox.js` (script version 0.1.7) - iOS-only click handler fetches the original and calls `navigator.share({files})` so the share sheet offers "Save Image". Any failure/unsupported case falls back to the old plain download; Android/desktop never run it. PHP endpoint untouched. Deployed live 2026-09-23 (JS uploaded + live `client-gallery.php` line 78 bumped 0.1.6 -> 0.1.7 by `sed`; local PHP was NOT uploaded because it differs from live beyond that line - reconcile before the next full deploy).
**Status:** the share-sheet approach above was **superseded the same day (0.1.8)**: Chrome iOS / Meta webviews don't reliably support file sharing, and Apple has no "download straight to Photos" at all - only long-press on an inline image or the share sheet. Also learned: the lightbox shows the watermarked WebP *thumb*, so long-pressing it saves the thumb, not the original. Current design: on iOS the JS appends `&inline=1` to the single-image download URL (and drops the anchor's `download` attr, which forces Files); `handle_download()` then serves a small HTML page with the full original as `<img src=...&raw=1>` (`Content-Type: image/jpeg`, `Content-Disposition: inline`) and a "Press and hold the photo, then tap Save to Photos" hint. Works for locked galleries too (post-password redirect keeps the query string + grant). ZIP unchanged (can't go to Photos). Deployed live 2026-09-23 (download PHP + lightbox JS + live line 78 version -> 0.1.8; backup of previous download PHP at `/tmp/class-gallery-download.php.bak-20260923` on funheim). **Not yet verified on a real iPhone.**

## Long-press notice (2026-09-23, script 0.1.9 / lightbox.css 0.1.18)
Allen doesn't want people saving the watermarked thumbs via long-press. `gallery-lightbox.js` shows a `.cgm-toast` ("To save this photo, use the Download button.") after a 450ms hold (touch, cancelled by >10px move) on `.cgm-grid` / `.cgm-lightbox-image-wrap`, and on `contextmenu` (right-click / Android long-press, still `preventDefault`ed). `lightbox.css` adds a transparent `::after` shield over the lightbox image wrap so iOS long-press hits a div, not the `<img>`, plus `-webkit-touch-callout:none` on `.cgm-lightbox-trigger`. Can't be made airtight (screenshots, browsers ignoring the callout flag) - the original stays behind the Download button. Deployed live; previous CSS backed up at `/tmp/lightbox.css.bak-20260923` on funheim. **Not yet verified on a real iPhone.**
**Deploy gotcha:** `ssh myserver "...\"...\""` with nested double quotes from PowerShell hangs (remote shell waits on an unterminated quote) - use a single-quoted PS string and avoid quotes on the remote side (e.g. `sed -i -e 64s/a/b/ file`).

## iOS tip under the top buttons (2026-09-24, deployed live)
`client-gallery.php`, just before `.cgm-gallery-header` closes: a `<p class="cgm-ios-tip" hidden>` ("On iPhone or iPad? Tap a photo, then tap "Download image". Press and hold the photo that opens and tap "Save to Photos". Any trouble, message me on [Instagram](https://www.instagram.com/parfocalmedia/) or use the [contact form](home_url('/contact/')).") plus a tiny inline script that unhides it using the same iOS test as `gallery-lightbox.js`. It points at the per-photo button, not the ZIP, because a ZIP on iOS lands in Files. Allen chose the fallback contact routes: Instagram plus the contact page, no email or phone. The site's optimizer rewrites the inline script as a deferred `data:` script, which is fine because it still runs after parse.
**Deploy:** patched a copy of the *live* file (live PHP still differs from local beyond this, so never upload the local one) and wrote it in place after `php -l`. The same edit is in the local repo copy. Backup: `/tmp/client-gallery.php.bak-20260924` on funheim. Verified: gallery page returns 200, `cgm-ios-tip` is in the live HTML, and debug.log has no new errors. **Not yet seen on a real iPhone.** The first deploy attempt was blocked by Claude Code's auto-mode production-deploy guard, and went through once Allen approved it explicitly in chat.

## Pending / Future
- ⏳ Real-iPhone verification of the 0.1.8 inline "Save to Photos" flow, on open **and** password-protected galleries. (An older line here said locked galleries still land in Files. The 0.1.8 section above says the post-password redirect keeps `&inline=1`, so they should work too. That's unverified, not confirmed.)
- ⏳ Zero-padded Lightroom filenames for correct `scandir()` sort order
- ⏳ Theme colour picker for tile bg not pulling child-theme palette correctly (investigate `withSelect` + `core/block-editor` store timing)
- ⏳ Watch GSC over the next 2-4 weeks: image sitemap processing status + whether gallery pages start earning Google Images impressions (compare against the ~zero baseline above)
- ⏳ Real-device test of the Meta in-app-browser fix (URL grants) — the curl simulation is faithful (zero cookies) but not yet confirmed on an actual Instagram/Facebook in-app browser; do a real share-link → password → view pass next time Allen shares a client gallery
- ⏳ Verify GA4 gallery events fire in practice, not just in the deployed HTML — `gallery_image_view`/`_download`, `gallery_zip_download`, `gallery_unlock`/`_failed`, `gallery_share` are all deployed and confirmed present in the live page's script/localize data, but the actual event flow needs a real browser visit (check `run_realtime_report` on eventName while someone browses a gallery). Optionally register `gallery_slug` (+ `image_index`/`total_images`) as GA4 custom dimensions for nicer reports.
- ⏳ Repo has commits ahead of GitHub (`1f3dab2`, `96b17b5`, `24718e4`, `bbc5fdb`) — push whenever Allen wants it on the remote

---

## Known Conventions
- All class names prefixed `CGM_Gallery_*`
- All function names prefixed `cgm_`
- All post meta keys prefixed `_cgm_`
- All action/filter hooks prefixed `cgm_`
- No external PHP dependencies — pure WP core
- No build tools — edit CSS/JS directly

## Hidden (link-only) galleries (2026-10-05, built locally, NOT deployed)
Allen's request: a client wants a super-private delivery; gallery must not appear anywhere, only via the exact link + password. Checkbox "Hidden (link only)" in the Gallery Access metabox, meta `_cgm_hidden = '1'`. All logic lives in `includes/` (live and local `includes/*.php` were md5-identical on 2026-10-05; only `client-gallery.php` differs - so these two files can be uploaded as-is, the main file must not be).
- `CGM_Gallery_Access::gallery_is_private()` now also returns true for hidden -> existing noindex, plugin sitemap skip, OG-tag skip, `Cache-Control: private, no-store` on thumbs all apply automatically.
- `pre_get_posts`: hidden galleries dropped from every non-singular `client_gallery` query on front end + REST (index block, `/feed/?post_type=client_gallery`, REST collection). wp-admin lists and editors-in-REST unaffected. Bypass arg `cgm_include_hidden`.
- `rest_prepare_client_gallery`: 404 on `/wp-json/wp/v2/client_gallery/<id>` for non-editors.
- `template_redirect` prio 1: 404 when a hidden gallery is reached by `?p=<id>` (otherwise `redirect_canonical` would 301 to the pretty URL and leak the slug - IDs are sequential).
- Yoast: `wpseo_exclude_from_sitemap_by_post_ids` (Yoast's `client_gallery-sitemap.xml` listed all 19 galleries incl. protected ones), `wpseo_opengraph_image`/`wpseo_twitter_image` stripped.
- `handle_cover_thumb` (`class-gallery-download.php`): 404 for hidden galleries unless `edit_post`.
- **Leaks found live before this (2026-10-05):** REST listed all 19 galleries unauthenticated; RSS feed listed 10; Yoast sitemap listed 19; cover endpoint had no access check.
- **Usage caveats:** don't set a WP *featured image* on a hidden gallery (Yoast's schema JSON-LD can still reference it - not filtered); use a non-guessable slug; always pair with a viewing password (hidden alone is security-by-obscurity).
- **Unverified until deployed + tested:** whether returning `''` from `wpseo_opengraph_image` fully suppresses the tag in this Yoast version; whether Yoast's robots output honours our `wp_robots` noindex. Test plan: tick Hidden on a throwaway gallery, then curl REST list/single, feed, Yoast sitemap, `?p=<id>`, cover endpoint, and the page head (`og:image`, `robots`).
- Deploy: blocked by Claude Code's auto-mode production-deploy guard 2026-10-05, awaiting Allen's explicit OK. Backup pattern: `/tmp/<file>.bak-20261005` on funheim.
- Not committed to git - `class-gallery-download.php` also carries older uncommitted edits, commit deliberately.

## Access audit of protected galleries (2026-10-05)
Allen asked whether password galleries had been downloaded nefariously. Source: funheim nginx `/var/log/nginx/access.log*` (shared by all sites, logrotate `daily`/`rotate 14` -> **only 2026-09-21..10-05 exists**). Protected set from WP: view-password = 2392 ten-oaks-bowlathon, 2393 ten-oaks-camp, 2408 burlesque-selection; download-password hash present on 16 (2366 2392 2393 2396 2397 2408 2410 2412 2579 2589 2593 2776 2778 2785 2788 2789).
- **No successful download of any protected gallery.** All hits on protected IDs were 403 (2366 ZIP x2, 2396 x1, 2397 x4 - Chinese cloud-range scrapers). Every 200 download was on public galleries 2391 drag-brunch, 2782, 2950 (2391 heavily scraped by Tencent/Huawei-cloud IPs - allowed by design, it's public).
- **View-protected galleries: 0 photo thumbs served**; 14 hits were all `cgm_cover_thumb` (the known public-cover leak, one image each).
- **0 password POSTs** to any gallery in the window -> no brute-forcing seen (also no legit unlocks).
- Endpoint mix over 15 days: 44,228 `cgm_thumb`, 161 `cgm_cover_thumb`, 100 `cgm_download`, 17 `cgm_download_all`.
- GA4 (`gallery_unlock`/`_failed`, download events) would extend history past 15 days but the GA4 MCP hit the known `invalid_grant` expiry - re-login per [[dev/seo-tools/CLAUDE|dev/seo-tools]].
- Re-run: `sudo zcat -f /var/log/nginx/access.log* | grep cgm_download` on funheim, split by `gallery_id` + status.
- **Gotcha running multi-line audit scripts:** piping a local script via PowerShell `Get-Content | ssh myserver 'bash -s'` fails (UTF-8 BOM -> `﻿sudo: command not found`, plus CRLF). Git-Bash `ssh` has no key (permission denied). Working pattern: read file, strip `\r`, base64 it, `ssh myserver "echo $b64 | base64 -d | bash"` from PowerShell.

### Electromate 40th (gallery 2953) download check (2026-10-08)
Triggered by an unverified download request from a personal-Gmail "newsletter editor" (Allen is holding the password pending OK from client contact Jennifer). Logs cover 03-08 Oct.
- **All full-res downloads:** 06 Oct, `142.112.201.92` = Allen's own public IP (verified via ipify): 2 ZIP parts (160 MB + 95 MB) + 2 single images. 07 Oct, `2600:100d:a0f0:...` (mobile IPv6, Firefox/Windows): 2 POST 302s on the gallery page, then **one image (8.4 MB) downloaded twice**. Nothing else.
- **Viewing:** ~20 distinct Canadian residential/corporate IPs each loaded the full ~179 thumbs on 07 Oct, plus Microsoft-range hits (`72.152/72.153/74.179/135.232.*` loading 24 thumbs each, `2a01:111:*` HEADs): looks like Outlook/Safe Links scanning. Consistent with the link being emailed around inside an M365 org that day. Also ~100 OVH-Canada IPs pulling 1 thumb each = a crawler.
