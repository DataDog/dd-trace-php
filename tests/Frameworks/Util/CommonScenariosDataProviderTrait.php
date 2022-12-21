<?php

namespace DDTrace\Tests\Frameworks\Util;

use DDTrace\Tests\Frameworks\TestScenarios;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;
use PHPUnit_Framework_ExpectationFailedException;
use Exception;

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
            throw new \Exception(
                'Found the following expectations not having any request defined: '
                . implode(', ', $unexpectedExpectations)
            );
        }

        // We expect that all the scenarios that we defined have a corresponding expectation to serve
        $unexpectedRequest = array_diff($allRequestNames, $allExpectationNames);
        // Note to team for later: Add 404 checks to all frameworks
        $i = array_search('A GET request to a missing route', $unexpectedRequest, true);
        if ($i !== false) {
            unset($unexpectedRequest[$i]);
        }
        if ($unexpectedRequest) {
            throw new \Exception(
                'Found the following scenarios not having any expectation defined: '
                . implode(', ', $unexpectedRequest)
            );
        }

        $dataProvider = [];
        foreach ($scenarios as $request) {
            $key = $request->getName();
            if (!isset($definedExpectations[$key])) {
                error_log('This framework is missing the following scenario test: ' . $key);
                continue;
            }
            $dataProvider[$key] = [
                $request,
                $definedExpectations[$key]
            ];
        }

        return $dataProvider;
    }
}
