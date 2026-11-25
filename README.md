# Leuffen Jekyll Builder

This repository provides two independent components:

- The **root Composer package** `leuffen/jekyll-builder-client` is only the
  reusable PHP HTTP client, autoloaded from `src/`.
- `container/` is the standalone Kickstart PHP/Apache build service with its
  own `composer.json`, `Dockerfile`, `.kick.yml`, `www/` and server `src/`.

The server listens on **port 80**, using the default `container/www/index.php`
front controller (no custom Apache configuration). It accepts a ZIP of a Jekyll
project and returns a ZIP containing the generated static files.

## Docker build

Build from the repository root while selecting the container Dockerfile:

```bash
docker build -f container/Dockerfile -t leuffen-jekyll-builder .
```

Only `container/` is copied into the image under `/opt`. Composer dependencies
for the server are declared separately in `container/composer.json`; the
server currently requires no external Composer libraries.

## HTTP API

`POST /build` with `Content-Type: application/zip` and the ZIP bytes as the
raw HTTP body. Archive the project contents (including `_config.yml`), not
the enclosing directory.

- **200**: `application/zip`, containing the generated HTML and assets.
- **400/413**: `application/json`, invalid or oversized ZIP.
- **422**: `application/json`, failed/timed-out Jekyll build with `error`,
  `exitCode`, `stdout`, `stderr`, `file`, `line`, `excerpt` and `cause`.

Example of a failed Jekyll build (HTTP `422`, `application/json`):

```json
{
  "error": "Jekyll build failed",
  "exitCode": 1,
  "stdout": "Configuration file: /tmp/jekyll-builder-.../source/_config.yml\n",
  "stderr": "Liquid Exception: Unknown tag 'definitely_not_a_liquid_tag' in index.html\n",
  "file": "index.html",
  "line": 3,
  "cause": "Liquid Exception: Unknown tag 'definitely_not_a_liquid_tag' in index.html",
  "excerpt": "  1 | ---\n  2 | ---\n> 3 | {% definitely_not_a_liquid_tag %}"
}
```

`stdout` and `stderr` contain the captured Jekyll output; their exact
contents depend on the project and the underlying Jekyll version. `file` is
relative to the ZIP root; it never contains a temporary directory. If no source
can be attributed, `file`, `line` and `excerpt` are `null`, and raw `stdout`
and `stderr` remain available.

There is no application authentication; deploy on a trusted internal network
only. ZIP contents are validated and builds use temporary directories.
Uploads are limited to 20 MiB, extracted content to 100 MiB and Jekyll
execution to 120 seconds. Jekyll `_plugins` execute Ruby code, so projects
must come from trusted sources; this container is **not** a security sandbox.

## Docker Stack and shared network

The builder runs on Apache port **80** and does not publish a host port.
Both the builder and calling application join the same existing overlay network:

```bash
docker network create --driver overlay --attachable pagebuilder-internal
docker stack deploy -c docs/stack.yml jekyll
```

The application stack adds the same network, for example:

```yaml
services:
  pagebuilder:
    image: your-pagebuilder-image:latest
    networks: [builder-network]

networks:
  builder-network:
    external: true
    name: pagebuilder-internal
```

From that container, reach the builder directly at
`http://jekyll-builder/build` (internal Docker DNS alias). No
authentication is required; do not expose the builder outside the internal
network.

## PHP client example

Install the client package in the calling PHP application:

```bash
composer require leuffen/jekyll-builder-client:dev-main
```

The client and its exceptions are autoloaded directly from the root Composer
package (`src/`). No copying of PHP files is required:

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Leuffen\JekyllBuilder\Client\LeuffenJekyllBuilderClient;
use Leuffen\JekyllBuilder\Client\Exception\JekyllBuilderBuildException;
use Leuffen\JekyllBuilder\Client\Exception\JekyllBuilderConnectionException;

$client = new LeuffenJekyllBuilderClient('http://jekyll-builder');

try {
    $client->build('/projects/example', '/srv/sites/example');
} catch (JekyllBuilderBuildException $error) {
    echo $error->sourceFile ?? 'Unknown source file';
    echo $error->sourceLine ?? '';
    echo $error->cause ?? $error->getMessage();
    echo $error->excerpt ?? '';
    echo $error->stderr;
} catch (JekyllBuilderConnectionException $error) {
    echo 'Builder not available: ' . $error->getMessage();
}
```

`build($sourceDir, $targetDir)` archives the contents of the source directory
and extracts the generated website into the target directory. Entries named
`.git` (directories or worktree files) are excluded at every depth; other
hidden files are included. The target must be new or empty; existing files are
not overwritten. `buildZip()` remains available for callers who already have
an input ZIP and sends that ZIP unchanged. Build failures expose `httpStatus`, `exitCode`,
`sourceFile` (relative to the upload ZIP), `sourceLine`, `cause`,
`excerpt`, `stdout` and `stderr`. If the source cannot be
identified, the corresponding fields are `null`.

Run `composer validate` and `composer validate --working-dir=container`, then
`composer install --working-dir=tests` followed by
`bash tests/integration.sh` to verify success and failure responses. The HTTP
test client uses `phore/http-client`.
