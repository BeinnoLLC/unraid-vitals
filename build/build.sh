#!/bin/bash
#
# unraid-vitals — build a release.
#
#   ./build/build.sh 2026.09.28
#
# Produces, in dist/:
#   unraid-vitals-<version>-x86_64-1.txz   the payload
#   unraid-vitals.plg                      the plugin manifest, checksums filled in
#   unraid-vitals-<version>.md5            checksums, for the release notes
#
# The .plg is what users install. It points at the .txz and verifies its MD5.

set -euo pipefail

VERSION="${1:-}"
if [ -z "$VERSION" ]; then
  echo "usage: $0 <version>          e.g. $0 2026.09.28" >&2
  exit 1
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$ROOT/src"
NAME="unraid-vitals"
AUTHOR="hazemhagrass"
GITHUB="${AUTHOR}/${NAME}"
ARCH="x86_64"
BUILD="1"
PKGNAME="${NAME}-${VERSION}-${ARCH}-${BUILD}"
DIST="$ROOT/dist"

rm -rf "$DIST"
mkdir -p "$DIST"

# ---------------------------------------------------------------- payload ---
# Stage with the on-server layout so `upgradepkg --install-new` drops the files
# exactly where Unraid expects them.
STAGE="$DIST/stage"
mkdir -p "$STAGE/usr/local/emhttp/plugins/$NAME"
cp -r "$SRC/." "$STAGE/usr/local/emhttp/plugins/$NAME/"
chmod +x "$STAGE/usr/local/emhttp/plugins/$NAME/scripts/"*.sh

TXZ="$DIST/${PKGNAME}.txz"
( cd "$STAGE" && tar --owner=0 --group=0 -cJf "$TXZ" usr )
rm -rf "$STAGE"

MD5=$(md5sum "$TXZ" | awk '{print $1}')
SIZE=$(stat -c %s "$TXZ")

# ------------------------------------------------------------------- .plg ---
PLG="$DIST/$NAME.plg"
sed -e "s|@VERSION@|$VERSION|g" \
    -e "s|@MD5@|$MD5|g" \
    -e "s|@GITHUB@|$GITHUB|g" \
    -e "s|@PKGNAME@|$PKGNAME|g" \
    "$ROOT/build/plugin.plg.template" > "$PLG"

# ---------------------------------------------------------------- checksums -
(
  cd "$DIST"
  md5sum "$(basename "$TXZ")" > "${NAME}-${VERSION}.md5"
  md5sum "$(basename "$PLG")" >> "${NAME}-${VERSION}.md5"
)

echo "built $PKGNAME"
echo "  txz   $TXZ  ($(numfmt --to=iec "$SIZE"))"
echo "  md5   $MD5"
echo "  plg   $PLG"
echo
echo "next:"
echo "  git add -A && git commit -m 'release $VERSION' && git tag v$VERSION && git push --tags"
echo "  gh release create v$VERSION $TXZ $PLG --title 'v$VERSION'"
echo "  then install on the server:"
echo "  plugin install https://raw.githubusercontent.com/$GITHUB/main/plugins/$NAME.plg"
