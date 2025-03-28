<?php

namespace DDTrace\Tests\Common;

use PHPUnit\Util\Test as TestUtil;

/**
 * Trait for validating @retry annotations.
 */
trait RetryAnnotationTrait
{
    private function getRetryAttemptsAnnotation(): int
    {
        $annotations = $this->getTestAnnotations();
        $retries = 0;

        if (isset($annotations['method']['retryAttempts'][0])) {
            $retries = $annotations['method']['retryAttempts'][0];
        } elseif (isset($annotations['class']['retryAttempts'][0])) {
            $retries = $annotations['class']['retryAttempts'][0];
        }

        return $this->parseRetryAttemptsAnnotation($retries);
    }

    private function parseRetryAttemptsAnnotation(string $retries): int
    {
        if ('' === $retries) {
            throw new \InvalidArgumentException(
                'The @retryAttempts annotation requires an integer as an argument'
            );
        }
        if (false === \is_numeric($retries)) {
            throw new \InvalidArgumentException(\sprintf(
                'The @retryAttempts annotation must be an integer but got "%s"',
                \var_export($retries, true)
            ));
        }
        if ((float) $retries !== (float) (int) $retries) {
            throw new \InvalidArgumentException(\sprintf(
                'The @retryAttempts annotation must be an integer but got "%s"',
                (float) $retries
            ));
        }
        $retries = (int) $retries;
        if ($retries < 0) {
            throw new \InvalidArgumentException(\sprintf(
                'The @retryAttempts annotation must be 0 or greater but got "%s".',
                $retries
            ));
        }
        return $retries;
    }

    private function getRetryDelaySecondsAnnotation(): int
    {
        $annotations = $this->getTestAnnotations();
        $retryDelaySeconds = 0;

        if (isset($annotations['method']['retryDelaySeconds'][0])) {
            $retryDelaySeconds = $annotations['method']['retryDelaySeconds'][0];
        } elseif (isset($annotations['class']['retryDelaySeconds'][0])) {
            $retryDelaySeconds = $annotations['class']['retryDelaySeconds'][0];
        }

        return $this->parseRetryDelaySecondsAnnotation($retryDelaySeconds);
    }

    private function parseRetryDelaySecondsAnnotation(string $retryDelaySeconds): int
    {
        if ('' === $retryDelaySeconds) {
            throw new \InvalidArgumentException(
                'The @retryDelaySeconds annotation requires an integer as an argument'
            );
        }
        if (false === \is_numeric($retryDelaySeconds)) {
            throw new \InvalidArgumentException(\sprintf(
                'The @retryDelaySeconds annotation must be an integer but got "%s"',
                \var_export($retryDelaySeconds, true)
            ));
        }
        if ((float) $retryDelaySeconds !== (float) (int) $retryDelaySeconds) {
            throw new \InvalidArgumentException(\sprintf(
                'The @retryDelaySeconds annotation must be an integer but got "%s"',
                floatval($retryDelaySeconds)
            ));
        }
        $retryDelaySeconds = (int) $retryDelaySeconds;
        if ($retryDelaySeconds < 0) {
            throw new \InvalidArgumentException(\sprintf(
                'The @retryDelaySeconds annotation must be 0 or greater but got "%s".',
                $retryDelaySeconds
            ));
        }
        return $retryDelaySeconds;
    }

    private function getRetryDelayMethodAnnotation()
    {
        $annotations = $this->getTestAnnotations();

        if (isset($annotations['method']['retryDelayMethod'][0])) {
            $delayAnnotation = $annotations['method']['retryDelayMethod'];
        } elseif (isset($annotations['class']['retryDelayMethod'][0])) {
            $delayAnnotation = $annotations['class']['retryDelayMethod'];
        } else {
            return null;
        }

        $delayAnnotations = \explode(' ', $delayAnnotation[0]);
        $delayMethod = $delayAnnotations[0];
        $delayMethodArgs = \array_slice($delayAnnotations, 1);

        return [
            $this->parseRetryDelayMethodAnnotation($delayMethod),
            $delayMethodArgs,
        ];
    }

