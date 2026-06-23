<?php echo file_get_contents(__DIR__ . '/ci-images.static.yml'); ?>
<?php foreach ($osList as ['name' => $os, 'dir' => $dir, 'services' => $services]): ?>
<?php /*
  One build job per PHP version. buildx bake reads the per-service x-bake
  platforms from docker-compose.yml and builds BOTH arches on the amd64 runner's
  managed "ci" builder, pushing a multi-arch manifest straight to the tag in the
  compose `image:` field. No per-arch split, no manifest fuse job.
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
  Mirror to Docker Hub, one matrix job per OS (grouped in the UI like the
  builds). Independent (needs: [] via .linux_publish): just syncs whatever is
  already in registry.ddbuild.io, so it can run without rebuilding. IMG_SOURCES
  / IMG_DESTINATIONS are built from $TAG in .linux_publish.
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
