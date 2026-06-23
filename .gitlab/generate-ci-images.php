<?php

/**
 * Generates the CI image build/manifest/publish pipeline from ci-images.yml.tpl.
 *
 * Source of truth (NO duplication):
 *   - dockerfiles/ci/<os>/docker-compose.yml : service name -> image:TAG
 *   - dockerfiles/ci/bookworm/.env           : $BOOKWORM_NEXT_VERSION etc.
 *
 * The compose service name is the `docker buildx bake` target and the build
 * matrix value; the `image:` tag (with env vars resolved) is the published tag.
 * Per Linux image the template emits one build matrix job over PHP versions
 * (bake builds the multi-arch image and pushes it) plus a manual mirror/publish
 * job per service. The static preamble (templates) and Windows jobs live in
 * ci-images.static.yml (Windows is single-arch).
 */

$root = dirname(__DIR__);

// Resolve $VAR / ${VAR} from a key=value .env file.
function parse_env(string $path): array
{
    $env = [];
    foreach (@file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) {
            $env[$m[1]] = $m[2];
        }
    }
    return $env;
}

function substitute(string $s, array $env): string
{
    return preg_replace_callback('/\$\{?([A-Za-z_][A-Za-z0-9_]*)\}?/', function ($m) use ($env) {
        return $env[$m[1]] ?? $m[0];
    }, $s);
}

// Parse a docker-compose.yml into [service => tag], preserving file order.
function parse_compose(string $path, array $env): array
{
    $services = [];
    $cur = null;
    $inServices = false;
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^services:\s*$/', $line)) {
            $inServices = true;
            continue;
        }
        if (!$inServices) {
            continue;
        }
        if (preg_match('/^\S/', $line)) { // back to a top-level key
            $inServices = false;
            continue;
        }
        if (preg_match('/^  ([A-Za-z0-9][A-Za-z0-9._-]*):\s*$/', $line, $m)) {
            $cur = $m[1];
            $services[$cur] = null;
            continue;
        }
        // image: ${CI_REGISTRY_IMAGE:-...}:TAG   (first image: wins per service)
        if ($cur !== null && $services[$cur] === null
            && preg_match('/^    image:\s*[\'"]?\$\{[^}]+\}:([^\s\'"]+)/', $line, $m)) {
            $services[$cur] = substitute($m[1], $env);
        }
    }
    return array_filter($services, fn($v) => $v !== null);
}

$dirs = [
    "Bookworm" => "dockerfiles/ci/bookworm",
    "CentOS"   => "dockerfiles/ci/centos/7",
    "Alpine"   => "dockerfiles/ci/alpine_compile_extension",
];

$osList = [];
foreach ($dirs as $os => $dir) {
    $services = parse_compose("$root/$dir/docker-compose.yml", parse_env("$root/$dir/.env"));
    if (!$services) {
        fwrite(STDERR, "WARNING: no services parsed for $os ($dir)\n");
        continue;
    }
    $osList[] = ["name" => $os, "dir" => $dir, "services" => $services];
}

require __DIR__ . "/ci-images.yml.tpl";
