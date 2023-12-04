package com.datadog.appsec.php.model

import com.fasterxml.jackson.core.JsonParser
import com.fasterxml.jackson.core.type.TypeReference
import com.fasterxml.jackson.databind.DeserializationContext
import com.fasterxml.jackson.databind.JsonDeserializer
import com.fasterxml.jackson.databind.ObjectMapper

class Trace implements List<Span> {
    BigInteger getTraceId() {
        spans.first().traceId
    }

    @Delegate
    List<Span> spans

    static class TraceDeserializer extends JsonDeserializer<Trace> {
        @Override
        Trace deserialize(JsonParser jp, DeserializationContext ctxt) {
            ObjectMapper mapper = (ObjectMapper) jp.getCodec()
            List<Span> spans = mapper.readValue(jp, new TypeReference<List<Span>>() {})
            new Trace(spans: spans)
        }
    }
}
