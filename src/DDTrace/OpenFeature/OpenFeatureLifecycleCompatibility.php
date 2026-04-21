<?php

declare(strict_types=1);

namespace DDTrace\OpenFeature;

use OpenFeature\OpenFeatureAPI;

/**
 * Host-integration compatibility layer for the standard OpenFeature lifecycle surface.
 *
 * The published PHP SDK (2.1.x) does not yet expose setProviderAndWait() or
 * provider eventing. This class provides the expected blocking-init behavior
 * so that host integrations can write:
 *
 *     OpenFeatureLifecycleCompatibility::setProviderAndWait(new DataDogProvider());
 *
 * and get the same outcome as the standard OpenFeature lifecycle contract:
 *   - The call blocks until the provider's first config is available or timeout expires.
 *   - PROVIDER_READY fires exactly once via the provider's lifecycle helper.
 *   - Non-blocking registration is available via the normal OpenFeatureAPI::setProvider().
 *
 * This is an internal compatibility artifact, not a replacement for the standard
 * OpenFeature API. When the PHP SDK adds native setProviderAndWait() and eventing,
 * this class should be deprecated in favor of the SDK-native surface.
 *
 * @internal Datadog host-integration layer. Not a public Datadog API.
 */
final class OpenFeatureLifecycleCompatibility
{
    /**
     * Register a DataDogProvider with blocking initialization.
     *
     * Blocks until the provider's lifecycle reports ready or the timeout expires.
     * After registration, the OpenFeature SDK client will use this provider for
     * all flag evaluations.
     *
     * @param DataDogProvider $provider The configured Datadog provider instance.
     * @param float $timeoutSeconds Maximum blocking wait time (default 5.0 seconds).
     * @return bool True if the provider became ready within the timeout, false otherwise.
     */
    public static function setProviderAndWait(
        DataDogProvider $provider,
        float $timeoutSeconds = 5.0,
    ): bool {
        // Register with the standard OpenFeature API
        OpenFeatureAPI::getInstance()->setProvider($provider);

        // Populate the batch-level service context block in the sidecar's EXPOSURE_STATE
        // so the flush payload wrapper includes DD_SERVICE/DD_ENV/DD_VERSION. Without this,
        // per-event exposure data is still correct but the batch envelope ships empty.
        $provider->initializeServiceContext();

        // Block until ready or timeout
        $lifecycle = $provider->getLifecycle();

        return $lifecycle->waitUntilReady($timeoutSeconds);
    }

    /**
     * Register a DataDogProvider for non-blocking initialization.
     *
     * Returns immediately. The provider will return defaults until config arrives.
     * This is equivalent to calling OpenFeatureAPI::getInstance()->setProvider()
     * directly, but explicitly names the non-blocking intent.
     *
     * @param DataDogProvider $provider The configured Datadog provider instance.
     */
    public static function setProvider(DataDogProvider $provider): void
    {
        OpenFeatureAPI::getInstance()->setProvider($provider);
        $provider->initializeServiceContext();
    }

    /**
     * Prevent instantiation -- static utility only.
     */
    private function __construct()
    {
    }
}
