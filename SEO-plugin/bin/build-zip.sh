#!/bin/sh
# Builds an installable plugin ZIP from a commit. Needs git, composer and zip.
#
#   sh bin/build-zip.sh [output-dir] [git-ref]
#
# Give WORKTREE as the git-ref to package the tracked files as they are on disk now, uncommitted
# edits included, for a review candidate. Untracked files are never packaged. Use a commit for releases.
#
# On a machine without PHP, run it in the Composer container from the repository root:
#   docker run --rm -v "$PWD:/repo" -w /repo/SEO-plugin composer:2 sh bin/build-zip.sh /repo/dist
#
# The ZIP holds one folder, seo-analyzer/, with the production Composer packages inside.
# Tests, the test harness and this script are left out (see .gitattributes, export-ignore).
set -eu

here=$(cd "$(dirname "$0")/.." && pwd)
out=${1:-"$here/../dist"}
ref=${2:-HEAD}
git="git -C $here"
# A repository mounted into a container belongs to another user. Tell git that is fine for this run.
export GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0='*'

if [ "$ref" = WORKTREE ]; then
    version=$(sed -n "s/^define('SEO_ANALYZER_VERSION', '\(.*\)');.*/\1/p" "$here/seo-analyzer.php")
else
    version=$($git show "$ref:SEO-plugin/seo-analyzer.php" | sed -n "s/^define('SEO_ANALYZER_VERSION', '\(.*\)');.*/\1/p")
fi
[ -n "$version" ] || { echo "Could not read the version from seo-analyzer.php" >&2; exit 1; }

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

repo=$($git rev-parse --show-toplevel)
if [ "$ref" = WORKTREE ]; then
    # Same file set as git archive: tracked files minus export-ignore. Line endings become LF,
    # as git stores them, whatever the checkout on this machine uses.
    # export-ignore on a folder (/tests, /bin) is not inherited by check-attr on the files inside,
    # so every parent folder of each file is checked too, as git archive does when it prunes folders.
    git -C "$repo" ls-files SEO-plugin > "$work/all.txt"
    awk -F/ '{ p = $1; for (i = 2; i <= NF; i++) { print p; p = p "/" $i } print $0 }' "$work/all.txt" | sort -u \
        | git -C "$repo" check-attr --stdin export-ignore | awk -F': ' '$3 == "set" { print $1 }' > "$work/ignored.txt"
    awk 'NR == FNR { ignored[$0] = 1; next }
        { n = split($0, part, "/"); p = part[1]; skip = (p in ignored)
          for (i = 2; i <= n && !skip; i++) { p = p "/" part[i]; if (p in ignored) skip = 1 }
          if (!skip) print }' "$work/ignored.txt" "$work/all.txt" > "$work/files.txt"
    (cd "$repo" && tar -c -f - -T "$work/files.txt") | tar -x -f - -C "$work"
    rm "$work/all.txt" "$work/ignored.txt" "$work/files.txt"
    find "$work/SEO-plugin" -type f \( -name '*.php' -o -name '*.js' -o -name '*.css' -o -name '*.txt' -o -name '*.md' -o -name '*.json' -o -name '*.lock' \) \
        -exec sed -i 's/\r$//' {} +
else
    git -C "$repo" archive --format=tar --output="$work/plugin.tar" "$ref" SEO-plugin
    tar -x -f "$work/plugin.tar" -C "$work"
    rm "$work/plugin.tar"
fi
mv "$work/SEO-plugin" "$work/seo-analyzer"
[ -f "$work/seo-analyzer/seo-analyzer.php" ] || { echo "The plugin files were not exported" >&2; exit 1; }
# Files the PDF needs. A file on disk that git does not track is left out of the export above, and the ZIP
# would then fail on the first PDF download (Pdf.php needs PdfFonts.php), so stop here instead.
for required in includes/engine/Wp/PdfFonts.php licenses/FreeFont-GPL-3.0-with-font-exception.txt; do
    [ -f "$work/seo-analyzer/$required" ] || { echo "$required was not exported: add it to git before building" >&2; exit 1; }
done

# ext-gd is needed on the web server, not on the build machine.
composer install --working-dir="$work/seo-analyzer" --no-dev --optimize-autoloader --no-interaction --no-progress --ignore-platform-req=ext-gd

# The PDF uses the DejaVu Sans family, and FreeSans (GNU FreeFont, regular face only) for Devanagari
# text (see Wp/PdfFonts.php). mPDF ships about 80 MB of other fonts that are never loaded; leaving
# them out keeps the ZIP under common upload limits.
fonts="$work/seo-analyzer/vendor/mpdf/mpdf/ttfonts"
for font in DejaVuSans.ttf FreeSans.ttf; do
  [ -f "$fonts/$font" ] || { echo "$font is missing from mPDF" >&2; exit 1; }
done
find "$fonts" -type f ! -name 'DejaVu*' ! -name 'FreeSans.ttf' -delete

# Not needed at run time. composer.lock pins the build above, so it is removed only after install.
rm -f "$work/seo-analyzer/composer.lock"
find "$work/seo-analyzer/vendor" -type d \( -name tests -o -name Tests -o -name test -o -name docs -o -name .github \) -prune -exec rm -rf {} +
find "$work/seo-analyzer/vendor" -type f \( -name 'phpunit.xml*' -o -name '.php-cs-fixer*' -o -name 'phpstan*.neon*' \) -delete
find "$work/seo-analyzer" \( -name .DS_Store -o -name .gitignore -o -name .gitattributes -o -name '*.md' -path '*/vendor/*' \) -type f -delete

mkdir -p "$out"
zip_path="$(cd "$out" && pwd)/halo-audit-$version.zip"
rm -f "$zip_path"
(cd "$work" && zip -q -r -X "$zip_path" seo-analyzer)

echo "Built $zip_path"
if [ "$ref" = WORKTREE ]; then
    echo "Source: working tree on top of $($git rev-parse --short HEAD) (uncommitted edits included)"
else
    echo "Commit: $($git rev-parse --short "$ref")"
fi
echo "Files: $(unzip -Z1 "$zip_path" | grep -vc '/$')"
echo "Size: $(du -h "$zip_path" | cut -f1)"
