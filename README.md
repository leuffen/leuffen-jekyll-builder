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

Install `phore/http-client` in the calling PHP application:

```bash
composer require phore/http-client
```

The reusable client and separate exception classes are under `docs/`.
Copy those files to your application and load the client after Composer's
`vendor/autoload.php`:

```php
<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/LeuffenJekyllBuilderClient.php';

use Leuffen\JekyllBuilder\Client\LeuffenJekyllBuilderClient;
use Leuffen\JekyllBuilder\Client\Exception\JekyllBuilderBuildException;
use Leuffen\JekyllBuilder\Client\Exception\JekyllBuilderConnectionException;

$client = new LeuffenJekyllBuilderClient('http://jekyll-builder');

try {
    $siteZip = $client->build('/projects/example');
    file_put_contents('/tmp/site.zip', $siteZip);
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

Alternatively, `buildZip('/path/project.zip')` sends an existing ZIP and returns
the output ZIP bytes. Build failures expose `httpStatus`, `exitCode`,
`sourceFile` (relative to the upload ZIP), `sourceLine`, `cause`,
`excerpt`, `stdout` and `stderr`. If the source cannot be
identified, the corresponding fields are `null`.

Run `composer install --working-dir=tests` followed by
`bash tests/integration.sh` to verify success and failure responses. The HTTP
test client uses `phore/http-client`.
