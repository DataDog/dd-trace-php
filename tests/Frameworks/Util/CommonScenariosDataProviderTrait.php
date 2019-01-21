<?php

namespace DDTrace\Tests\Frameworks\Util;

use DDTrace\Tests\Frameworks\TestScenarios;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;
use PHPUnit_Framework_ExpectationFailedException;

trait CommonScenariosDataProviderTrait
{
    /**
     * @param array $definedExpectations
     * @return array
     */
    public function buildDataProvider($definedExpectations)
    {
        $scenarios = TestScenarios::all();

        $allRequestNames = array_map(function (RequestSpec $spec) {
            return $spec->getName();
        }, $scenarios);
        sort($allRequestNames);

        $allExpectationNames = array_keys($definedExpectations);
        sort($allExpectationNames);

        // We expect that all the expectations that we defined have a corresponding request to serve
        $unexpectedExpectations = array_diff($allExpectationNames, $allRequestNames);
        if ($unexpectedExpectations) {
            throw new PHPUnit_Framework_ExpectationFailedException(
                'Found the following expectations not having any request defined: '
                . implode(', ', $unexpectedExpectations)
            );
        }

        // We expect that all the scenarios that we defined have a corresponding expectation to serve
        $unexpectedRequest = array_diff($allRequestNames, $allExpectationNames);
        if ($unexpectedRequest) {
            throw new PHPUnit_Framework_ExpectationFailedException(
                'Found the following scenarios not having any expectation defined: '
                . implode(', ', $unexpectedRequest)
            );
        }

        $dataProvider = [];
        foreach ($scenarios as $request) {
            $dataProvider[$request->getName()] = [ $request, $definedExpectations[$request->getName()] ];
        }

        return $dataProvider;
    }
}
