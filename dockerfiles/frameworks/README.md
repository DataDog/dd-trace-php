# Framework tests how to

This folders contains definitions for the frameworks' test suites we run in CI and the companion environements configured to run such test suites.

From now on, snipptes this document assumes this folder as the working directory.

## Publish the images

In order to run in CI, images are required to be available from docker hub.

In order to build and publish all the required images, run the following command.

```
$ make publish
```

In order to only build locally all the required images, run the following command.

```
$ make build
```

In order to build and publish a specific framework image, run the following command.

```
# make publish_framework_<name>
$ make publish_framework_predis3
```

In order to only build locally a specific framework image, run the following command.

```
# make build_framework_<name>
$ make build_framework_predis3
```

In order to run a specific framework test suite

```
# With the tracer installed
$ make predis3

# Without the tracer installed
$ make predis3_no_ddtrace
```

## Patches only take effect after publishing

The framework Dockerfiles under `contrib/` apply the repository patches in the
image's build directory at build time (`git apply *.patch`). The CI framework
jobs pull the published framework images from Docker Hub (only the nginx file
server is built locally), so a change to anything a Dockerfile bakes into an
image - a patch under `contrib/<framework>/`, the Dockerfile itself, `run.sh`
- only takes effect in CI once a maintainer with Docker Hub credentials runs
the matching publish target:

```
$ make publish_framework_flow
```

Until that happens the framework jobs keep running the old image.
`contrib/flow/flaky-auth-date.patch` (#4463) merged in 2026-11, but the
`php-framework-flow` image was last published in 2025-05, so that patch has
still never run in CI. If you change a patch, publish the image in the same
PR or ask a maintainer to.
