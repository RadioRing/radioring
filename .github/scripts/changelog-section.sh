#!/bin/sh
# Prints the CHANGELOG.md section of one version, without its own heading.
#
# The release workflow uses this to publish the GitHub release notes, so that the
# changelog in the repository and the release page cannot drift apart. It exits
# non-zero when the version has no section, which is what makes a release without
# notes impossible.
#
#   .github/scripts/changelog-section.sh v0.3.0 [path/to/CHANGELOG.md]

set -eu

version="${1#v}"
file="${2:-CHANGELOG.md}"

if [ ! -f "$file" ]; then
    echo "No changelog at $file" >&2
    exit 1
fi

# Everything between this version's heading and whatever ends it: the next
# version heading, or the block of link definitions at the bottom of the file.
section="$(awk -v heading="## [$version]" '
    index($0, heading) == 1 { found = 1; next }
    found && /^## \[/      { exit }
    found && /^\[[^]]+\]:/ { exit }
    found                  { print }
' "$file")"

if [ -z "$(printf '%s' "$section" | tr -d '[:space:]')" ]; then
    echo "No section '## [$version]' in $file. Add one before tagging." >&2
    exit 1
fi

# Drop the blank lines the split leaves at the start and the end.
printf '%s\n' "$section" | sed -e '/./,$!d' -e ':a' -e '/^\n*$/{$d;N;ba' -e '}'
