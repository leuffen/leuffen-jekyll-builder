#!/usr/bin/env bash
set -euo pipefail

temp_dir="$(mktemp -d)"
trap 'rm -rf "$temp_dir"' EXIT

# Archive the project contents, not the enclosing directory.
(cd tests/fixtures/site && zip -qr "$temp_dir/site.zip" .)
(cd tests/fixtures/broken && zip -qr "$temp_dir/broken.zip" .)
printf 'not a zip' > "$temp_dir/invalid.zip"

php tests/error-analyzer-test.php
php tests/http-test.php "$temp_dir"
