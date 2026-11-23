# Leuffen Jekyll Builder

Kickstart PHP/Apache server on **port 80**, using the default `www/index.php`
front controller (no custom Apache configuration). The service accepts an
uploaded Jekyll project ZIP and returns a ZIP with the built static files.

## HTTP API

`POST /build` with `Content-Type: application/zip` and the ZIP bytes as the
raw HTTP body. Archive the project contents (including `_config.yml`), not
the enclosing directory.

- **200**: `application/zip`, containing the generated HTML and assets.
- **400/413**: `application/json`, invalid or oversized ZIP.
- **422**: `application/json`, failed/timed-out Jekyll build with `error`,
  `exitCode`, `stdout` and `stderr`.

There is no application authentication; deploy on a trusted internal network
only. ZIP contents are validated and builds use temporary directories.
Uploads are limited to 20 MiB, extracted content to 100 MiB and Jekyll
execution to 120 seconds. Jekyll `_plugins` execute Ruby code, so projects
must come from trusted sources; this container is **not** a security sandbox.

## Example

```bash
docker build -t leuffen-jekyll-builder:local .
docker compose -f tests/compose.yml up -d --no-build
(cd my-jekyll-project && zip -qr ../project.zip .)
curl -f -H 'Content-Type: application/zip' --data-binary @project.zip \
  http://localhost:8080/build -o output.zip
unzip -l output.zip
```

Run `bash tests/integration.sh` to verify success and failure responses.
