# datadog/dd-trace-ci

The older images can be found in the [DataDog/dd-trace-ci](https://github.com/DataDog/dd-trace-ci/tree/master/php) repo.

Build and push a specific image:

```
docker buildx bake --no-cache --pull --push <image_name>
```

Build and push all images:

```
docker buildx bake --no-cache --pull --push
```

## Building via GitLab-CI

This is the preferred way of building the images.

The image list (PHP versions and tags) is **not** hand-maintained in the
pipeline. It is derived from the `docker-compose.yml` and `.env` files in each
`dockerfiles/ci/<os>/` directory — the single source of truth. The pipeline is
generated from those by `.gitlab/generate-ci-images.php` (template
`.gitlab/ci-images.yml.tpl`, hand-written templates + Windows jobs in
`.gitlab/ci-images.static.yml`). To add or remove a PHP version, edit the
compose file + `.env`; the jobs follow automatically.

The image jobs run in a **child pipeline**. In your pipeline
([GitLab-CI](https://gitlab.ddbuild.io/DataDog/apm-reliability/dd-trace-php/-/pipelines)),
manually start the `ci-images` job (stage `ci-build`) to spawn it. Inside that
child pipeline, per OS there are two kinds of jobs:

1. **`<OS> build: [<version>]`** (manual) — runs `docker buildx bake --pull
   --push` for that PHP version. `bake` reads the `x-bake` platforms from the
   `docker-compose.yml` and builds **both** `amd64` and `arm64` on the amd64
   runner's managed `ci` builder, pushing a single multi-arch manifest to
   `registry.ddbuild.io/ci/dd-trace-php/dd-trace-ci:<tag>`. Run the version(s)
   you need.
2. **`<OS> publish`** (manual, one matrix job per OS with an instance per tag)
   — triggers a downstream child pipeline in the `public-images` service to
   mirror `…:<tag>` from `registry.ddbuild.io` to the public Docker Hub
   (`datadog/dd-trace-ci`).

Authentication to `registry.ddbuild.io` is automatic via the runner's native
credentials.

### Publishing is independent of building

The `publish` jobs have **no dependencies**: they simply sync whatever currently
exists in `registry.ddbuild.io` to Docker Hub. The normal flow is build →
publish, but you can run a `publish` job on its own to (re)provision Docker Hub
from images already present in the internal registry, without rebuilding
anything. It is up to you to ensure the image you publish actually exists in
`registry.ddbuild.io` first.

## Building locally and need more speed?

Building the containers that match your host platform is usually fast enough to
just wait. But building the containers for the other platform (`arm64` vs.
`amd64`) is super slow as those builds are running in QEMU.

Builder-Instances for the rescue:
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
