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
NAME="unraid-vitals"
SRC="$ROOT/src/usr/local/emhttp/plugins/$NAME"
AUTHOR="BeinnoLLC"
GITHUB="${AUTHOR}/${NAME}"
ARCH="x86_64"
BUILD="1"
PKGNAME="${NAME}-${VERSION}-${ARCH}-${BUILD}"
DIST="$ROOT/dist"

rm -rf "$DIST"
mkdir -p "$DIST"

# ---------------------------------------------------------------- CRLF gate -
# A Windows checkout without .gitattributes honoured (or a stray CRLF commit)
# packages shell scripts that fail on Unraid with "bad interpreter: ^M". Fail
# the build before staging rather than shipping a broken .txz.
CRLF_FOUND=0
while IFS= read -r -d '' f; do
  if grep -qU $'\r' "$f" 2>/dev/null; then
    echo "CRLF line endings in $f — refusing to build a payload that will fail on Unraid." >&2
    CRLF_FOUND=1
  fi
done < <(git -C "$ROOT" ls-files -z -- '*.sh' '*.php' '*.page' '*.mjs')
if [ "$CRLF_FOUND" -ne 0 ]; then
  echo "fix: run 'git add --renormalize .' after .gitattributes forces LF, then retry." >&2
  exit 1
fi

# ---------------------------------------------------------------- payload ---
# Stage with the on-server layout so `upgradepkg --install-new` drops the files
# exactly where Unraid expects them.
STAGE="$DIST/stage"
mkdir -p "$STAGE/usr/local/emhttp/plugins/$NAME"
# Exclude agent/node_modules from the payload: it is 386 MB, and the agents
# provision their own deps via install.sh's npm ci bootstrap (see install.sh:
# agent deps bootstrap). Shipping it would bloat the txz ~380x and make the
# flash drive hold a second copy of every dependency on every release.
cp -r "$SRC/." "$STAGE/usr/local/emhttp/plugins/$NAME/"
rm -rf "$STAGE/usr/local/emhttp/plugins/$NAME/agent/node_modules"
chmod +x "$STAGE/usr/local/emhttp/plugins/$NAME/scripts/"*.sh

TXZ="$DIST/${PKGNAME}.txz"
( cd "$STAGE" && tar --owner=0 --group=0 -cJf "$TXZ" usr )
rm -rf "$STAGE"

MD5=$(md5sum "$TXZ" | awk '{print $1}')
SIZE=$(stat -c %s "$TXZ")

# ------------------------------------------------------------------- .plg ---
# Two copies, and they must not drift:
#   dist/<name>.plg          the release asset
#   plugins/<name>.plg       the tracked manifest, and what &pluginURL points at
# The second is the address in this script's own install instructions (and the
# one users paste into `plugin install`). For a while the build only wrote the
# first, so the tracked manifest sat frozen at an old version and every release
# after it installed the older payload. Write both, from one build.
PLG="$DIST/$NAME.plg"
sed -e "s|@VERSION@|$VERSION|g" \
    -e "s|@MD5@|$MD5|g" \
    -e "s|@GITHUB@|$GITHUB|g" \
    -e "s|@PKGNAME@|$PKGNAME|g" \
    "$ROOT/build/plugin.plg.template" > "$PLG"
cp "$PLG" "$ROOT/plugins/$NAME.plg"

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
