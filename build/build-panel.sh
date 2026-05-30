#!/usr/bin/env bash
#
# Assemble an installable control-panel extension zip from the shared,
# panel-agnostic core (core/) plus a single panel adapter (panels/<panel>/).
#
# Panel extensions are self-contained packages with no runtime Composer
# dependencies, so the core cannot merely be *referenced* — it must be
# physically copied into the package at build time. This script does exactly
# that, producing a zip whose layout is identical to the historic single-repo
# build (meta.xml + plib/ + htdocs/ + _meta/, with core under plib/library/).
#
# Usage:
#   build/build-panel.sh <panel> <version>
# Example:
#   build/build-panel.sh plesk 0.5.14   ->  dist/cloudflare-dns-sync-0.5.14.zip
#
set -euo pipefail

PANEL="${1:?usage: build-panel.sh <panel> <version>}"
VERSION="${2:?usage: build-panel.sh <panel> <version>}"

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PANEL_DIR="$ROOT/panels/$PANEL"
CORE_DIR="$ROOT/core"
DIST="$ROOT/dist"

[ -d "$PANEL_DIR" ] || { echo "build-panel: unknown panel '$PANEL' ($PANEL_DIR not found)" >&2; exit 1; }
[ -d "$CORE_DIR/library/Cloudflare" ] || { echo "build-panel: core not found at $CORE_DIR/library" >&2; exit 1; }

# The extension id (from the panel's meta.xml) drives the zip names, so every
# panel ships under its own canonical name.
ID="$(sed -n 's:[[:space:]]*<id>\(.*\)</id>.*:\1:p' "$PANEL_DIR/meta.xml" | head -n1)"
[ -n "$ID" ] || { echo "build-panel: could not read <id> from $PANEL_DIR/meta.xml" >&2; exit 1; }

OUT="$DIST/$ID-$VERSION.zip"   # versioned, immutable per release
ALIAS="$DIST/$ID.zip"          # stable name for releases/latest/download/<id>.zip

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

# 1) Panel files, in Plesk extension layout.
cp -R "$PANEL_DIR/meta.xml" "$PANEL_DIR/plib" "$PANEL_DIR/htdocs" "$PANEL_DIR/_meta" "$STAGE/"

# 2) Inject the shared core alongside the panel adapter under plib/library/.
#    The autoloader maps Noiz\CloudflareDns\ to its own dir (__DIR__), so once
#    Cloudflare/ and PleskDns/ sit together under plib/library/ both resolve.
cp -R "$CORE_DIR/library/Cloudflare" "$STAGE/plib/library/"
cp "$CORE_DIR/library/autoload.php" "$STAGE/plib/library/autoload.php"

# 3) Package — source.svg is an icon-authoring artefact, not shipped.
mkdir -p "$DIST"
rm -f "$OUT" "$ALIAS"
( cd "$STAGE" && zip -rq "$OUT" meta.xml plib htdocs _meta -x '_meta/icons/source.svg' )
cp "$OUT" "$ALIAS"

echo "build-panel: built $OUT (+ stable alias $ALIAS)"
