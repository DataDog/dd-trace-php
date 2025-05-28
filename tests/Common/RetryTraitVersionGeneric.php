<?php

namespace DDTrace\Tests\Common;

use PHPUnit\Framework\IncompleteTestError;
use PHPUnit\Framework\SkippedTestError;

/**
 * Trait for handling version-specific method declarations for PHP 7.1+.
 */
trait RetryTraitVersionGeneric
{
    /**
     * @var int|null
     */
    private static $timeOfFirstRetry;

    /**
     * Main test loop to implement retry annotations.
     */
    public function runBare(): void
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
                // If this is a web framework test and we have a server, try to restart it
                if ($this instanceof \DDTrace\Tests\Common\WebFrameworkTestCase) {
                    $reflection = new \ReflectionClass(\DDTrace\Tests\Common\WebFrameworkTestCase::class);
                    $property = $reflection->getProperty('appServer');
                    $property->setAccessible(true);
                    $appServer = $property->getValue(null);

                    if ($appServer) {
                        echo "[RetryTrait] Attempting to restart web server before retry" . PHP_EOL;
                        $appServer->stop();
                        usleep(500000); // Wait 500ms for ports to be freed
                        \DDTrace\Tests\Common\WebFrameworkTestCase::setUpWebServer();
                    }
                }
            }
            if (!$this->checkShouldRetryForException($e)) {
                throw $e;
            }
            $retryAttempt++;
        } while ($this->checkShouldRetryAgain($retryAttempt));

        throw $e;
    }
}
