package com.datadog.appsec.php.integration

class TestParams {
    static String getPhpVersion() {
        System.getProperty 'PHP_VERSION'
    }
    static String getVariant() {
        System.getProperty 'VARIANT'
    }
    static String getTracerVersion() {
        System.getProperty 'TRACER_VERSION'
    }
}
