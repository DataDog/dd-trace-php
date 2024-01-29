package com.datadog.appsec.php.test

import org.junit.jupiter.api.extension.AfterAllCallback
import org.junit.jupiter.api.extension.ExtensionContext

class StopContainerExtension implements AfterAllCallback {
    @Override
    void afterAll(ExtensionContext context) {
        Class testClass = context.requiredTestClass
        try {
            def containerField = testClass.getDeclaredField('CONTAINER')
            containerField.accessible = true

            def container = containerField.get(null) // Assuming the field is static
            container?.close()
        } catch (NoSuchFieldException ignored) {
            // No action needed if the field does not exist
        } catch (Exception e) {
            throw new RuntimeException("Error stopping the container", e)
        }
    }
}
