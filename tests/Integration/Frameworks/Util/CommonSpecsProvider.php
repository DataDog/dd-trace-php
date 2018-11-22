<?php

namespace DDTrace\Tests\Integration\Frameworks\Util;

use DDTrace\Tests\Integration\Frameworks\Util\Request\RequestSpec;
use PHPUnit\Framework\ExpectationFailedException;


class CommonSpecsProvider
{
    /**
     * @param RequestSpec[] $requests
     * @param ExpectationProvider $expectationProvider
     * @return array
     */
    public function provide(array $requests, ExpectationProvider $expectationProvider)
    {
        $allRequestNames = array_map(function (RequestSpec $spec) {
            return $spec->getName();
        }, $requests);
        sort($allRequestNames);

        $definedExpectations = $expectationProvider->provide();
        $allExpectationNames = array_keys($definedExpectations);
        sort($allExpectationNames);

        // We expect that all the expectations that we defined have a corresponding request to serve
        $unexpectedExpectations = array_diff($allExpectationNames, $allRequestNames);
        if ($unexpectedExpectations) {
            throw new ExpectationFailedException('Found the following expectations not having any request defined: '
                . implode(', ', $unexpectedExpectations));
        }

        // We expect that all the requests that we defined have a corresponding expectation to serve
        $unexpectedRequest = array_diff($allRequestNames, $allExpectationNames);
        if ($unexpectedRequest) {
            throw new ExpectationFailedException('Found the following requests not having any expectation defined: '
                . implode(', ', $unexpectedRequest));
        }

        $dataProvider = [];
        foreach ($requests as $request) {
            $dataProvider[$request->getName()] = [ $request, $definedExpectations[$request->getName()] ];
        }

        return $dataProvider;
    }
}
