# Template for the CI image child pipeline, rendered to ci-images-gen.yml by
# .gitlab/generate-ci-images.php. Everything here is emitted as-is except the
# PHP loops at the bottom, which generate the per-OS Linux build/publish jobs
# from the docker-compose.yml + .env files. Edit this template — never the
# generated ci-images-gen.yml. The Windows jobs are hand-written (single-arch,
# no multi-arch manifest).

stages:
  - ci-build
  - ci-publish

variables:
  CI_REGISTRY_IMAGE: "registry.ddbuild.io/ci/dd-trace-php/dd-trace-ci"

.linux_image_build:
  stage: ci-build
  rules:
    - when: manual
      allow_failure: true
  needs: []
  timeout: 4h
  image: 486234852809.dkr.ecr.us-east-1.amazonaws.com/docker:29.4.0-noble
  variables:
    DDCI_CONFIGURE_OTEL_EXPORTER: "true"
    # Compile runs on the buildx "ci" builder instance, not this job pod, so the
    # pod uses cluster defaults. MAKE_JOBS sets the builder's compile parallelism.
    MAKE_JOBS: "8"

.linux_publish:
  stage: ci-publish
  rules:
    - when: manual
      allow_failure: true
  # No deps: a publish just mirrors whatever already exists in
  # registry.ddbuild.io to Docker Hub, so it can run without (re)building.
  needs: []
  trigger:
    project: DataDog/public-images
    branch: main
  # $TAG is supplied per matrix entry by the generated publish jobs.
  variables:
    IMG_REGISTRIES: "dockerhub"
    IMG_SIGNING: false
    IMG_SOURCES: "registry.ddbuild.io/ci/dd-trace-php/dd-trace-ci:${TAG}"
    IMG_DESTINATIONS: "dd-trace-ci:${TAG}"

.windows_image_build:
  stage: ci-build
  rules:
    - when: manual
      allow_failure: true
  needs: []
  tags: ["windows-v2:2019"]
  timeout: 6h
  variables:
    DDCI_CONFIGURE_OTEL_EXPORTER: "true"
    GIT_STRATEGY: none
  script: |
    # Kill leftover containers; a previous run may still hold php_ddtrace.dll open.
    $containers = docker ps -aq 2>$null
    if ($containers) { docker rm -f $containers 2>$null }

    # Use cmd.exe rd from the parent dir: handles junctions/symlinks that PS5.1 Remove-Item cannot.
    Write-Host "Performing workspace cleanup..."
    $workspace = $PWD.Path
    Push-Location ..
    cmd /c "rd /s /q ""$workspace"""
    if (-not (Test-Path $workspace)) {
        New-Item -ItemType Directory -Path $workspace -Force | Out-Null
    }
    Pop-Location
    $remaining = Get-ChildItem -Path . -Force -ErrorAction SilentlyContinue
    if ($remaining) { Write-Host "WARNING: could not remove: $($remaining.Name -join ', ')" }
    Write-Host "Cleanup complete."

    # PS 5.1 ignores $PSNativeCommandUseErrorActionPreference; use $LASTEXITCODE checks instead.
    $ErrorActionPreference = 'Stop'

    # Manual git clone with proper config.
    Write-Host "Cloning repository..."
    git config --global core.longpaths true
    git config --global core.symlinks true
    git clone --branch $env:CI_COMMIT_REF_NAME $env:CI_REPOSITORY_URL .
    if ($LASTEXITCODE -ne 0) {
        Write-Host "ERROR: git clone failed. Remaining workspace contents:"
        Get-ChildItem -Force | Select-Object Name
        exit $LASTEXITCODE
    }
    git checkout $env:CI_COMMIT_SHA
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

    # Initialize submodules.
    Write-Host "Initializing submodules..."
    git submodule update --init --recursive
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    Write-Host "Git setup complete."

    # Download docker-compose to the workspace.
    Write-Host "Downloading docker-compose..."
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    $dockerCompose = "$PWD\docker-compose.exe"
    Start-BitsTransfer -Source "https://github.com/docker/compose/releases/download/v2.36.0/docker-compose-windows-x86_64.exe" -Destination $dockerCompose

    cd dockerfiles\ci\windows

    docker version
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

    & $dockerCompose version
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

    foreach ($target in ($env:WINDOWS_IMAGE_TARGETS -split ' ')) {
      if ([string]::IsNullOrWhiteSpace($target)) { continue }

      Write-Host "Building Windows CI image target $target..."
      & $dockerCompose build --pull --no-cache $target
      if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

      Write-Host "Pushing Windows CI image target $target..."
      & $dockerCompose push $target
      if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    }

"Windows 1: Tool Images":
  extends: .windows_image_build
  parallel:
    matrix:
      - WINDOWS_IMAGE_TARGETS:
        - "vc15"
        - "vs16"
        - "vs17"

"Windows 2: PHP Images":
  extends: .windows_image_build
  parallel:
    matrix:
      - WINDOWS_IMAGE_TARGETS:
        - "php-8.5"
        - "php-8.4"
        - "php-8.3"
        - "php-8.2"
        - "php-8.1"
        - "php-8.0"
        - "php-7.4"
        - "php-7.3"
        - "php-7.2"

Publish Windows:
  stage: ci-publish
  rules:
    - when: manual
      allow_failure: true
  needs:
    - job: "Windows 1: Tool Images"
    - job: "Windows 2: PHP Images"
  trigger:
    project: DataDog/public-images
    branch: main
  parallel:
    matrix:
      - TAG_NAME:
        - "windows-base-vc15"
        - "windows-base-vs16"
        - "windows-base-vs17"
        - "windows-vc15"
        - "windows-vs16"
        - "windows-vs17"
        - "php-8.5_windows"
        - "php-8.4_windows"
        - "php-8.3_windows"
        - "php-8.2_windows"
        - "php-8.1_windows"
        - "php-8.0_windows"
        - "php-7.4_windows"
        - "php-7.3_windows"
        - "php-7.2_windows"
  variables:
    IMG_SOURCES: "registry.ddbuild.io/ci/dd-trace-php/dd-trace-ci:${TAG_NAME}"
    IMG_DESTINATIONS: "dd-trace-ci:${TAG_NAME}"
    IMG_REGISTRIES: "dockerhub"
    IMG_SIGNING: false
<?php foreach ($osList as ['name' => $os, 'dir' => $dir, 'services' => $services]): ?>
<?php /*
  One build job per PHP version. buildx bake reads the x-bake platforms from
  docker-compose.yml and builds both arches on the amd64 runner's "ci" builder,
  pushing a multi-arch manifest to the tag in the compose `image:` field.
*/ ?>

<?= $os ?> build:
  extends: .linux_image_build
  tags: ["arch:amd64"]
  parallel:
    matrix:
      - PHP_VERSION:
<?php foreach (array_keys($services) as $svc): ?>
          - <?= $svc, "\n" ?>
<?php endforeach; ?>
  script:
    - cd <?= $dir, "\n" ?>
    - docker buildx bake --no-cache --pull --push "${PHP_VERSION}"
<?php /*
  Mirror to Docker Hub: one matrix job per OS, independent (needs: [] via
  .linux_publish) so it can sync existing images without rebuilding.
*/ ?>

<?= $os ?> publish:
  extends: .linux_publish
  parallel:
    matrix:
      - TAG:
<?php foreach ($services as $tag): ?>
          - "<?= $tag ?>"
<?php endforeach; ?>
<?php endforeach; ?>
