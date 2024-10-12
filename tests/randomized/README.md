# Randomized tests

The purpose of randomized tests is to find edge cases that users could encounter in their setups but that we would not be able to think of while writing tests.

The basic idea is to randomly generate a matrix of application configurations:
  - Apache/Nginx
  - PHP 7.0-8.3
  - Centos 7 / Debian Buster

and to run a application with randomily generated execution paths, which involve a number of scenarios and integrations.

Additional platforms and settings can be provided in the `./config` folder.
- `./config/envs.php`: all tested environment variables values;
- `./config/inis.php`: all tested INI modifications;
- `./config/platforms.php`: all possible OSes and PHP versions.

## Usage

### Overview

The typical workflow is:

Download the tracer version you intend to test.
(Note that as the randomized tests use `Docker`, the following command must NOT be run inside a container)

```
# Use the .tar.gz from <root>/build/packages built locally
make library.local

# Download from a url
make library.download LIBRARY_TEST_URL=<link to the .tar.gz>
```

Generate scenarios, i.e. all the possible configuration we are testing.

```
$ make generate
```

Scenarios will be generated in `.tmp.scenarios` folder.

Execute tests based on the generated scenarios scenarios

```
$ make test
```

Analyze the results (that will be generated in `.tmp.scenarios/.results` directory)

```
$ make analyze
```

### Generate scenarios

Running the following command will generate 20 randomized configuration by default. `NUMBER_OF_SCENARIOS` can be used to change the number of generated scenarios.

```
$ make generate
Using seed: 976049198

$ make generate NUMBER_OF_SCENARIOS=40
Using seed: 1335550468
```

The script that generates the scenarios print a seed. When a batch of generated scenarios fails in CI, we can read the seed used to generate them in CI and recreate those exact scenarios locally for debug.

```
$ make generate SEED=12345
Using seed: 12345
```

### Execute tests

Running the following command will execute tests

```
$ make test
```

By default 5 tests are executed at the same time to avoid resource starving. The number of concurrent jobs can be changed, though.

```
$ make test CONCURRENT_JOBS=10
```

### Analyze results

Results are generated in `.tmp.scenarios/.results` folder. They can be analyzed running the command

```
$ make analyze
```

Currently the following checks are executed:
- the returned status code can only be on of 200 (OK), 510 (controlled uncaught exception), 511 (controlled php error). Any other return code will result in a failing analysis.
- At least 1000 requests have been executed. If we find that vegeta has performed less than 1000 request, then the test fails as we might have put the system not under enough pressure and combination of execution paths to find meaningful problems. In this case the error will be reported with a message `Minimum request not matched` and, unless there are not requests at all, it typically means that your hardware is not power enough to run a large amount of concurrent tests. You can either reduce concurrency via `make execute CONCURRENT_JOBS=3` or run specific tests via `make -C .tmp.scenarios test.scenario.<scenario_name>`.

### Reproduce a failing job in CI

You can reproduce a CI job by running the following commands:

```bash
# Download the package from the CI
make library.download LIBRARY_TEST_URL=<URL of the package from the "package extension" job artifacts>
# Generate the scenarios (Should match the configuration in `.circleci/continue_config.yml`)
make generate SEED=<The seed from the "Generate scenarios" step of the randomized job> NUMBER_OF_SCENARIOS=4 PLATFORMS=centos7
# Run the scenarios (Should match the configuration in `.circleci/continue_config.yml`)
make test CONCURRENT_JOBS=2 DURATION=1m30s
# Analyze the results
make analyze
```

### Launch a local environment with a specific scenario activated

The folder where scenarios are generated provides a `docker-compose.yml` file and a `Makefile` file that can be used to launch tests environments singularly.

For example, execute the following steps to launch a shell in a specific scenario named `randomized-661543622-centos7-7.4`.

```
$ make -C .tmp.scenarios shell.scenario.randomized-661543622-centos7-7.4
```

Inside the container you can optionally start the web servers and install the tracer automatically using the script provided in `/scripts/prepare.sh`.

```
> bash /scripts/prepare.sh
```

Once the bootstrap phase is completed, a nginx server will be listening on port 80, while an apache server will be listening on port 81. From within the container you can now run:

