package com.datadog.appsec.php.mock_agent.rem_cfg

enum Capability {
    ASM_ACTIVATION(1),
    ASM_IP_BLOCKING(2),
    ASM_DD_RULES(3),
    ASM_EXCLUSIONS(4),
    ASM_REQUEST_BLOCKING(5),
    ASM_RESPONSE_BLOCKING(6),
    ASM_USER_BLOCKING(7),
    ASM_CUSTOM_RULES(8),
    ASM_CUSTOM_BLOCKING_RESPONSE(9),
    ASM_TRUSTED_IPS(10),
    ASM_API_SECURITY_SAMPLE_RATE(11),
    APM_TRACING_SAMPLE_RATE(12),
    APM_TRACING_LOGS_INJECTION(13),
    APM_TRACING_HTTP_HEADER_TAGS(14),
    APM_TRACING_CUSTOM_TAGS(15),
    ASM_PROCESSOR_OVERRIDES(16),
    ASM_CUSTOM_DATA_SCANNERS(17),
    ASM_EXCLUSION_DATA(18),
    APM_TRACING_ENABLED(19),
    APM_TRACING_DATA_STREAMS_ENABLED(20),
    ASM_RASP_SQLI(21),
    ASM_RASP_LFI(22),
    ASM_RASP_SSRF(23),
    ASM_RASP_SHI(24),
    ASM_RASP_XXE(25),
    ASM_RASP_RCE(26),
    ASM_RASP_NOSQLI(27),
    ASM_RASP_XSS(28),
    APM_TRACING_SAMPLE_RULES(29),
    CSM_ACTIVATION(30),
    ASM_DD_MULTICONFIG(42),
    ASM_TRACE_TAGGING_RULES(43)

    final int ordinal

    Capability(int ordinal) {
        this.ordinal = ordinal
    }

    static EnumSet<Capability> forByteArray(byte[] arr) {
        def capabilities = EnumSet.noneOf(Capability)
        def bi = new BigInteger(arr)
        for (Capability c: values()) {
            if (bi.testBit(c.ordinal)) {
                capabilities << c
            }
        }
        capabilities
    }
}
