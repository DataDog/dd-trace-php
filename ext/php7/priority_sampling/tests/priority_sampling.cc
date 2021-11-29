extern "C" {
#include "priority_sampling/priority_sampling.h"
#include "zai_sapi/zai_sapi.h"
#include "zai_sapi/zai_sapi_extension.h"
#include "configuration.h"
}

#include <catch2/catch.hpp>
#include <cstdio>
#include <cstring>

ZEND_DECLARE_MODULE_GLOBALS(ddtrace)

zval config_sample_rate, config_sampling_rules;
unsigned long long very_random_integer;

extern "C" {
    zval *zai_config_get_value(zai_config_id id) {
        if (id == DDTRACE_CONFIG_DD_TRACE_SAMPLE_RATE) {
            return &config_sample_rate;
        }
        if (id == DDTRACE_CONFIG_DD_TRACE_SAMPLING_RULES) {
            return &config_sampling_rules;
        }
        REQUIRE(!"Unexpected config value read");
        return NULL;
    }

    zai_config_memoized_entry zai_config_memoized_entries[ZAI_CONFIG_ENTRIES_COUNT_MAX];

    unsigned long long genrand64_int64() { return very_random_integer; }
}

ZEND_GINIT_FUNCTION(ddtrace) {}

#define TEST_SAMPLING(name, code) TEST_CASE(name, "[priority_sampling]") { \
        REQUIRE(zai_sapi_spinup()); \
        ZAI_SAPI_ABORT_ON_BAILOUT_OPEN() \
        ZEND_INIT_MODULE_GLOBALS(ddtrace, ZEND_MODULE_GLOBALS_CTOR_N(ddtrace), NULL); \
\
        DDTRACE_G(default_priority_sampling) = DDTRACE_PRIORITY_SAMPLING_UNKNOWN; \
        ddtrace_span_fci span = {0}; \
        DDTRACE_G(root_span) = &span; \
        very_random_integer = 0; \
        array_init(&config_sampling_rules); \
        ZVAL_DOUBLE(&config_sample_rate, 1); \
        zai_config_memoized_entries[DDTRACE_CONFIG_DD_TRACE_SAMPLE_RATE].name_index = -1; \
\
        { code } \
\
        zval_ptr_dtor(&config_sampling_rules); \
        for (int i = 0; i <= sizeof(span.span.properties_table_placeholder) / sizeof(zval); ++i) { \
            zval_ptr_dtor(OBJ_PROP_NUM(&span.span.std, i)); \
        } \
        ZAI_SAPI_ABORT_ON_BAILOUT_CLOSE() \
        zai_sapi_spindown(); \
    }

TEST_SAMPLING("default sampling", {
    REQUIRE(ddtrace_fetch_prioritySampling_from_root() == PRIORITY_SAMPLING_AUTO_KEEP);
    zend_array *metrics = Z_ARR_P(ddtrace_spandata_property_metrics(&DDTRACE_G(root_span)->span));
    zval *prio = zend_hash_str_find(metrics, ZEND_STRL("_sampling_priority_v1"));
    REQUIRE(prio);
    REQUIRE(Z_TYPE_P(prio) == IS_LONG);
    REQUIRE(Z_LVAL_P(prio) == PRIORITY_SAMPLING_AUTO_KEEP);
    zval *psr = zend_hash_str_find(metrics, ZEND_STRL("_dd.rule_psr"));
    REQUIRE(psr);
    REQUIRE(Z_TYPE_P(psr) == IS_DOUBLE);
    REQUIRE(Z_DVAL_P(psr) == 1);

    REQUIRE(ddtrace_fetch_prioritySampling_from_root() == PRIORITY_SAMPLING_AUTO_KEEP);
})

TEST_SAMPLING("low sampling probability - reject", {
    zai_config_memoized_entries[DDTRACE_CONFIG_DD_TRACE_SAMPLE_RATE].name_index = 0;
    ZVAL_DOUBLE(&config_sample_rate, 0.99);
    very_random_integer = ~0ULL - 100;

    REQUIRE(ddtrace_fetch_prioritySampling_from_root() == PRIORITY_SAMPLING_USER_REJECT);

    // only sampled once
    very_random_integer = 0;
    REQUIRE(ddtrace_fetch_prioritySampling_from_root() == PRIORITY_SAMPLING_USER_REJECT);

    zend_array *metrics = Z_ARR_P(ddtrace_spandata_property_metrics(&DDTRACE_G(root_span)->span));
    REQUIRE(Z_LVAL_P(zend_hash_str_find(metrics, ZEND_STRL("_sampling_priority_v1"))) == PRIORITY_SAMPLING_USER_REJECT);
    REQUIRE(Z_DVAL_P(zend_hash_str_find(metrics, ZEND_STRL("_dd.rule_psr"))) == 0.99);
})

TEST_SAMPLING("low sampling probability - keep", {
    zai_config_memoized_entries[DDTRACE_CONFIG_DD_TRACE_SAMPLE_RATE].name_index = 1;
    ZVAL_DOUBLE(&config_sample_rate, 0.01);
    very_random_integer = 100;

    REQUIRE(ddtrace_fetch_prioritySampling_from_root() == PRIORITY_SAMPLING_USER_KEEP);
})