```
> curl -v localhost:80                # nginx
> curl -v localhost:81                # apache
> curl -v localhost:80?seed=123456    # optionally, provide a seed to exactly recreate the same execution path on multiple requests.
```


## Debugging a segmentation fault

At the beginning of every request a message is printed to correlate a PID and a seed. E.g. `Current PID: 70. Current seed 754539563`.
That seed can be used later on to recreate the same request: `curl localhost:80?seed=754539563`.

Note that the generated scenarios can be run individually. In the directory `.tmp.scenarios` you can see a `Makefile` with a list of targets that can be executed.

### Analyzing a core dump generated in CI

Run a script `circleci_core.sh` which will install the used extension, and launch a gdb shell with the core dump:

```
tests/randomized/circleci_core.sh https://app.circleci.com/pipelines/github/DataDog/dd-trace-php/17113/workflows/196b5740-f3ce-4faf-8731-e9a4b15d114a/jobs/4964970/steps
```

The only argument to that script is the URL of the job.

#### Alternative: Manual steps

Run a container with the most recent version of the proper docker image for the specific version of PHP. For example, assuming PHP 8.0:

```
docker pull datadog/dd-trace-ci:php-randomizedtests-centos7-8.0
docker run --rm -ti datadog/dd-trace-ci:php-randomizedtests-centos7-8.0 bash
```

Install the specific version of the tracer from `CircleCI` > `build_packages` > `package extension` > `ARTIFACTS`

```
curl -L -o /tmp/datadog-setup.php https://557109-119990860-gh.circle-artifacts.com/0/datadog-setup.php
curl -L -o /tmp/ddtrace-test.tar.gz https://557109-119990860-gh.circle-artifacts.com/0/dd-library-php-1.0.0-nightly-aarch64-linux-gnu.tar.gz
php /tmp/datadog-setup.php --php-bin all --file /tmp/ddtrace-test.tar.gz --enable-profiling
```

Download the generated core dump from `CircleCI` > `build_packages` > `randomized_tests-XX` > `ARTIFACTS` (search in artifact for the scenario that is failing based on the build report, the core dump file is called `core`):

```
curl -L -o /tmp/core https://557142-119990860-gh.circle-artifacts.com/0/tests/randomized/.tmp.scenarios/.results/randomized-202999263-centos7-8.0/corefiles/core
```

Then load it in `gdb`:

```
gdb --core=/tmp/core php-fpm|httpd|php
```

#### Manual steps using ASAN

In some cases it might be tricky to find the memory corruption, but you can
always use an address sanitizer to try and find problems.

If you want/need the tracer to be linked to ASAN as well, you need to fetch the
package from the `package extension zts-debug-asan` step instead of the `package
extension` job, otherwise only PHP itself will be linked to ASAN in the
following, which might be sufficient depending on the use case.

#### Ubuntu image

In case the bug was spotted in a Ubuntu image, there is PHP with ASAN already
installed, you need to switch to it:

```sh
switch-php debug-zts-asan
```

After this you can start run the tests again with address sanitizer enabled.

#### CentOS image

If the bug was spotted in CentOS, you'd need to recompile PHP with ASAN:

```sh
yum install -y devtoolset-7-libasan-devel
mkdir -p /tmp/build-php
cd /tmp/build-php
/root/configure.sh --prefix=${PHP_INSTALL_DIR_NTS} --with-config-file-path=${PHP_INSTALL_DIR_NTS} --with-config-file-scan-dir=${PHP_INSTALL_DIR_NTS}/conf.d --enable-address-sanitizer
make -j
make install
```

This will install `libasan`, reconfigure PHP to enable the address sanitizer,
recompile and install it. You also need to set some ASAN options and make sure
to disable the ZendMM:

```sh
export ASAN_OPTIONS=abort_on_error=1:disable_coredump=0:unmap_shadow_on_exit=1
export USE_ZEND_ALLOC=0
```

After this you can start run the tests again with address sanitizer enabled.

## Rebuilding docker containers

The randomized tests docker containers are based on our CentOS 7 or Debian Buster containers.
