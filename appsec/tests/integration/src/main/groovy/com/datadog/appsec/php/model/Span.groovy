package com.datadog.appsec.php.model

import com.fasterxml.jackson.annotation.JsonProperty
import groovy.json.JsonSlurper

class Span {
    @JsonProperty("trace_id")
    BigInteger traceId

    @JsonProperty("span_id")
    BigInteger spanId

    @JsonProperty("parent_id")
    BigInteger parentId

    boolean error

    Long start
    Long duration
    String name
    String resource
    String service
    String type
    Map<String, String> meta
    Map<String, Double> metrics
    Map<String, String> meta_struct

    Map<String, ?> getParsedAppsecJson() {
        def s = meta?.get('_dd.appsec.json')
        s ? new JsonSlurper().parseText(s) : null
    }
}
