<?php

namespace DDTrace\Tests\Common;

use PHPUnit\Framework\IncompleteTestError;
use PHPUnit\Framework\SkippedTestError;

/**
 * Trait for handling version-specific method declarations for PHP 7.0.
 */
trait RetryTraitVersionSpecific70
{
    /**
     * @var int|null
     */
    private static $timeOfFirstRetry;

    /**
     * Main test loop to implement retry annotations.
     */
    public function runBare()
    {
        $retryAttempt = 0;
        self::$timeOfFirstRetry = null;

        do {
            try {
                parent::runBare();
                return;
            } catch (IncompleteTestError $e) {
                throw $e;
            } catch (SkippedTestError $e) {
                throw $e;
            } catch (\Exception $e) {
            }
            if (!$this->checkShouldRetryForException($e)) {
                throw $e;
            }
            $retryAttempt++;
        } while ($this->checkShouldRetryAgain($retryAttempt));

        throw $e;
    }
}
