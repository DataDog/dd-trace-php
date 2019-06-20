# Sample apps

This package contains a number of sample apps used to tests different web frameworks.

The test frameworks must support the following routes/endpoints in order to execute the tests in `./TestScenarios.php`:

* `/simple`: GET request that returns a string
* `/simple_view`: GET request that renders a view
* `/error`: GET request that throws an exception (returns a **500** response)

## CakePHP

In order to generate the sample CakePHP apps we installed it as a new project [using Composer](https://packagist.org/packages/cakephp/cakephp).

    $ cd tests/Frameworks/CakePHP

### CakePHP 2.8

    $ composer create-project cakephp/cakephp Version_2_8 2.8.*

## Laravel

In order to generate the sample Laravel apps we used the default commands from the framework's 'Getting started' guides.

    $ cd tests/Frameworks/Laravel

### Laravel 4.2

Link: https://laravel.com/docs/4.2/quick#installation

    $ composer create-project laravel/laravel Version_4_2 4.2.*

### Laravel 5.8

Link: https://laravel.com/docs/5.8/installation

    $ composer create-project --prefer-dist laravel/laravel Version_5_8 5.8.*

### Laravel 5.7

Link: https://laravel.com/docs/5.7/installation

    $ composer create-project --prefer-dist laravel/laravel Version_5_7 5.7.*

## Lumen

In order to generate the sample Lumen apps we used the default commands from the framework's 'Getting started' guides.

    $ cd tests/Frameworks/Lumen

### Lumen 5.2

Link: https://lumen.laravel.com/docs/5.2/installation

    $ composer create-project laravel/lumen Version_5_2 "5.2.*"

### Lumen 5.6

Link: https://lumen.laravel.com/docs/5.6/installation

    $ composer create-project laravel/lumen Version_5_6 "5.6.*"

### Lumen 5.8

Link: https://lumen.laravel.com/docs/5.8/installation

    $ composer create-project laravel/lumen Version_5_8 "5.8.*"

## Slim Framework

In order to generate the sample Slim Framework apps we used the default commands from the [framework's 'Getting started' guide](http://www.slimframework.com/).

    $ cd tests/Frameworks/Slim

### Slim 3.12

    $ composer create-project slim/slim-skeleton Version_3_12 "3.12.*"

## Symfony

In order to generate the sample Symfony apps we used the default commands from the framework's 'Getting started' guides.

    $ cd tests/Frameworks/Symfony

### Symfony 2.3

    $ composer create-project symfony/framework-standard-edition Version_2_3 "2.3.*"

### Symfony 2.8

    $ composer create-project symfony/framework-standard-edition Version_2_8 "2.8.*"

### Symfony 3.0

Link: https://symfony.com/doc/3.0/setup.html

    $ composer create-project symfony/framework-standard-edition Version_3_0 "3.0.*"

### Symfony 3.3

Link: https://symfony.com/doc/3.3/setup.html

    $ composer create-project symfony/framework-standard-edition Version_3_3 "3.3.*"

### Symfony 3.4

Link: https://symfony.com/doc/3.4/setup.html

    $ composer create-project symfony/framework-standard-edition Version_3_4 "3.4.*"

### Symfony 4.0

Generating project requires older composer version. Known working version can be downloaded frome here: https://getcomposer.org/download/1.1.3/composer.phar

Link: https://symfony.com/doc/4.0/setup.html

    $ composer create-project symfony/website-skeleton Version_4_0 "4.0.*"

### Symfony 4.2

Link: https://symfony.com/doc/4.2/setup.html

    $ composer create-project symfony/website-skeleton Version_4_2

## Custom frameworks

These aren't real frameworks, but they represent unsupported frameworks and custom frameworks.

    $ cd tests/Frameworks/Custom
