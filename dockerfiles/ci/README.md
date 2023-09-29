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

Find your pipeline with the changes you made in
[GitLab-CI](https://gitlab.ddbuild.io/DataDog/apm-reliability/dd-trace-php/-/pipelines)
and manually start the jobs to build the images for the OS you need. You need to
add the following CI variables to the job run:

- `CI_REGISTRY_USER`: should be your Docker Hub username
- `CI_REGISTRY_TOKEN`: should be your access token

In case you don't have one, follow the [docs to create an access
token](https://docs.docker.com/docker-hub/access-tokens/#create-an-access-token).

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
