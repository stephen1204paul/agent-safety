#!/usr/bin/env bash
#
# build-zip.sh — the single allowlisted build for the Agent Safety plugin.
#
# Builds a WordPress-installable zip from plugin/ + the vendored core
# library, using an explicit allowlist for what goes in, and an
# independent verification pass over the finished zip's entry list that
# fails (naming the offending paths) if anything outside the allowlist
# slipped through.
#
# Usage:
#   bin/build-zip.sh [--restore-dev-deps]
#
#   --restore-dev-deps   After building, re-run `composer install` (with
#                         dev requires) in plugin/ so a local checkout is
#                         left usable for `vendor/bin/phpunit -c
#                         plugin/phpunit.xml.dist` again. Without this
#                         flag plugin/vendor is left in the --no-dev state
#                         the build produced (this is what CI wants: it
#                         throws the checkout away afterwards). Local
#                         callers who want to keep running the plugin
#                         suite right after a build should pass this flag.
#
# Env (test-only):
#   AGSAFE_BUILD_TEST_INJECT=<relative/path/inside/agent-safety>
#                         Injects an empty file at that path into the
#                         staging dir *after* the allowlist copy and
#                         *before* zipping, so the independent
#                         verification step can be proven to catch a file
#                         that slipped past the copier — not just past
#                         whatever the copier itself decided to allow.
#                         Never set this outside a proof/test run.
#
# Exit codes:
#   0  clean build, verified zip
#   1  a required source file/dir is missing, composer failed, or the
#      zip verification found disallowed entries

set -euo pipefail

# ---------------------------------------------------------------------------
# Paths (resolve repo root from the script's own location so this runs from
# any cwd).
# ---------------------------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PLUGIN_DIR="$REPO_ROOT/plugin"
BUILD_DIR="$REPO_ROOT/build"
STAGE_DIR="$BUILD_DIR/agent-safety"

RESTORE_DEV_DEPS=0
for arg in "$@"; do
	case "$arg" in
	--restore-dev-deps)
		RESTORE_DEV_DEPS=1
		;;
	-h | --help)
		sed -n '2,40p' "$0" | sed 's/^# \{0,1\}//'
		exit 0
		;;
	*)
		echo "build-zip.sh: unknown argument: $arg" >&2
		exit 1
		;;
	esac
done

log() { echo "[build-zip] $*"; }
fail() {
	echo "[build-zip] ERROR: $*" >&2
	exit 1
}

command -v composer >/dev/null 2>&1 || fail "composer not found on PATH"
command -v zip >/dev/null 2>&1 || fail "zip not found on PATH"
command -v unzip >/dev/null 2>&1 || fail "unzip not found on PATH"

[ -f "$PLUGIN_DIR/agent-safety.php" ] || fail "plugin header not found at $PLUGIN_DIR/agent-safety.php"

# ---------------------------------------------------------------------------
# 1. Version, from the plugin header (same field the release workflow
#    already asserts the tag against).
# ---------------------------------------------------------------------------
VERSION=$(awk '/^[[:space:]]*\*[[:space:]]*Version:/ {print $NF; exit}' "$PLUGIN_DIR/agent-safety.php")
[ -n "$VERSION" ] || fail "could not read Version: from plugin header"
log "plugin header Version: $VERSION"

ZIP_PATH="$REPO_ROOT/agent-safety-${VERSION}.zip"

# ---------------------------------------------------------------------------
# 2. Runtime-only composer install in plugin/. Note: plugin/'s path repo
#    COPIES (mirrors) the root core library into
#    plugin/vendor/specflux/agent-safety-core/ rather than symlinking it —
#    this leaves plugin/vendor in a --no-dev state (composer.lock updated
#    too) until either --restore-dev-deps is passed or `composer install`
#    is re-run manually in plugin/.
# ---------------------------------------------------------------------------
log "composer install --no-dev in plugin/"
(cd "$PLUGIN_DIR" && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction)

# ---------------------------------------------------------------------------
# 3. Stage the allowlist.
# ---------------------------------------------------------------------------
log "staging build/agent-safety/"
rm -rf "$BUILD_DIR" "$ZIP_PATH"
mkdir -p "$STAGE_DIR"

