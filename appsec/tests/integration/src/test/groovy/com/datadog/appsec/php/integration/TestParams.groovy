package com.datadog.appsec.php.integration

class TestParams {
    static String getPhpVersion() {
        System.getProperty('PHP_VERSION') ?: ''
    }
    static boolean phpVersionAtLeast(String requiredVersion) {
        String version = System.getProperty('PHP_VERSION')
        if (!version) {
            return false
        }
        int versionI = version.split('\\.').collect { it.toInteger() }.inject(0) {
            acc, it -> acc * 100 + it
        }
        int requiredVersionI = requiredVersion.split('\\.').collect { it.toInteger() }.inject(0) {
            acc, it -> acc * 100 + it
        }
        versionI >= requiredVersionI
    }
    static String getVariant() {
        System.getProperty('VARIANT') ?: ''
    }
    static String getTracerVersion() {
        System.getProperty('TRACER_VERSION') ?: ''
    }
}
