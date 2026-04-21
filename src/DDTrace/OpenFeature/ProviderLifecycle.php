<?php

declare(strict_types=1);

namespace DDTrace\OpenFeature;

use Closure;

/**
 * Provider lifecycle state management.
 *
 * Encapsulates the readiness state machine for the Datadog OpenFeature provider.
 * Readiness is derived from the FFE bridge config signals:
 *   - DDTrace\ffe_has_config() for simple "can evaluate now" checks
 *   - DDTrace\ffe_config_changed() for first-ready transition detection (single consumer)
 *
 * This class is the single owner of readiness state. No other code should call
 * ffe_config_changed() directly -- it uses compare-and-swap semantics that reset
 * on read, so multiple consumers would race and lose transitions.
 *
 * Supports:
 *   - Blocking init: waits until config arrives or timeout expires
 *   - Non-blocking init: returns immediately, defaults until ready
 *   - First-ready detection: fires PROVIDER_READY callback exactly once
 *
 * @internal Used by DataDogProvider and OpenFeatureLifecycleCompatibility. Not public API.
 */
final class ProviderLifecycle
{
    /**
     * Whether the provider has transitioned to the ready state at least once.
     */
    private bool $ready = false;

    /**
     * Whether the PROVIDER_READY callback has been fired.
     * Separate from $ready to ensure exactly-once semantics.
     */
    private bool $readyEventFired = false;

    /**
     * Optional callback invoked exactly once when the provider first becomes ready.
     *
     * @var Closure|null
     */
    private ?Closure $onReady = null;

    /**
     * Bridge callable for checking if config is loaded.
     * fn(): bool
     *
     * @var Closure(): bool
     */
    private Closure $hasConfigCallable;

    /**
     * Bridge callable for checking if config has changed since last call.
     * fn(): bool  (compare-and-swap: resets on read)
     *
     * @var Closure(): bool
     */
    private Closure $configChangedCallable;

    /**
     * @param Closure|null $hasConfigCallable Override for DDTrace\ffe_has_config() (testing)
     * @param Closure|null $configChangedCallable Override for DDTrace\ffe_config_changed() (testing)
     * @param Closure|null $onReady Callback fired exactly once on first ready transition
     */
    public function __construct(
        ?Closure $hasConfigCallable = null,
        ?Closure $configChangedCallable = null,
        ?Closure $onReady = null,
    ) {
        $this->hasConfigCallable = $hasConfigCallable ?? self::defaultHasConfig();
        $this->configChangedCallable = $configChangedCallable ?? self::defaultConfigChanged();
        $this->onReady = $onReady;

        // Check initial state -- if config is already loaded (e.g., preloaded),
        // mark as ready immediately.
        if (($this->hasConfigCallable)()) {
            $this->transitionToReady();
        }
    }

    /**
     * Whether the provider is currently ready to evaluate flags.
     *
     * Once ready, stays ready. Readiness is sticky -- config removal is not
     * modeled in the current bridge surface.
     */
    public function isReady(): bool
    {
        if ($this->ready) {
            return true;
        }

        // Poll the bridge for config availability
        if (($this->hasConfigCallable)()) {
            $this->transitionToReady();
            return true;
        }

        return false;
    }

    /**
     * Block until config is available or timeout expires.
     *
     * Uses a polling loop with a small sleep interval. The bridge functions
     * are lightweight lock-check operations, so polling is acceptable.
     *
     * @param float $timeoutSeconds Maximum time to wait (in seconds). 0 = no wait.
     * @param int $pollIntervalMicroseconds Polling interval (default 10ms).
     * @return bool True if ready within timeout, false if timed out.
     */
    public function waitUntilReady(float $timeoutSeconds = 5.0, int $pollIntervalMicroseconds = 10_000): bool
    {
        if ($this->isReady()) {
            return true;
        }

        if ($timeoutSeconds <= 0) {
            return false;
        }

        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            if (($this->hasConfigCallable)()) {
                $this->transitionToReady();
                return true;
            }
            usleep($pollIntervalMicroseconds);
        }

        // Final check after sleep
        if (($this->hasConfigCallable)()) {
            $this->transitionToReady();
            return true;
        }

        return false;
    }

    /**
     * Check for config changes and update readiness state.
     *
     * This is the ONLY place that should consume ffe_config_changed().
     * The compare-and-swap semantics mean each call resets the changed flag,
     * so only one consumer should ever call it.
     *
     * @return bool True if config changed since last check.
     */
    public function checkForConfigChange(): bool
    {
        $changed = ($this->configChangedCallable)();

        if ($changed && !$this->ready) {
            $this->transitionToReady();
        }

        return $changed;
    }

    /**
     * Register a callback to fire exactly once when the provider becomes ready.
     *
     * If already ready, the callback fires immediately (synchronously).
     *
     * @param Closure $callback The callback to invoke on PROVIDER_READY.
     */
    public function onReady(Closure $callback): void
    {
        if ($this->readyEventFired) {
            // Already fired -- invoke immediately for late subscribers
            $callback();
            return;
        }

        $this->onReady = $callback;

        // If ready but event hasn't fired yet (shouldn't normally happen),
        // fire it now.
        if ($this->ready && !$this->readyEventFired) {
            $this->fireReadyEvent();
        }
    }

    /**
     * Transition to the ready state and fire the PROVIDER_READY event.
     */
    private function transitionToReady(): void
    {
        if ($this->ready) {
            return; // Already transitioned
        }

        $this->ready = true;

        // Drain the config_changed flag so it doesn't fire a spurious
        // change notification later when someone calls checkForConfigChange().
        ($this->configChangedCallable)();

        $this->fireReadyEvent();
    }

    /**
     * Fire the PROVIDER_READY callback exactly once.
     */
    private function fireReadyEvent(): void
    {
        if ($this->readyEventFired) {
            return;
        }

        $this->readyEventFired = true;

        if ($this->onReady !== null) {
            ($this->onReady)();
        }
    }

    /**
     * Default bridge callable for DDTrace\ffe_has_config().
     *
     * @return Closure(): bool
     */
    private static function defaultHasConfig(): Closure
    {
        return static function (): bool {
            if (!function_exists('DDTrace\ffe_has_config')) {
                return false;
            }
            return \DDTrace\ffe_has_config();
        };
    }

    /**
     * Default bridge callable for DDTrace\ffe_config_changed().
     *
     * @return Closure(): bool
     */
    private static function defaultConfigChanged(): Closure
    {
        return static function (): bool {
            if (!function_exists('DDTrace\ffe_config_changed')) {
                return false;
            }
            return \DDTrace\ffe_config_changed();
        };
    }
}
