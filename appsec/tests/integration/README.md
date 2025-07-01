# AppSec Integration Tests

This is a gradle project. The gradle files are divided into two parts:

* `build.gradle` — contains the main tasks for running the tests.
* `gradle/images.gradle` — contains the tasks for building the docker images.

Generally, you don't need to build the docker images yourself, only if you need
to change them.

## Running the tests

To run all the tests, do `./gradlew check`.

To run tests for a specific PHP version, do e.g. `./gradlew test8.3-release`

To run a specific test suite, do e.g.
`./gradlew test8.3-release --tests RoadRunnerTests`

To run a specific test, do e.g.
`./gradlew test8.3-release --tests "com.datadog.appsec.php.integration.RoadRunnerTests.blocking json on request start"`
(you need to provide the fully qualified name of the test class here).

The tracer and appsec modules are built automatically, if required.

You can attach a Java debugger with `--debug-jvm`. E.g.:
`./gradlew test8.3-release --tests RoadRunnerTests --debugJvm`.

You can attach a PHP debugger with `-PXDEBUG=1`. E.g.:
`./gradlew test8.3-release --tests RoadRunnerTests -PXDEBUG=1`.

It can be also helpful to run gradle with `--info`. This will show the output of
the tests in the console. Or you can look at the html test report afterwards.

Some test classes also have a `main()` method so that you can run the container
without running the tests against it. Generally, changes you make to the PHP
files also become instantly visible in the container, though with roadrunner you
will need to kill the existing workers. Do:

```bash
./gradlew runMain8.3-release -PtestClass=com.datadog.appsec.php.integration.RoadRunnerTests
```

Don't forget to the testClass property. Otherwise, the task won't even be
created.

## Building the images

These can be fetched from docker hub, but if you need to build them yourself,
do `./gradlew buildAll`. You can also build a specific image. You can see the
list of these individual tasks with `./gradlew tasks --all`.

To then use these images, loaded locally in your docker instance, you need to
run the tests with `-PfloatingImageTags=true`. Otherwise, the image digests
listed in the file [`tag_mappings.gradle`](gradle/tag_mappings.gradle) will be
used instead.

## Updating the images on Docker Hub

Pushing new images to Docker Hub requires logging in with a Docker Hub account
that has access to the
[`dd-appsec-php-ci`](https://hub.docker.com/r/datadog/dd-appsec-php-ci)
repository. It's not currently possible to do this via Gitlab CI.

You need an arm64 and an amd64 machine. On each, you'll run `./gradlew pushAll`.
This will push images with the `-$ARCH` suffix in their tag. You then to run
(once) `./gradlew pushMultiArch`.

Finally, you need to run `./gradlew generateTagMappings` and submit a PR with
the modified `tag_mappings.gradle`.

## Cleaning

If for some reason you need to clean the builds of the tracer or appSec or some
state for a specific test (e.g. composer packages), you can remove the
corresponding volumes, (see `docker volume ls`).

You can also completely clean the project with `./gradlew clean`.

