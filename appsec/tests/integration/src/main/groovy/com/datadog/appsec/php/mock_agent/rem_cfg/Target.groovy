package com.datadog.appsec.php.mock_agent.rem_cfg

import groovy.transform.Immutable

@Immutable
class Target {
    String service
    String env
    String appVersion

    static Target create(String service, String env, String appVersion) {
        new Target(service, env, appVersion)
    }
}
