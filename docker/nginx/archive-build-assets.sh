#!/bin/sh
set -eu

# Copies this image's Vite build into the shared build_archive volume and
# prunes files no release has shipped for BUILD_ARCHIVE_DAYS days.
#
# Nginx falls back to the archive for /build/ requests, so HTML rendered
# by a newer or older app container keeps loading its hashed assets while
# the deploy swaps containers, and browsers holding a page from before the
# deploy can still fetch its CSS.

source_dir=/var/www/html/public/build
archive_dir=/var/www/build-archive/build
keep_days="${BUILD_ARCHIVE_DAYS:-14}"

if [ ! -d "$source_dir/assets" ]; then
    echo "ERROR: $source_dir/assets is missing from the web image." >&2
    exit 1
fi

mkdir -p "$archive_dir/assets"

copied=0
for file in "$source_dir"/assets/*; do
    target="$archive_dir/assets/$(basename "$file")"

    if [ -e "$target" ]; then
        # Shipped again: reset its age so pruning never removes a live file.
        touch "$target"
    else
        cp "$file" "$target"
        copied=$((copied + 1))
    fi
done

pruned="$(find "$archive_dir/assets" -type f -mtime +"$keep_days" -print -delete | wc -l)"
total="$(find "$archive_dir/assets" -type f | wc -l)"

echo "Build archive: $copied new, $pruned pruned (older than $keep_days days), $total kept." >&2
