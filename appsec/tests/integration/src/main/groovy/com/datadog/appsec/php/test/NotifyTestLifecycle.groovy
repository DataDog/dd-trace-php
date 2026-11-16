package com.datadog.appsec.php.test

import groovy.util.logging.Slf4j
import org.junit.jupiter.api.extension.AfterEachCallback
import org.junit.jupiter.api.extension.BeforeEachCallback
import org.junit.jupiter.api.extension.ExtensionContext

@Slf4j
class NotifyTestLifecycle implements BeforeEachCallback, AfterEachCallback {
    @Override
    void beforeEach(ExtensionContext context) throws Exception {
        log.info("Starting test ${context.displayName}")
    }

    @Override
    void afterEach(ExtensionContext context) throws Exception {
        if (context.executionException.present) {
            def e = context.executionException.get()
            log.error("Finished test ${context.displayName} (failed with exception: ${e.message})")
        } else {
            log.info("Finished test ${context.displayName} (success)")
        }

    }
}