copy_required_file() {
	local rel="$1"
	[ -f "$PLUGIN_DIR/$rel" ] || fail "required plugin file missing: plugin/$rel"
	cp "$PLUGIN_DIR/$rel" "$STAGE_DIR/$rel"
}

copy_required_file "agent-safety.php"
copy_required_file "uninstall.php"
copy_required_file "readme.txt"

[ -d "$PLUGIN_DIR/src" ] || fail "required plugin dir missing: plugin/src"
cp -R "$PLUGIN_DIR/src" "$STAGE_DIR/src"

if [ -d "$PLUGIN_DIR/assets" ]; then
	cp -R "$PLUGIN_DIR/assets" "$STAGE_DIR/assets"
else
	log "plugin/assets/ does not exist today — skipping (spec allows it once it does)"
fi

[ -d "$PLUGIN_DIR/vendor" ] || fail "plugin/vendor missing — composer install did not run"
cp -R "$PLUGIN_DIR/vendor" "$STAGE_DIR/vendor"

# Inside vendor/specflux/agent-safety-core/, the path-repo mirror pulls in
# the ENTIRE root repo checkout (tests/, docs/, .git/, CLAUDE.md, its own
# nested plugin/ copy, dotfiles, ...) because composer's path repository
# mirrors the whole directory. Prune it down to src/, composer.json and
# LICENSE (if present) — nothing else from that package ships.
CORE_VENDOR_DIR="$STAGE_DIR/vendor/specflux/agent-safety-core"
if [ -d "$CORE_VENDOR_DIR" ]; then
	KEEP_TMP="$BUILD_DIR/.core-keep"
	rm -rf "$KEEP_TMP"
	mkdir -p "$KEEP_TMP"

	[ -d "$CORE_VENDOR_DIR/src" ] || fail "vendor/specflux/agent-safety-core/src missing after composer install"
	mv "$CORE_VENDOR_DIR/src" "$KEEP_TMP/src"

	[ -f "$CORE_VENDOR_DIR/composer.json" ] || fail "vendor/specflux/agent-safety-core/composer.json missing after composer install"
	mv "$CORE_VENDOR_DIR/composer.json" "$KEEP_TMP/composer.json"

	if [ -f "$CORE_VENDOR_DIR/LICENSE" ]; then
		mv "$CORE_VENDOR_DIR/LICENSE" "$KEEP_TMP/LICENSE"
	else
		log "NOTE: no LICENSE file in specflux/agent-safety-core — none shipped in the zip (orchestrator: confirm whether one should exist before release)"
	fi

	rm -rf "$CORE_VENDOR_DIR"
	mkdir -p "$CORE_VENDOR_DIR"
	mv "$KEEP_TMP"/* "$CORE_VENDOR_DIR/"
	rm -rf "$KEEP_TMP"
else
	fail "vendor/specflux/agent-safety-core missing after composer install"
fi

# Test-only injection hook: prove the verifier is independent of the
# copier above by planting a disallowed file straight into the staging
# dir, after staging is otherwise complete.
if [ -n "${AGSAFE_BUILD_TEST_INJECT:-}" ]; then
	INJECT_PATH="$STAGE_DIR/${AGSAFE_BUILD_TEST_INJECT}"
	mkdir -p "$(dirname "$INJECT_PATH")"
	: >"$INJECT_PATH"
	log "TEST INJECTION: planted $STAGE_DIR/${AGSAFE_BUILD_TEST_INJECT} for verification proof"
fi

# ---------------------------------------------------------------------------
# 4. Zip it, top-level folder agent-safety/.
# ---------------------------------------------------------------------------
# Desktop metadata (Finder's .DS_Store, AppleDouble ._ files) can sit in any
# local checkout; drop it rather than failing the allowlist check below.
find "$STAGE_DIR" \( -name '.DS_Store' -o -name '._*' -o -name 'Thumbs.db' \) -type f -delete

log "zipping $ZIP_PATH"
(cd "$BUILD_DIR" && zip -rq -X -D "$ZIP_PATH" agent-safety)

# ---------------------------------------------------------------------------
# 5. Independent verification: read the zip's own entry list back (not the
#    staging dir, not the copy logic above) and fail, naming the offending
#    paths, if any entry falls outside the allowlist.
# ---------------------------------------------------------------------------
log "verifying zip entries against the allowlist"

ALLOW_RE='^agent-safety/$'
ALLOW_RE="$ALLOW_RE|^agent-safety/agent-safety\.php$"
ALLOW_RE="$ALLOW_RE|^agent-safety/uninstall\.php$"
ALLOW_RE="$ALLOW_RE|^agent-safety/readme\.txt$"
ALLOW_RE="$ALLOW_RE|^agent-safety/src/([^./][^/]*/)*[^./][^/]*$"
ALLOW_RE="$ALLOW_RE|^agent-safety/assets/([^./][^/]*/)*[^./][^/]*$"
ALLOW_RE="$ALLOW_RE|^agent-safety/vendor/autoload\.php$"
ALLOW_RE="$ALLOW_RE|^agent-safety/vendor/composer/([^./][^/]*/)*[^./][^/]*$"
ALLOW_RE="$ALLOW_RE|^agent-safety/vendor/specflux/agent-safety-core/src/([^./][^/]*/)*[^./][^/]*$"
ALLOW_RE="$ALLOW_RE|^agent-safety/vendor/specflux/agent-safety-core/composer\.json$"
ALLOW_RE="$ALLOW_RE|^agent-safety/vendor/specflux/agent-safety-core/LICENSE$"
# Note: the zip is built with `zip -D` (no directory entries), so the
# patterns above only ever need to match file entries.

