<?php

declare(strict_types=1);

namespace DDTrace\OpenFeature;

use DDTrace\Log\Logger;
use DDTrace\Log\NonThrowingLogger;
use OpenFeature\interfaces\flags\EvaluationContext;
use OpenFeature\interfaces\hooks\Hook;
use OpenFeature\interfaces\hooks\HookContext;
use OpenFeature\interfaces\hooks\HookHints;
use OpenFeature\interfaces\provider\ResolutionDetails;

/**
 * Hands each completed flag evaluation to a callback with the Datadog exposure
 * decision, so an application can record exposures in its own data store.
 *
 * Register it on the OpenFeature API or a client. The callback runs for every
 * evaluation by the Datadog provider, including errors and repeats;
 * ExposureEvaluation::shouldSend() selects the new or changed exposures. It is
 * skipped, with one warning, for any other provider, including a MultiProvider. It runs on the evaluating thread, so it
 * must not do I/O. An exception from the callback does not affect the
 * evaluation: the first one is logged as a warning, later ones at debug level.
 */
final class ExposureHook implements Hook
{
    /** @var callable(ExposureEvaluation): void */
    private $callback;
    private bool $warned = false;
    private bool $warnedAboutProvider = false;

    /** @param callable(ExposureEvaluation): void $callback */
    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    public function supportsFlagValueType(string $flagValueType): bool
    {
        return true;
    }

    public function before(HookContext $context, HookHints $hints): ?EvaluationContext
    {
        return null;
    }

    public function after(HookContext $context, ResolutionDetails $details, HookHints $hints): void
    {
    }

    public function error(HookContext $context, \Throwable $error, HookHints $hints): void
    {
    }

    public function finally(HookContext $context, HookHints $hints): void
    {
        try {
            if ($context->getProviderMetadata()->getName() !== DataDogProvider::NAME) {
                $this->warnAboutProvider($context->getProviderMetadata()->getName());
                return;
            }
            $evaluation = FlagEvaluationHook::exposureEvaluation($context)
                ?? new ExposureEvaluation(
                    $context->getFlagKey(),
                    ExposureEvaluation::copyContext($context->getEvaluationContext())
                );
            ($this->callback)($evaluation);
        } catch (\Throwable $error) {
            $this->logFailure($error);
        }
    }

    private function warnAboutProvider(string $name): void
    {
        if ($this->warnedAboutProvider) {
            return;
        }
        $this->warnedAboutProvider = true;
        (new NonThrowingLogger(Logger::get()))->warning(
            'Feature Flags: ExposureHook only reports evaluations by the Datadog provider set directly on OpenFeature; skipping provider {provider}',
            ['provider' => $name]
        );
    }

    private function logFailure(\Throwable $error): void
    {
        try {
            $logger = new NonThrowingLogger(Logger::get());
            $context = ['exception' => $error, 'error' => $error->getMessage()];
            if ($this->warned) {
                $logger->debug('Feature Flags: exposure callback failed: {error}', $context);
            } else {
                $this->warned = true;
                $logger->warning('Feature Flags: exposure callback failed; later failures are logged at debug level: {error}', $context);
            }
        } catch (\Throwable $ignored) {
        }
    }
}
