# Randomized tests

The purpose of randomized tests is to find edge cases that users could encounter in their setups but that we would not be able to think of while writing tests.

The basic idea is to randomly generate a matrix of application configurations:
  - Apache/Nginx
  - PHP 5.4-8.0
  - Centos 7

and to run a application with randomily generated execution paths, which involve a number of scenarios and integrations.

## Usage

### Overview

The typical worksflow is:

Generate scenarios, i.e. all the possible configuration we are testing.

```
$ make generate
```

Scenarios will be generated in `.tmp.scenarios` folder.

Execute tests based on the generated scenarios scenarios

```
$ make execute
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
$ make execute
```

By default 5 tests are executed at the same time to avoid resource starving. The number of concurrent jobs can be changed, though.

```
$ make execute CONCURRENT_JOBS=10
```

### Analyze results

Results are generated in `.tmp.scenarios/.results` folder. They can be analyzed running the command

```
$ make analyze
```

Currently the following checks are executed:
- the returned status code can only be on of 200 (OK), 510 (controlled uncaught exception), 511 (controlled php error). Any other return code will result in a failing analysis.
- At least 1000 requests have been executed. If we find that vegeta has performed less than 1000 request, then the  test fails as we might have put the system not under enough pressure and combination of execution paths to find meaningful problems.