TEST_SAMPLING("sampling rules - simple rule", {
    ZVAL_DOUBLE(&config_sample_rate, 0.99);
    very_random_integer = 1ULL << 63;

    ZVAL_STRING(ddtrace_spandata_property_name(&DDTRACE_G(root_span)->span), "fooname");
    ZVAL_STRING(ddtrace_spandata_property_service(&DDTRACE_G(root_span)->span), "barservice");

    zval rule;
    array_init(&rule);
    add_assoc_double(&rule, "sample_rate", 0.3);
    add_next_index_zval(&config_sampling_rules, &rule);

    REQUIRE(ddtrace_fetch_prioritySampling_from_root() == PRIORITY_SAMPLING_USER_REJECT);
    zend_array *metrics = Z_ARR_P(ddtrace_spandata_property_metrics(&DDTRACE_G(root_span)->span));
    REQUIRE(Z_DVAL_P(zend_hash_str_find(metrics, ZEND_STRL("_dd.rule_psr"))) == 0.3);
})

TEST_SAMPLING("sampling rules - rule with name", {
    ZVAL_DOUBLE(&config_sample_rate, 0.99);
    very_random_integer = 1ULL << 63;

    ZVAL_STRING(ddtrace_spandata_property_name(&DDTRACE_G(root_span)->span), "fooname");
    ZVAL_STRING(ddtrace_spandata_property_service(&DDTRACE_G(root_span)->span), "barservice");

    zval rule;
    array_init(&rule);
    add_assoc_double(&rule, "sample_rate", 0.3);
    add_assoc_string(&rule, "name", "foo");
    add_next_index_zval(&config_sampling_rules, &rule);

    array_init(&rule);
    add_assoc_double(&rule, "sample_rate", 0.7);
    add_assoc_string(&rule, "name", "bar");
    add_next_index_zval(&config_sampling_rules, &rule);

    REQUIRE(ddtrace_fetch_prioritySampling_from_root() == PRIORITY_SAMPLING_USER_REJECT);
    zend_array *metrics = Z_ARR_P(ddtrace_spandata_property_metrics(&DDTRACE_G(root_span)->span));
    REQUIRE(Z_DVAL_P(zend_hash_str_find(metrics, ZEND_STRL("_dd.rule_psr"))) == 0.3);
})

TEST_SAMPLING("sampling rules - rule with service", {
    ZVAL_DOUBLE(&config_sample_rate, 0.99);
    very_random_integer = 1ULL << 63;

    ZVAL_STRING(ddtrace_spandata_property_name(&DDTRACE_G(root_span)->span), "fooname");
    ZVAL_STRING(ddtrace_spandata_property_service(&DDTRACE_G(root_span)->span), "barservice");

    zval rule;
    array_init(&rule);
    add_assoc_double(&rule, "sample_rate", 0.3);
    add_assoc_string(&rule, "service", "foo");
    add_next_index_zval(&config_sampling_rules, &rule);

    array_init(&rule);
    add_assoc_double(&rule, "sample_rate", 0.7);
    add_assoc_string(&rule, "service", "bar");
    add_next_index_zval(&config_sampling_rules, &rule);

    REQUIRE(ddtrace_fetch_prioritySampling_from_root() == PRIORITY_SAMPLING_USER_KEEP);
    zend_array *metrics = Z_ARR_P(ddtrace_spandata_property_metrics(&DDTRACE_G(root_span)->span));
    REQUIRE(Z_DVAL_P(zend_hash_str_find(metrics, ZEND_STRL("_dd.rule_psr"))) == 0.7);
})

TEST_SAMPLING("sampling rules - rule with name and service", {
    ZVAL_DOUBLE(&config_sample_rate, 0.99);
    very_random_integer = 1ULL << 63;

    ZVAL_STRING(ddtrace_spandata_property_name(&DDTRACE_G(root_span)->span), "fooname");
    ZVAL_STRING(ddtrace_spandata_property_service(&DDTRACE_G(root_span)->span), "barservice");

    zval rule;
    array_init(&rule);
    add_assoc_double(&rule, "sample_rate", 0.7);
    add_assoc_string(&rule, "name", "fo.*ame");
    add_assoc_string(&rule, "service", "bar");
    add_next_index_zval(&config_sampling_rules, &rule);

    array_init(&rule);
    add_assoc_double(&rule, "sample_rate", 0.3);
    add_next_index_zval(&config_sampling_rules, &rule);

    REQUIRE(ddtrace_fetch_prioritySampling_from_root() == PRIORITY_SAMPLING_USER_KEEP);
    zend_array *metrics = Z_ARR_P(ddtrace_spandata_property_metrics(&DDTRACE_G(root_span)->span));
    REQUIRE(Z_DVAL_P(zend_hash_str_find(metrics, ZEND_STRL("_dd.rule_psr"))) == 0.7);
})

TEST_SAMPLING("sampling decision retained if pre-set", {
    ddtrace_set_prioritySampling_on_root(PRIORITY_SAMPLING_USER_REJECT);

    ZVAL_DOUBLE(&config_sample_rate, 0.01);
    very_random_integer = 100;

    REQUIRE(ddtrace_fetch_prioritySampling_from_root() == PRIORITY_SAMPLING_USER_REJECT);
    zend_array *metrics = Z_ARR_P(ddtrace_spandata_property_metrics(&DDTRACE_G(root_span)->span));
    REQUIRE(!zend_hash_str_exists(metrics, ZEND_STRL("_dd.rule_psr")));
})

TEST_SAMPLING("sampling decision retained from default", {
    DDTRACE_G(default_priority_sampling) = PRIORITY_SAMPLING_USER_REJECT;

    ZVAL_DOUBLE(&config_sample_rate, 0.01);
    very_random_integer = 100;

    REQUIRE(ddtrace_fetch_prioritySampling_from_root() == PRIORITY_SAMPLING_USER_REJECT);
    zend_array *metrics = Z_ARR_P(ddtrace_spandata_property_metrics(&DDTRACE_G(root_span)->span));
    REQUIRE(!zend_hash_str_exists(metrics, ZEND_STRL("_dd.rule_psr")));
})
