#!/usr/bin/env python3
"""
cgm-thumbd - server-side thumbnail generator for the Client Gallery plugin.

Generates the watermarked WebP thumbnails that the WordPress plugin used to
build inline in PHP-FPM. Bulk generation inside PHP requests pegged the CPU
and took the site down; this runs the same work out-of-band under systemd
with idle CPU/IO priority instead.

Contract with the plugin (see class-gallery-storage.php):
  {install_dir}/config.ini   - paths + watermark settings (this file's dir)
  {install_dir}/queue/       - plugin touches {gallery-folder} files here to
                               request priority processing
  {install_dir}/status.json  - written every run; the plugin treats the
                               service as ACTIVE when this is fresh (<5 min)
                               and then never generates thumbs in PHP.

Run:  python3 cgm_thumbd.py --once     (what the systemd timer does)

Watermark/output parity with the plugin's GD implementation:
  - resize to max width 1080, never upscale
  - watermark text bottom-right, font size max(12, 3.5% of width),
    padding max(10, 2% of width), white at ~45% opacity
  - skip watermark on images smaller than 200x150
  - WebP quality 80, thumb filename = {original}.webp
Improvement over GD: EXIF orientation is applied (no-op for Lightroom
exports with baked rotation).
"""

import argparse
import configparser
import fcntl
import json
import os
import sys
import time

from PIL import Image, ImageDraw, ImageFont, ImageOps

INSTALL_DIR = os.path.dirname(os.path.abspath(__file__))
CONFIG_PATH = os.path.join(INSTALL_DIR, "config.ini")
QUEUE_DIR = os.path.join(INSTALL_DIR, "queue")
STATUS_PATH = os.path.join(INSTALL_DIR, "status.json")
LOCK_PATH = os.path.join(INSTALL_DIR, ".lock")

TARGET_WIDTH = 1080
WEBP_QUALITY = 80
# GD alpha 70 on a 0(opaque)-127(transparent) scale ~= 45% opacity.
WATERMARK_ALPHA = 114  # of 255
SKIP_WATERMARK_UNDER = (200, 150)
IMAGE_EXTS = {".jpg", ".jpeg", ".png", ".webp"}


def load_config():
    cfg = configparser.ConfigParser()
    cfg.read(CONFIG_PATH)
    section = cfg["cgm-thumbd"]
    return {
        "storage_path": section.get("storage_path"),
        "font_path": section.get("font_path"),
        "watermark_text": section.get("watermark_text", "Parfocal Media"),
    }


def list_gallery_folders(storage_path):
    try:
        entries = sorted(os.listdir(storage_path))
    except OSError:
        return []
    return [
        name for name in entries
        if os.path.isdir(os.path.join(storage_path, name, "original"))
    ]


def pending_files(storage_path, folder):
    """Yield (original_path, thumb_path) pairs missing a thumbnail."""
    original_dir = os.path.join(storage_path, folder, "original")
    thumbs_dir = os.path.join(storage_path, folder, "thumbs")

    try:
        names = sorted(os.listdir(original_dir))
    except OSError:
        return

    for name in names:
        ext = os.path.splitext(name)[1].lower()
        if ext not in IMAGE_EXTS:
            continue
        source = os.path.join(original_dir, name)
        if not os.path.isfile(source):
            continue
        thumb = os.path.join(thumbs_dir, name + ".webp")
        if not os.path.exists(thumb):
            yield source, thumb


def apply_watermark(img, text, font_path):
    w, h = img.size
    if w < SKIP_WATERMARK_UNDER[0] or h < SKIP_WATERMARK_UNDER[1]:
        return img

    pad = max(10, int(w * 0.02))
    font_size = max(12, int(w * 0.035))

    try:
        font = ImageFont.truetype(font_path, font_size)
    except OSError:
        font = ImageFont.load_default()

    overlay = Image.new("RGBA", img.size, (0, 0, 0, 0))
    draw = ImageDraw.Draw(overlay)
    # anchor "rs" = right-aligned, baseline — matches GD's imagettftext
    # placement at (w - text_w - pad, h - pad).
    draw.text(
        (w - pad, h - pad),
        text,
        font=font,
        fill=(255, 255, 255, WATERMARK_ALPHA),
        anchor="rs",
    )

    return Image.alpha_composite(img.convert("RGBA"), overlay)


def generate_thumbnail(source, dest, watermark_text, font_path):
    with Image.open(source) as img:
        img = ImageOps.exif_transpose(img)

        if img.width > TARGET_WIDTH:
            scale = TARGET_WIDTH / img.width
            new_size = (TARGET_WIDTH, max(1, int(img.height * scale)))
            img = img.resize(new_size, Image.LANCZOS)

        img = apply_watermark(img, watermark_text, font_path)

        os.makedirs(os.path.dirname(dest), exist_ok=True)
        img.convert("RGB" if not img.mode.startswith("RGBA") else "RGBA").save(
            dest, "WEBP", quality=WEBP_QUALITY
        )


def read_queue():
    """Return queued gallery folder names (and clear the queue entries)."""
    try:
        names = sorted(os.listdir(QUEUE_DIR))
    except OSError:
        return []

    queued = []
    for name in names:
        path = os.path.join(QUEUE_DIR, name)
        if os.path.isfile(path):
            queued.append(name)
            try:
                os.unlink(path)
            except OSError:
                pass
    return queued


def write_status(generated, pending, errors):
    status = {
        "last_run": int(time.time()),
        "last_run_iso": time.strftime("%Y-%m-%dT%H:%M:%S%z"),
        "generated": generated,
        "pending": pending,
        "errors": errors,
    }
    tmp = STATUS_PATH + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(status, f)
    os.replace(tmp, STATUS_PATH)


def run_once(max_seconds):
    cfg = load_config()
    storage = cfg["storage_path"]

    os.makedirs(QUEUE_DIR, exist_ok=True)

    # Queued galleries first, then everything else.
    queued = read_queue()
    all_folders = list_gallery_folders(storage)
    ordered = [f for f in queued if f in all_folders]
    ordered += [f for f in all_folders if f not in ordered]

    started = time.time()
    generated = 0
    errors = 0
    out_of_time = False

    for folder in ordered:
        for source, thumb in pending_files(storage, folder):
            if time.time() - started > max_seconds:
                out_of_time = True
                break
            try:
                generate_thumbnail(source, thumb, cfg["watermark_text"], cfg["font_path"])
                generated += 1
            except Exception as exc:  # noqa: BLE001 - keep the sweep alive
                errors += 1
                print(f"error: {source}: {exc}", file=sys.stderr)
        if out_of_time:
            break

    # Count whatever is still pending after this pass.
    pending = sum(
        1 for folder in all_folders for _ in pending_files(storage, folder)
    )

    write_status(generated, pending, errors)
    print(f"generated={generated} pending={pending} errors={errors}")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--once", action="store_true",
                        help="single pass (what the systemd timer runs)")
    parser.add_argument("--max-seconds", type=int, default=50,
                        help="time budget per pass (default 50, under the 60s timer)")
    args = parser.parse_args()

    if not args.once:
        parser.error("only --once mode is supported; scheduling is systemd's job")

    # Prevent overlapping runs if a pass exceeds the timer interval.
    with open(LOCK_PATH, "w", encoding="utf-8") as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            print("previous run still active; skipping")
            return
        run_once(args.max_seconds)


if __name__ == "__main__":
    main()
