package com.datadog.appsec.php.test

import com.datadog.appsec.php.docker.AppSecContainer
import groovy.util.logging.Slf4j
import org.junit.jupiter.api.extension.BeforeEachCallback
import org.junit.jupiter.api.extension.ExtensionContext

@Slf4j
class ClearTelemetryExtension  implements BeforeEachCallback {
    @Override
    void beforeEach(ExtensionContext context) throws Exception {
        Class testClass = context.requiredTestClass
        try {
            def containerField = testClass.getDeclaredField('CONTAINER')
            containerField.accessible = true

            AppSecContainer container = containerField.get(null) // Assuming the field is static
            def tel = container?.drainTelemetry(0)
            if (tel) {
                log.info("Cleared ${tel.size()} telemetry messages before '${context.displayName}'")
            }
        } catch (NoSuchFieldException ignored) {
            // No action needed if the field does not exist
        } catch (Exception e) {
            throw new RuntimeException("Error stopping the container", e)
        }
    }
}
