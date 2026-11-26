# Collector Base integration test

The fixture exercises the IONOS module through Combodo's real `exec.php` in a
PHP 8.3 Docker container. It generates a local certificate and a disposable
token, serves synthetic JSON over loopback HTTPS, and uses a temporary resource
plan. A complete collection must publish the expected item. A forbidden
collection must save an incomplete inventory and return a failure. The fixture
also checks that missing organization IDs and conflicting modes stop the Base
before it contacts an iTop target.
The same deployment template runs through the standalone CLI and the Base.
The fixture checks both native iTop authentication modes, named IONOS
credentials and the endpoint filter.

## Run in Docker Desktop

Use the existing `itop33-tests-runner:latest` image. Place the
[Combodo Base 1.5.1 source tree](https://github.com/Combodo/itop-data-collector-base/tree/8a79f3bd30d37d376c768516fda741be334ed432)
under the collector repository's `.tmp/base-1.5.1/` with `exec.php` directly
in that directory. From the collector repository root, run in PowerShell:

```powershell
$base = (Resolve-Path .tmp/base-1.5.1).Path
$module = (Resolve-Path collectors).Path
$test = (Resolve-Path tests/base-integration.php).Path
$example = (Resolve-Path conf/params.example.xml).Path
$standalone = (Resolve-Path scripts/inventory.php).Path
docker run --rm --network none --read-only --user 33:33 `
    --cap-drop ALL --security-opt no-new-privileges `
    --tmpfs '/work:rw,mode=1777' --workdir /work `
    --mount "type=bind,source=$base,target=/base,readonly" `
    --mount "type=bind,source=$module/main.php,target=/module/main.php,readonly" `
    --mount "type=bind,source=$module/params.distrib.xml,target=/module/params.distrib.xml,readonly" `
    --mount "type=bind,source=$module/src,target=/module/src,readonly" `
    --mount "type=bind,source=$test,target=/test.php,readonly" `
    --mount "type=bind,source=$example,target=/example.xml,readonly" `
    --mount "type=bind,source=$standalone,target=/standalone.php,readonly" `
    --entrypoint php itop33-tests-runner:latest /test.php
```

Exit code zero confirms the CLI outcomes. All writes stay in the container's
temporary memory filesystem. Host credentials, live inventory files and iTop
databases are outside its mounts.