# Explicit deny patterns take priority over the allowlist above, so that
# e.g. a stray dotfile that would otherwise line up with an allowed
# directory prefix is still rejected and named.
DENY_RE='(^|/)\.[^/]*(/|$)'          # any dotfile or dot-directory component
DENY_RE="$DENY_RE|(^|/)[Tt]ests/"    # any tests/ directory
DENY_RE="$DENY_RE|/phpunit\.xml"     # phpunit.xml*
DENY_RE="$DENY_RE|\.md$"             # markdown docs
DENY_RE="$DENY_RE|(^|/)vendor/bin(/|$)" # composer bin shims

# (avoid `mapfile`/`readarray` — not present in the bash 3.2 that ships as
# /bin/bash on macOS)
ENTRIES=()
while IFS= read -r line; do
	ENTRIES+=("$line")
done < <(unzip -Z1 "$ZIP_PATH")
[ "${#ENTRIES[@]}" -gt 0 ] || fail "zip has no entries — build produced nothing"

BAD=()
for entry in "${ENTRIES[@]}"; do
	if echo "$entry" | grep -Eq "$DENY_RE"; then
		BAD+=("$entry")
		continue
	fi
	if ! echo "$entry" | grep -Eq "$ALLOW_RE"; then
		BAD+=("$entry")
	fi
done

if [ "${#BAD[@]}" -gt 0 ]; then
	echo "[build-zip] ERROR: zip contains entries outside the allowlist:" >&2
	for entry in "${BAD[@]}"; do
		echo "  - $entry" >&2
	done
	exit 1
fi

log "verified: ${#ENTRIES[@]} entries, all allowlisted"

# ---------------------------------------------------------------------------
# 6. Optionally restore dev deps in plugin/ for local use.
# ---------------------------------------------------------------------------
if [ "$RESTORE_DEV_DEPS" -eq 1 ]; then
	log "restoring dev deps in plugin/ (--restore-dev-deps)"
	(cd "$PLUGIN_DIR" && composer install --prefer-dist --optimize-autoloader --no-interaction)
fi

# ---------------------------------------------------------------------------
# 7. Report.
# ---------------------------------------------------------------------------
if command -v sha256sum >/dev/null 2>&1; then
	CHECKSUM=$(sha256sum "$ZIP_PATH" | awk '{print $1}')
else
	CHECKSUM=$(shasum -a 256 "$ZIP_PATH" | awk '{print $1}')
fi

echo "$ZIP_PATH"
echo "sha256: $CHECKSUM"
