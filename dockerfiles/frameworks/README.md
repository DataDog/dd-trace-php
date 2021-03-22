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
