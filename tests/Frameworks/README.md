# Sample apps

This package contains a number of sample apps used to tests different web frameworks.

The test frameworks must support the following routes/endpoints in order to execute the tests in `./TestScenarios.php`:

* `/simple`: GET request that returns a string
* `/simple_view`: GET request that renders a view
* `/error`: GET request that throws an exception (returns a **500** response)

## Laravel

In order to generate the sample Laravel apps we used the default commands from the framework's 'Getting started' guides.

    $ cd tests/Integration/Frameworks/Laravel

### Laravel 4.2

Link: https://laravel.com/docs/4.2/quick#installation

    $ composer create-project laravel/laravel Version_4_2 4.2.*

### Laravel 5.7

Link: https://laravel.com/docs/5.7/installation

    $ composer create-project --prefer-dist laravel/laravel Version_5_7 5.7.*

## Symfony

In order to generate the sample Symfony apps we used the default commands from the framework's 'Getting started' guides.

    $ cd tests/Frameworks/Symfony

### Symfony 2.3

    $ composer create-project symfony/framework-standard-edition Version_2_3 "2.3.*"

### Symfony 2.8

    $ composer create-project symfony/framework-standard-edition Version_2_8 "2.8.*"

### Symfony 3.3

Link: https://symfony.com/doc/3.3/setup.html

    $ composer create-project symfony/framework-standard-edition Version_3_3 "3.3.*"

### Symfony 3.4

Link: https://symfony.com/doc/3.4/setup.html

    $ composer create-project symfony/framework-standard-edition Version_3_4 "3.4.*"

### Symfony 4.2

Link: https://symfony.com/doc/4.2/setup.html

    $ composer create-project symfony/website-skeleton Version_4_2

## Custom frameworks

These aren't real frameworks, but they represent unsupported frameworks and custom frameworks.

    $ cd tests/Frameworks/Custom
