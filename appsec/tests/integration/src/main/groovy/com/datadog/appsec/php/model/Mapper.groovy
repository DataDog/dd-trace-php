package com.datadog.appsec.php.model

import com.fasterxml.jackson.core.JsonParser
import com.fasterxml.jackson.databind.DeserializationContext
import com.fasterxml.jackson.databind.JsonDeserializer
import com.fasterxml.jackson.databind.ObjectMapper
import com.fasterxml.jackson.databind.module.SimpleModule

class Mapper {
    public static final ObjectMapper INSTANCE = createMapper()

    private static ObjectMapper createMapper() {
        ObjectMapper mapper = new ObjectMapper()
        SimpleModule module = new SimpleModule()
        module.addDeserializer(Trace, new Trace.TraceDeserializer())
        mapper.registerModule(module)
    }
}
