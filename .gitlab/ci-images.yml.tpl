<?php echo file_get_contents(__DIR__ . '/ci-images.static.yml'); ?>
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
