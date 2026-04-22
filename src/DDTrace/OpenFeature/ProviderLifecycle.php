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
 *   - DDTrace\ffe_config_version() for first-ready transition detection
 *
 * The version counter is monotonically-increasing and bumped on every config
 * update. Consumers track their last observed value and compare — unlike a
 * drain-on-read "changed" flag, multiple independent subscribers can detect
 * transitions without racing each other.
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
     * Bridge callable returning the current FFE config version counter.
     * fn(): int
     *
     * @var Closure(): int
     */
    private Closure $configVersionCallable;

    /**
     * Last observed FFE config version. A value greater than this indicates
     * the config has changed since the last check.
     */
    private int $lastSeenVersion = 0;

    /**
     * @param Closure|null $hasConfigCallable Override for DDTrace\ffe_has_config() (testing)
     * @param Closure|null $configVersionCallable Override for DDTrace\ffe_config_version() (testing)
     * @param Closure|null $onReady Callback fired exactly once on first ready transition
     */
    public function __construct(
        ?Closure $hasConfigCallable = null,
        ?Closure $configVersionCallable = null,
        ?Closure $onReady = null,
    ) {
        $this->hasConfigCallable = $hasConfigCallable ?? self::defaultHasConfig();
        $this->configVersionCallable = $configVersionCallable ?? self::defaultConfigVersion();
        $this->onReady = $onReady;
        // Initialize last-seen to current version so a config loaded before
        // construction is not mistakenly observed as a "change".
        $this->lastSeenVersion = ($this->configVersionCallable)();

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
     * Compares the current version against the last seen version. Multiple
     * independent subscribers can call this without racing — each instance
     * tracks its own `lastSeenVersion`.
     *
     * @return bool True if config changed since last check.
     */
    public function checkForConfigChange(): bool
    {
        $currentVersion = ($this->configVersionCallable)();
        $changed = $currentVersion !== $this->lastSeenVersion;
        $this->lastSeenVersion = $currentVersion;

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

        // Sync last-seen version so a subsequent checkForConfigChange() does
        // not interpret the transition itself as another change.
        $this->lastSeenVersion = ($this->configVersionCallable)();

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
     * Default bridge callable for DDTrace\ffe_config_version().
     *
     * @return Closure(): int
     */
    private static function defaultConfigVersion(): Closure
    {
        return static function (): int {
            if (!function_exists('DDTrace\ffe_config_version')) {
                return 0;
            }
            return \DDTrace\ffe_config_version();
        };
    }
}
