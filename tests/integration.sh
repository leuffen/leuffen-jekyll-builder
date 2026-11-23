#!/usr/bin/env bash
set -euo pipefail

temp_dir="$(mktemp -d)"
trap 'rm -rf "$temp_dir"' EXIT

# Archive the project *contents* so _config.yml is at the ZIP root.
(cd tests/fixtures/site && zip -qr "$temp_dir/site.zip" .)
status="$(curl --silent --show-error --max-time 180 -o "$temp_dir/out.zip" -w '%{http_code}' \
  -H 'Content-Type: application/zip' --data-binary @"$temp_dir/site.zip" http://localhost:8080/build)"
test "$status" = "200"
unzip -p "$temp_dir/out.zip" index.html | grep -q 'ZIP Builder smoke'

(cd tests/fixtures/broken && zip -qr "$temp_dir/broken.zip" .)
status="$(curl --silent --show-error --max-time 180 -o "$temp_dir/error.json" -w '%{http_code}' \
  -H 'Content-Type: application/zip' --data-binary @"$temp_dir/broken.zip" http://localhost:8080/build)"
test "$status" = "422"
grep -q '"stderr"' "$temp_dir/error.json"

printf 'not a zip' > "$temp_dir/not.zip"
status="$(curl --silent --show-error -o "$temp_dir/invalid.json" -w '%{http_code}' \
  -H 'Content-Type: application/zip' --data-binary @"$temp_dir/not.zip" http://localhost:8080/build)"
test "$status" = "400"
grep -q 'Invalid ZIP' "$temp_dir/invalid.json"
