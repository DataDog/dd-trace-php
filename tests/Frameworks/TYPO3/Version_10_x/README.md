# TYPO3 CMS Base Distribution

Get going quickly with TYPO3 CMS.

## Prerequisites

* PHP 7.2
* [Composer](https://getcomposer.org/download/)

## Quickstart

* `composer create-project typo3/cms-base-distribution project-name ^10`
* `cd project-name`

**Setup:**

To start an interactive installation, you can do so by executing the following
command and then follow the wizard:

```
php vendor/bin/typo3cms install:setup
```

**Setup unattended (optional):**

If you're a more advanced user, you might want to leverage the unattended installation.
To do this, you need to execute the following command and substitute the arguments
with your own environment configuration.

```
php vendor/bin/typo3cms install:setup \
    --no-interaction \
    --database-user-name=typo3 \
    --database-user-password=typo3 \
    --database-host-name=127.0.0.1 \
    --database-port=3306 \
    --database-name=typo3 \
    --use-existing-database \
    --admin-user-name=admin \
    --admin-password=password \
    --site-setup-type=site
```

**Development server:**

While it's advised to use a more sophisticated web server such as
Apache 2 or nginx, you can instantly run the project by using PHPs` built-in
[web server](https://secure.php.net/manual/en/features.commandline.webserver.php).

* `TYPO3_CONTEXT=Development php -S localhost:8000 -t public`
* open your browser at "http://localhost:8000"

Please be aware that the built-in web server is single threaded. Which is ultimately
a performance killer and may result in deadlocks if you execute too many requests at once.

# License

GPL-2.0 or later
