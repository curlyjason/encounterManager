#!/bin/bash
# Publish a new version of Encounter Manager.
#
#   tools/release.sh patch            1.0.0 → 1.0.1   (bug fixes)
#   tools/release.sh minor            1.0.0 → 1.1.0   (new features)
#   tools/release.sh major            1.0.0 → 2.0.0   (big changes)
#   tools/release.sh 1.4.2            an exact version
#
# Options:
#   -n FILE    take the update notes from FILE (Markdown) instead of opening an editor
#   --local    do everything except pushing to GitHub and publishing the release
#
# What it does:
#   1. Writes the new version to VERSION and adds your notes to the top of CHANGELOG.md
#   2. Commits everything and tags it vX.Y.Z
#   3. Builds dist/encounterManager-X.Y.Z.zip (no Notion key, no encounters)
#   4. Pushes to GitHub and creates a release with the zip and notes attached.
#      Copies of the app check those releases and offer the update.
set -euo pipefail
cd "$(dirname "$0")/.."

die() { echo "release: $*" >&2; exit 1; }

BUMP="" NOTES_FILE="" LOCAL=0
while [ $# -gt 0 ]; do
    case "$1" in
        -n) NOTES_FILE="${2:-}"; shift 2 ;;
        --local) LOCAL=1; shift ;;
        -h|--help) sed -n '2,20p' "$0"; exit 0 ;;
        *) BUMP="$1"; shift ;;
    esac
done
[ -n "$BUMP" ] || die "say which version: patch, minor, major or X.Y.Z (see --help)"

command -v git >/dev/null || die "git isn't installed"
git rev-parse --git-dir >/dev/null 2>&1 || die "this folder isn't a git repository yet"
if [ "$LOCAL" = 0 ]; then
    command -v gh >/dev/null || die "the GitHub CLI isn't installed (brew install gh), or use --local"
    gh auth status >/dev/null 2>&1 || die "log in to GitHub first: gh auth login"
    REPO="$(sed -n "s/^const UPDATE_REPO = '\(.*\)';/\1/p" lib/version.php)"
    [ -n "$REPO" ] || die "set UPDATE_REPO in lib/version.php to your GitHub repo (owner/name)"
    ORIGIN="$(gh repo view --json nameWithOwner -q .nameWithOwner 2>/dev/null || true)"
    [ "$ORIGIN" = "$REPO" ] || die "UPDATE_REPO is '$REPO' but this folder pushes to '${ORIGIN:-nothing}'"
fi

# Never release with a Notion key or database in the repo.
if git ls-files | grep -qE '^lib/config(\.backup)?\.php$|\.sqlite'; then
    die "lib/config.php or a database is tracked by git; remove it from the repo first"
fi

# ---- version -----------------------------------------------------------------
CURRENT="$(tr -d '[:space:]' < VERSION)"
IFS=. read -r MA MI PA <<EOF
$CURRENT
EOF
case "$BUMP" in
    patch) NEW="$MA.$MI.$((PA + 1))" ;;
    minor) NEW="$MA.$((MI + 1)).0" ;;
    major) NEW="$((MA + 1)).0.0" ;;
    [0-9]*.[0-9]*.[0-9]*) NEW="$BUMP" ;;
    *) die "unknown version '$BUMP'" ;;
esac
git rev-parse -q --verify "refs/tags/v$NEW" >/dev/null && die "v$NEW already exists"
echo "Releasing $CURRENT → $NEW"

# ---- notes -------------------------------------------------------------------
TMP_NOTES="$(mktemp)"
trap 'rm -f "$TMP_NOTES"' EXIT
if [ -n "$NOTES_FILE" ]; then
    [ -f "$NOTES_FILE" ] || die "no notes file at $NOTES_FILE"
    cp "$NOTES_FILE" "$TMP_NOTES"
else
    LAST_TAG="$(git describe --tags --abbrev=0 2>/dev/null || true)"
    {
        echo "<!-- Write the update notes for $NEW below, in Markdown. Lines like this one are ignored."
        echo "     Save and close the editor to continue; leave it empty to cancel."
        echo "     Commits since ${LAST_TAG:-the start}: -->"
        git log --format='<!-- %s -->' ${LAST_TAG:+"$LAST_TAG"..HEAD} 2>/dev/null | head -40
        echo
        echo "- "
    } > "$TMP_NOTES"
    "${EDITOR:-nano}" "$TMP_NOTES"
fi
# Drop comment lines and leading/trailing blank lines.
NOTES="$(grep -v '^[[:space:]]*<!--.*-->[[:space:]]*$' "$TMP_NOTES" | sed -e '/./,$!d' | sed -e :a -e '/^\n*$/{$d;N;ba' -e '}')"
[ -n "$(echo "$NOTES" | tr -d '[:space:]-')" ] || die "no notes written; cancelled"
printf '%s\n' "$NOTES" > "$TMP_NOTES"

# ---- write VERSION + CHANGELOG ----------------------------------------------
MARK='<!-- new releases go below this line -->'
grep -qF "$MARK" CHANGELOG.md || die "CHANGELOG.md is missing the line: $MARK"
echo "$NEW" > VERSION
ENTRY="$(mktemp)"
{ echo; echo "## $NEW — $(date +%Y-%m-%d)"; echo; cat "$TMP_NOTES"; } > "$ENTRY"
awk -v mark="$MARK" -v entry="$ENTRY" '
    { print }
    $0 == mark { while ((getline line < entry) > 0) print line }
' CHANGELOG.md > CHANGELOG.md.tmp && mv CHANGELOG.md.tmp CHANGELOG.md
rm -f "$ENTRY"

# ---- commit, tag, build --------------------------------------------------------
git add -A
git commit -q -F - <<EOF
Release v$NEW

$NOTES
EOF
git tag -a "v$NEW" -F "$TMP_NOTES"
sh tools/package.sh
ZIP="dist/encounterManager-$NEW.zip"
[ -f "$ZIP" ] || die "the package wasn't built"

cp "$TMP_NOTES" "dist/notes-$NEW.md"
if [ "$LOCAL" = 1 ]; then
    echo "Done locally: committed, tagged v$NEW, built $ZIP. Push and publish later with:"
    echo "  git push --follow-tags && gh release create v$NEW $ZIP --title v$NEW --notes-file dist/notes-$NEW.md"
    exit 0
fi

# ---- publish ---------------------------------------------------------------------
git push -q --follow-tags
gh release create "v$NEW" "$ZIP" --title "v$NEW" --notes-file "$TMP_NOTES"
echo "Published v$NEW: copies of the app will offer the update within a few hours (or at once from What's new → Check again)."
