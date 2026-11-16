<?php

namespace DDTrace\Tests\Common;

/**
 * Trait for adding @retry annotations to retry flakey tests.
 */
trait RetryTraitCommon
{
    use RetryAnnotationTrait;

    private static $timeOfFirstRetry;

    private function checkShouldRetryAgain(int $retryAttempt): bool
    {
        if ($retryAttempts = $this->getRetryAttemptsAnnotation()) {
            // Maximum retry attempts exceeded
            if ($retryAttempt > $retryAttempts) {
                return false;
            }

            // Log retry
            \printf(
                '[RETRY] Retrying %s of %s' . PHP_EOL,
                $retryAttempt,
                $retryAttempts
            );
        } elseif ($retryFor = $this->getRetryForSecondsAnnotation()) {
            if (self::$timeOfFirstRetry === null) {
                self::$timeOfFirstRetry = \time();
            }

            // Maximum retry duration exceeded
            $secondsRemaining = self::$timeOfFirstRetry + $retryFor - \time();
            if ($secondsRemaining < 0) {
                return false;
            }

            // Log retry
            \printf(
                '[RETRY] Retrying %s (%s %s remaining)' . PHP_EOL,
                $retryAttempt,
                $secondsRemaining,
                $secondsRemaining === 1 ? 'second' : 'seconds'
            );
        } else {
            return false;
        }

        // Execute delay function
        $this->executeRetryDelayFunction($retryAttempt);

        return true;
    }

    private function checkShouldRetryForException(\Exception $e): bool
    {
        if ($retryIfExceptions = $this->getRetryIfExceptionAnnotations()) {
            foreach ($retryIfExceptions as $retryIfException) {
                if ($e instanceof $retryIfException) {
                    return true;
                }
            }
            return false;
        }

        if ($retryIfMethodAnnotation = $this->getRetryIfMethodAnnotation()) {
            list($retryIfMethod, $retryIfMethodArgs) = $retryIfMethodAnnotation;

            \array_unshift($retryIfMethodArgs, $e);
            return \call_user_func_array([$this, $retryIfMethod], $retryIfMethodArgs);
        }

        // Retry all exceptions by default
        return true;
    }

    private function executeRetryDelayFunction(int $retryAttempt)
    {
        if ($delaySeconds = $this->getRetryDelaySecondsAnnotation()) {
            \sleep($delaySeconds);
        } elseif ($delayMethodAnnotation = $this->getRetryDelayMethodAnnotation()) {
            list($delayMethod, $delayMethodArgs) = $delayMethodAnnotation;
            \array_unshift($delayMethodArgs, $retryAttempt);
            \call_user_func_array([$this, $delayMethod], $delayMethodArgs);
        }

        return null;
    }

    /**
     * A delay function implementing an exponential backoff. Use it in your
     * tests like this:
     *
     * /**
     *  * This test will delay with exponential backoff
     *  *
     *  * @retryAttempts 3
     *  * @retryDelayMethod exponentialBackoff
     *  * ...
     *
     * It is also possible to pass an argument to extend the maximum delay
     * seconds, which defaults to 60 seconds:
     *
     * /**
     *  * This test will delay with exponential backoff, with a maximum delay of 1 hr.
     *  *
     *  * @retryAttempts 30
     *  * @retryDelayMethod exponentialBackoff 3600
     *  * ...
     */
    private function exponentialBackoff($retryAttempt, $maxDelaySeconds = 60)
    {
        $sleep = min(
            mt_rand(0, 1000000) + (pow(2, $retryAttempt) * 1000000),
            $maxDelaySeconds * 1000000
        );
        usleep($sleep);
    }
}
