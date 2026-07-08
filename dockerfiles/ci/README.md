# datadog/dd-trace-ci

These are the CI images the dd-trace-php pipelines run on: one image per PHP
version per base OS (Debian "bookworm", CentOS 7, Alpine), plus Windows. They
are pushed to `registry.ddbuild.io/ci/dd-trace-php/dd-trace-ci` (internal) and
mirrored to `datadog/dd-trace-ci` on Docker Hub (public). Older images live in
the [DataDog/dd-trace-ci](https://github.com/DataDog/dd-trace-ci/tree/master/php)
repo.

## How it works

* **Source of truth:** the `docker-compose.yml` + `.env` in each
  `dockerfiles/ci/<os>/` directory. Each compose *service* is one image; the
  service name is the `buildx bake` target, and its `image:` tag (with `.env`
  vars resolved) is the published tag. PHP versions live here and nowhere else.
* **Pipeline generation:** `.gitlab/generate-ci-images.php` reads those compose
  files and emits a GitLab child pipeline — a literal preamble (stages, job
  templates, and the hand-written Windows jobs) followed by the per-OS Linux
  build/publish jobs it generates from the compose services. The generator runs
  inside the `generate-templates` job; the manual `ci-images` job (stage
  `ci-build`) launches the generated child pipeline.
* **Build:** `docker buildx bake --no-cache --pull --push` builds the multi-arch
  (`amd64` + `arm64`) image — the platforms come from the `x-bake` block in the
  compose file — and pushes a single multi-arch manifest to the internal
  registry. It runs on the amd64 runner's managed `ci` BuildKit builder, so the
  build never needs a separate arm64 runner. The job pod only orchestrates;
  the actual compile happens on the builder, and `MAKE_JOBS` sets its
  parallelism.
* **Signing:** every pushed image is signed with
  [`ddsign`](https://datadoghq.atlassian.net/wiki/spaces/SECENG/pages/2744681107/Image+Integrity+User+Guide),
  required for it to be pullable in Kubernetes clusters that enforce image
  signature verification. Linux images sign right after the `buildx bake`
  push, using the build's `--metadata-file`. Unlike plain `docker buildx
  build`, `bake` nests that file's contents under the target name instead of
  writing it flat, so it's resliced with `jq` first to the shape `ddsign
  --docker-metadata-file` expects. Windows images are built with plain
  `docker build` on a native Windows runner that has no `ddsign` binary
  (ddsign only ships for Linux/Mac), so they're signed by a separate `Windows
  sign` job that runs on Linux and looks up the pushed tag's digest with
  `docker buildx imagetools inspect` instead.
* **Publish:** a `trigger` to the `DataDog/public-images` service mirrors the
  internal image to Docker Hub. It has no dependency on the build (see below).

## Building via GitLab-CI

This is the preferred way of building the images.

In your pipeline
([GitLab-CI](https://gitlab.ddbuild.io/DataDog/apm-reliability/dd-trace-php/-/pipelines)),
manually start the `ci-images` job (stage `ci-build`) to spawn the child
pipeline. Per OS it has two kinds of jobs:

1. **`<OS> build: [<version>]`** (manual) — multi-arch build + push to
   `registry.ddbuild.io/ci/dd-trace-php/dd-trace-ci:<tag>`, then signs it with
   `ddsign`. Run the version(s) you need. Authentication to the internal
   registry is automatic via the runner's native credentials.
2. **`<OS> publish`** (manual, a matrix with one instance per tag) — mirrors
   `…:<tag>` from the internal registry to the public Docker Hub
   (`datadog/dd-trace-ci`) via a downstream `public-images` pipeline.

Windows has an extra manual job, **`Windows sign`** (a matrix with one
instance per tag), since `Windows build` can't sign its own images (see
above). Run it after `Windows build`, before `Windows publish`.

### Publishing is independent of building

The `publish` jobs have **no dependencies**: they simply sync whatever currently
exists in `registry.ddbuild.io` to Docker Hub. The normal flow is build →
publish, but you can run a `publish` job on its own to (re)provision Docker Hub
from images already present in the internal registry, without rebuilding
anything. It is up to you to ensure the image you publish actually exists in
`registry.ddbuild.io` first.

### Adding or updating a PHP version

* **Bump a patch / RC** (e.g. 8.5.7 → 8.5.8): in that OS's `docker-compose.yml`,
  update the service's `phpTarGzUrl` and `phpSha256Hash`. The image tag is
  major.minor, so the image just tracks the latest patch. (`tar xf` autodetects
  compression, so a `.tar.gz`, `.tar.xz` or `.tar.bz2` URL all work — just use
  the matching hash.)
* **Add a new minor**: add a service to the compose file; for bookworm also add
  a `php-<minor>/Dockerfile` (copy the previous minor's and adjust the
  `COPY php-<minor>/...` paths). The generator picks the new service up
  automatically — no pipeline edits needed.

## Building locally

Build and push a specific image (or all of them) from the OS directory:

```
docker buildx bake --no-cache --pull --push <image_name>
docker buildx bake --no-cache --pull --push
```

Building the containers that match your host platform is usually fast enough to
just wait. But building the containers for the other platform (`arm64` vs.
`amd64`) is super slow as those builds are running in QEMU.

Builder-Instances to the rescue:
- Boot up an ARM64 and an AMD64 instance in AWS with Ubuntu
- [Install Docker](https://docs.docker.com/engine/install/ubuntu/) on both
- make Docker executable with the [ubuntu user](https://docs.docker.com/engine/install/linux-postinstall/)
- place your SSH public key on both machines and make sure you can login via
  `ssh ubuntu@ip`
- register both VM's with a named Buildx instance:

```bash
docker buildx create --name multi --driver docker-container --platform linux/arm64 ssh://user@ip
docker buildx create --append --name multi --driver docker-container --platform linux/amd64 ssh://user@ip
docker buildx use multi
```

After this you can just run the `docker buildx` commands from above on those
VM's.

[Source for this](https://depot.dev/blog/building-arm-containers#option-3-running-your-own-builder-instances)
