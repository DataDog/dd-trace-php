# Post-Install Hook Tests

These tests ensure proper functionality of the post-install script that is executed after installing ddtrace from a package.

The post-install script is expected to be at the following location to run the tests:

```
/src/ddtrace-scripts/post-install.sh
```

To run the tests from this directory:

```bash
$ docker-compose run --rm php bash
# In the container...
$ cd ~/datadog \
    && (supervisord) \
    && bash tests/PostInstallHook/run-tests.sh
```

## Adding new PHP versions

1. Add the version number to the `CliInput::VALID_PHP_VERSIONS` array in **tests/PostInstallHook/classes.php**.
2. Add the new port number `80<PHP VERSION>` to **docker-compose.yml**.
3. Head over to [the Docker set up for `datadog/dd-trace-ci:php-nginx-apache2`](https://github.com/DataDog/dd-trace-ci/tree/master/php/nginx-apache2).
4. Add the new version to the `apt-get install` line in the **Dockerfile**. _Note: there should only be one version that installs mod_php (`libapache2-mod-php<version>`). If you update it, make sure to also update **apache-ports.conf**._
5. Add a new `server` context for the new version in **nginx-default-site**.
6. Finally, add the new process config to **supervisord.conf**.

> **Note:** `mod_php` only supports one PHP version at a time. If the Apache PHP version is updated, make sure to update the `apachePhpVersion` variable in the **run-tests.sh** script and edit the port in the **docker-compose.yml** file.