    private function parseRetryDelayMethodAnnotation(string $delayMethod): string
    {
        if ('' === $delayMethod) {
            throw new \InvalidArgumentException(
                'The @retryDelayMethod annotation requires a callable as an argument'
            );
        }
        if (false === \is_callable([$this, $delayMethod])) {
            throw new \InvalidArgumentException(\sprintf(
                'The @retryDelayMethod annotation must be a method in your test class but got "%s"',
                $delayMethod
            ));
        }
        return $delayMethod;
    }

    private function getRetryForSecondsAnnotation()
    {
        $annotations = $this->getTestAnnotations();

        if (isset($annotations['method']['retryForSeconds'][0])) {
            $retryForSeconds = $annotations['method']['retryForSeconds'][0];
        } elseif (isset($annotations['class']['retryForSeconds'][0])) {
            $retryForSeconds = $annotations['class']['retryForSeconds'][0];
        } else {
            return null;
        }

        return $this->parseRetryForSecondsAnnotation($retryForSeconds);
    }

    private function parseRetryForSecondsAnnotation(string $retryForSeconds): int
    {
        if ('' === $retryForSeconds) {
            throw new \InvalidArgumentException(
                'The @retryForSeconds annotation requires an integer as an argument'
            );
        }
        if (false === \is_numeric($retryForSeconds)) {
            throw new \InvalidArgumentException(\sprintf(
                'The @retryForSeconds annotation must be an integer but got "%s"',
                \var_export($retryForSeconds, true)
            ));
        }
        if ((float) $retryForSeconds !== (float)(int) $retryForSeconds) {
            throw new \InvalidArgumentException(\sprintf(
                'The @retryForSeconds annotation must be an integer but got "%s"',
                (float) $retryForSeconds
            ));
        }
        $retryForSeconds = (int) $retryForSeconds;
        if ($retryForSeconds < 0) {
            throw new \InvalidArgumentException(\sprintf(
                'The @retryForSeconds annotation must be 0 or greater but got "%s".',
                $retryForSeconds
            ));
        }
        return $retryForSeconds;
    }

    private function getRetryIfExceptionAnnotations()
    {
        $annotations = $this->getTestAnnotations();

        if (isset($annotations['method']['retryIfException'][0])) {
            $retryIfExceptions = [];
            foreach ($annotations['method']['retryIfException'] as $retryIfException) {
                $this->validateRetryIfExceptionAnnotation($retryIfException);
                $retryIfExceptions[] = $retryIfException;
            }
            return $retryIfExceptions;
        }

        return null;
    }

    private function validateRetryIfExceptionAnnotation(string $retryIfException)
    {
        if ('' === $retryIfException) {
            throw new \InvalidArgumentException(
                'The @retryIfException annotation requires a class name as an argument'
            );
        }

        if (!class_exists($retryIfException)) {
            throw new \InvalidArgumentException(\sprintf(
                'The @retryIfException annotation must be an instance of Exception but got "%s"',
                $retryIfException
            ));
        }
    }

    private function getRetryIfMethodAnnotation()
    {
        $annotations = $this->getTestAnnotations();

        if (!isset($annotations['method']['retryIfMethod'][0])) {
            return null;
        }

        $retryIfMethodAnnotation = \explode(' ', $annotations['method']['retryIfMethod'][0]);
        $retryIfMethod = $retryIfMethodAnnotation[0];
        $retryIfMethodArgs = \array_slice($retryIfMethodAnnotation, 1);

        $this->validateRetryIfMethodAnnotation($retryIfMethod);

        return [
            $retryIfMethod,
            $retryIfMethodArgs,
        ];
    }

    private function validateRetryIfMethodAnnotation(string $retryIfMethod)
    {
        if ('' === $retryIfMethod) {
            throw new \InvalidArgumentException(
                'The @retryIfMethod annotation requires a callable as an argument'
            );
        }
        if (false === \is_callable([$this, $retryIfMethod])) {
            throw new \InvalidArgumentException(\sprintf(
                'The @retryIfMethod annotation must be a method in your test class but got "%s"',
                $retryIfMethod
            ));
        }
    }

    private function getTestAnnotations(): array
    {
        $inheritedAnnotations = array_reduce(class_parents($this), function ($memo, $class) {
            return array_replace_recursive($memo, TestUtil::parseTestMethodAnnotations($class));
        }, []);

        $annotations = TestUtil::parseTestMethodAnnotations(static::class, $this->getName(false));

        return array_replace_recursive($inheritedAnnotations, $annotations);
    }
}
