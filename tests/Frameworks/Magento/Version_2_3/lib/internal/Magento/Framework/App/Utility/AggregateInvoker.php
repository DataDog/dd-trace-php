<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\App\Utility;

/**
 * Runs given callback across given array of data and collects all PhpUnit assertion results.
 * Should be used in case data provider is huge to minimize overhead.
 */
class AggregateInvoker
{
    /**
     * @var \PHPUnit\Framework\TestCase
     */
    protected $_testCase;

    /**
     * There is no PHPUnit internal API to determine whether --verbose or --debug options are passed.
     * When verbose is true, data sets are gathered for any result, includind incomplete and skipped test.
     * Only data sets for failed assertions are gathered otherwise.
     *
     * @var array
     */
    protected $_options = ['verbose' => false];

    /**
     * @param \PHPUnit\Framework\TestCase $testCase
     * @param array $options
     */
    public function __construct($testCase, array $options = [])
    {
        $this->_testCase = $testCase;
        $this->_options = $options + $this->_options;
    }

    /**
     * Collect all failed assertions and fail test in case such list is not empty.
     * Incomplete and skipped test results are aggregated as well.
     *
     * @param callable $callback
     * @param array[] $dataSource
     * @return void
     */
    public function __invoke(callable $callback, array $dataSource)
    {
        $results = [
            \PHPUnit\Framework\IncompleteTestError::class => [],
            \PHPUnit\Framework\SkippedTestError::class => [],
            \PHPUnit\Framework\AssertionFailedError::class => [],
        ];
        $passed = 0;
        foreach ($dataSource as $dataSetName => $dataSet) {
            try {
                call_user_func_array($callback, $dataSet);
                $passed++;
            } catch (\PHPUnit\Framework\IncompleteTestError $exception) {
                $results[get_class($exception)][] = $this->prepareMessage($exception, $dataSetName, $dataSet);
            } catch (\PHPUnit\Framework\SkippedTestError $exception) {
                $results[get_class($exception)][] = $this->prepareMessage($exception, $dataSetName, $dataSet);
            } catch (\PHPUnit\Framework\AssertionFailedError $exception) {
                $results[\PHPUnit\Framework\AssertionFailedError::class][] = $this->prepareMessage(
                    $exception,
                    $dataSetName,
                    $dataSet
                );
            }
        }
        $this->processResults($results, $passed);
    }

    /**
     * @param \Exception $exception
     * @param string $dataSetName
     * @param mixed $dataSet
     * @return string
     */
    protected function prepareMessage(\Exception $exception, $dataSetName, $dataSet)
    {
        if (!is_string($dataSetName)) {
            $dataSetName = var_export($dataSet, true);
        }
        if ($exception instanceof \PHPUnit\Framework\AssertionFailedError
            && !$exception instanceof \PHPUnit\Framework\IncompleteTestError
            && !$exception instanceof \PHPUnit\Framework\SkippedTestError
            || $this->_options['verbose']) {
            $dataSetName = 'Data set: ' . $dataSetName . PHP_EOL;
        } else {
            $dataSetName = '';
        }
        return $dataSetName . $exception->getMessage() . PHP_EOL
        . \PHPUnit\Util\Filter::getFilteredStacktrace($exception);
    }

    /**
     * Analyze results of aggregated tests execution and complete test case appropriately
     *
     * @param array $results
     * @param int $passed
     * @return void
     */
    protected function processResults(array $results, $passed)
    {
        $totalCountsMessage = sprintf(
            'Passed: %d, Failed: %d, Incomplete: %d, Skipped: %d.',
            $passed,
            count($results[\PHPUnit\Framework\AssertionFailedError::class]),
            count($results[\PHPUnit\Framework\IncompleteTestError::class]),
            count($results[\PHPUnit\Framework\SkippedTestError::class])
        );
        if ($results[\PHPUnit\Framework\AssertionFailedError::class]) {
            $this->_testCase->fail(
                $totalCountsMessage . PHP_EOL .
                implode(PHP_EOL, $results[\PHPUnit\Framework\AssertionFailedError::class])
            );
        }
        if (!$results[\PHPUnit\Framework\IncompleteTestError::class] &&
            !$results[\PHPUnit\Framework\SkippedTestError::class]) {
            return;
        }
        $message = $totalCountsMessage . PHP_EOL . implode(
            PHP_EOL,
            $results[\PHPUnit\Framework\IncompleteTestError::class]
        ) . PHP_EOL . implode(
            PHP_EOL,
            $results[\PHPUnit\Framework\SkippedTestError::class]
        );
        if ($results[\PHPUnit\Framework\IncompleteTestError::class]) {
            $this->_testCase->markTestIncomplete($message);
        } elseif ($results[\PHPUnit\Framework\SkippedTestError::class]) {
            $this->_testCase->markTestSkipped($message);
        }
    }
}
