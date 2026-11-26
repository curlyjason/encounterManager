#!/bin/sh
# Build a clean zip to give to someone else: no Notion key, no encounters, no editor files.
#   sh tools/package.sh      → dist/encounterManager-<VERSION>.zip
set -e
cd "$(dirname "$0")/.."
NAME=encounterManager
VERSION="$(tr -d '[:space:]' < VERSION)"
OUT="dist/$NAME-$VERSION.zip"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p dist "$STAGE/$NAME"
rsync -a \
    --exclude '.git/' --exclude '.idea/' --exclude '.vscode/' --exclude '.DS_Store' \
    --exclude 'dist/' --exclude 'tools/' --exclude '.gitignore' \
    --exclude 'lib/config.php' --exclude 'lib/config.backup.php' --exclude 'lib/config.php.tmp' \
    --exclude 'data/*.sqlite' --exclude 'data/*.sqlite-*' \
    --exclude 'data/update-check.json' --exclude 'data/adb-monsters.json' --exclude 'data/adb-spells.json' --exclude 'data/my-images/' --exclude 'data/image-cache/' --exclude 'data/image-manifest.json' --exclude 'data/*.zip' --exclude '/images/' --exclude 'data/backups/' --exclude 'data/updates/' \
    ./ "$STAGE/$NAME/"

# Safety net: never ship a key or a database.
if grep -rlE 'ntn_[A-Za-z0-9]{20,}|secret_[A-Za-z0-9]{20,}|sk-[A-Za-z0-9_-]{20,}' "$STAGE/$NAME" >/dev/null 2>&1; then
    echo "Refusing to package: something that looks like a Notion or OpenAI key is in:" >&2
    grep -rlE 'ntn_[A-Za-z0-9]{20,}|secret_[A-Za-z0-9]{20,}|sk-[A-Za-z0-9_-]{20,}' "$STAGE/$NAME" >&2
    exit 1
fi
if find "$STAGE/$NAME" -name '*.sqlite*' | grep -q .; then
    echo "Refusing to package: a database file slipped in" >&2
    exit 1
fi

chmod +x "$STAGE/$NAME/start.command"
rm -f "$OUT"
(cd "$STAGE" && zip -qr -X "$OLDPWD/$OUT" "$NAME")
echo "Built $OUT"
unzip -l "$OUT" | tail -n +4 | sed '$d' | sed '$d' | awk '{print "  " $4}'
