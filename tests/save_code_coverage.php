<?php

require __DIR__ . '/vendor/autoload.php';

use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Report\PHP as PhpReport;

if (extension_loaded('xdebug')) {
    // Isolate the project name: __DIR__ is in the format /home/circleci/<project_name>/...
    $projectName = explode('/', __DIR__)[3];

    $filter = new Filter();
    $filter->includeDirectory("/home/circleci/$projectName/src");

    $coverage = new CodeCoverage(
        (new Selector())->forLineCoverage($filter),
        $filter
    );

    $coverage->start(bin2hex(random_bytes(16)));

    function save_coverage()
    {
        global $coverage, $projectName;
        $coverage->stop();

        $coverageFileName = bin2hex(random_bytes(16)) . '.cov';
        (new PhpReport())->process($coverage, "/home/circleci/$projectName/reports/cov/$coverageFileName");
    }

    register_shutdown_function('save_coverage');
}

?>
