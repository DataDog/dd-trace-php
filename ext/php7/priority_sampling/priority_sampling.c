#include "priority_sampling.h"

#include <mt19937-64.h>

#include <ext/pcre/php_pcre.h>
#include <ext/standard/base64.h>

#include "../compat_string.h"
#include "../configuration.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

enum dd_sampling_mechanism {
    DD_MECHANISM_AGENT_RATE = 1,
    DD_MECHANISM_REMOTE_RATE = 2,
    DD_MECHANISM_RULE = 3,
    DD_MECHANISM_MANUAL = 4,
};

static void dd_update_upstream_services(ddtrace_span_fci *span, ddtrace_span_fci *deciding_span,
                                        enum dd_sampling_mechanism mechanism) {
    zend_array *meta = ddtrace_spandata_property_meta(&span->span);

    zval *current_services_zv =
        zend_hash_str_find(&DDTRACE_G(root_span_tags_preset), ZEND_STRL("_dd.p.upstream_services"));
    zend_string *current_services = current_services_zv ? Z_STR_P(current_services_zv) : ZSTR_EMPTY_ALLOC();

    zend_long sampling_priority = ddtrace_fetch_prioritySampling_from_root();
    if (DDTRACE_G(propagated_priority_sampling) == sampling_priority ||
        sampling_priority == DDTRACE_PRIORITY_SAMPLING_UNSET) {
        if (ZSTR_LEN(current_services)) {
            zval_addref_p(current_services_zv);
            zend_hash_str_update(meta, "_dd.p.upstream_services", sizeof("_dd.p.upstream_services") - 1,
                                 current_services_zv);
        } else {
            zend_hash_str_del(meta, ZEND_STRL("_dd.p.upstream_services"));
        }
        return;
    }

    zend_string *servicename = ddtrace_convert_to_str(ddtrace_spandata_property_service(&deciding_span->span));
    zend_string *b64_servicename =
        php_base64_encode((const unsigned char *)ZSTR_VAL(servicename), ZSTR_LEN(servicename));
    while (ZSTR_LEN(b64_servicename) > 0 && ZSTR_VAL(b64_servicename)[ZSTR_LEN(b64_servicename) - 1] == '=') {
        ZSTR_VAL(b64_servicename)[--ZSTR_LEN(b64_servicename)] = 0;  // remove padding
    }

    char sampling_rate[7] = {0};
    zend_array *metrics = ddtrace_spandata_property_metrics(&span->span);
    zval *sample_rate, new_services;
    if ((sample_rate = zend_hash_str_find(metrics, ZEND_STRL("_dd.rule_psr")))) {
        snprintf(sampling_rate, 6, "%f", Z_DVAL_P(sample_rate));
    }

    ZVAL_STR(&new_services,
             zend_strpprintf(0, "%s%s%s|%d|%d|%s", ZSTR_VAL(current_services), ZSTR_LEN(current_services) ? ";" : "",
                             ZSTR_VAL(b64_servicename), (int)sampling_priority, mechanism, sampling_rate));
    zend_hash_str_update(meta, "_dd.p.upstream_services", sizeof("_dd.p.upstream_services") - 1, &new_services);

    zend_string_release(servicename);
    zend_string_release(b64_servicename);
}

static bool dd_rule_matches(zval *pattern, zval *prop) {
    if (Z_TYPE_P(pattern) != IS_STRING) {
        return false;
    }
    if (Z_TYPE_P(prop) != IS_STRING) {
        return true;  // default case unset or null must be true, everything else is too then...
    }

    zend_string *regex = zend_strpprintf(0, "(%s)", Z_STRVAL_P(pattern));
    pcre_cache_entry *pce = pcre_get_compiled_regex_cache(regex);
    zval ret;
#if PHP_VERSION_ID < 70400
    php_pcre_match_impl(pce, Z_STRVAL_P(prop), (int)Z_STRLEN_P(prop), &ret, NULL, 0, 0, 0, 0);
#else
    php_pcre_match_impl(pce, Z_STR_P(prop), &ret, NULL, 0, 0, 0, 0);
#endif
    zend_string_release(regex);
    return Z_TYPE(ret) == IS_LONG && Z_LVAL(ret) > 0;
}

static void dd_decide_on_sampling(ddtrace_span_fci *span) {
    int priority = DDTRACE_G(default_priority_sampling);
    // manual if it's not just inherited, otherwise this value is irrelevant (as sampling priority will be default)
    enum dd_sampling_mechanism mechanism = DD_MECHANISM_MANUAL;
    if (priority == DDTRACE_PRIORITY_SAMPLING_UNKNOWN) {
        zval *rule;
        bool explicit_rule = zai_config_memoized_entries[DDTRACE_CONFIG_DD_TRACE_SAMPLE_RATE].name_index >= 0;
        double sample_rate = get_DD_TRACE_SAMPLE_RATE();
        ZEND_HASH_FOREACH_VAL(get_DD_TRACE_SAMPLING_RULES(), rule) {
            if (Z_TYPE_P(rule) != IS_ARRAY) {
                continue;
            }

            bool rule_matches = true;

            zval *rule_pattern;
            if ((rule_pattern = zend_hash_str_find(Z_ARR_P(rule), ZEND_STRL("service")))) {
                rule_matches &= dd_rule_matches(rule_pattern, ddtrace_spandata_property_service(&span->span));
            }
            if ((rule_pattern = zend_hash_str_find(Z_ARR_P(rule), ZEND_STRL("name")))) {
                rule_matches &= dd_rule_matches(rule_pattern, ddtrace_spandata_property_name(&span->span));
            }

            zval *sample_rate_zv;
            if (rule_matches && (sample_rate_zv = zend_hash_str_find(Z_ARR_P(rule), ZEND_STRL("sample_rate")))) {
                sample_rate = zval_get_double(sample_rate_zv);
                explicit_rule = true;
                break;
            }
        }
        ZEND_HASH_FOREACH_END();

        zval sample_rate_zv;
        ZVAL_DOUBLE(&sample_rate_zv, sample_rate);
        zend_hash_str_update(ddtrace_spandata_property_metrics(&span->span), "_dd.rule_psr", sizeof("_dd.rule_psr") - 1,
                             &sample_rate_zv);

        bool sampling = (double)genrand64_int64() < sample_rate * (double)~0ULL;

        if (explicit_rule) {
            mechanism = DD_MECHANISM_RULE;
            priority = sampling ? PRIORITY_SAMPLING_USER_KEEP : PRIORITY_SAMPLING_USER_REJECT;
        } else {
            mechanism = DD_MECHANISM_AGENT_RATE;
            priority = sampling ? PRIORITY_SAMPLING_AUTO_KEEP : PRIORITY_SAMPLING_AUTO_REJECT;
        }
    }

    zval priority_zv;
    ZVAL_LONG(&priority_zv, priority);
    zend_hash_str_update(ddtrace_spandata_property_metrics(&span->span), "_sampling_priority_v1",
                         sizeof("_sampling_priority_v1") - 1, &priority_zv);

    dd_update_upstream_services(span, span, mechanism);
}

zend_long ddtrace_fetch_prioritySampling_from_root(void) {
    zval *priority_zv;
    ddtrace_span_fci *root_span = DDTRACE_G(root_span);

    if (!root_span) {
        if (DDTRACE_G(default_priority_sampling) == DDTRACE_PRIORITY_SAMPLING_UNSET) {
            return DDTRACE_PRIORITY_SAMPLING_UNKNOWN;
        }
        return DDTRACE_G(default_priority_sampling);
    }

    zend_array *root_metrics = ddtrace_spandata_property_metrics(&root_span->span);
    if (!(priority_zv = zend_hash_str_find(root_metrics, ZEND_STRL("_sampling_priority_v1")))) {
        if (DDTRACE_G(default_priority_sampling) == DDTRACE_PRIORITY_SAMPLING_UNSET) {
            return DDTRACE_PRIORITY_SAMPLING_UNKNOWN;
        }

        dd_decide_on_sampling(root_span);
        priority_zv = zend_hash_str_find(root_metrics, ZEND_STRL("_sampling_priority_v1"));
    }

    return zval_get_long(priority_zv);
}

void ddtrace_set_prioritySampling_on_root(zend_long priority) {
    ddtrace_span_fci *root_span = DDTRACE_G(root_span);

    if (!root_span) {
        return;
    }

    zend_array *root_metrics = ddtrace_spandata_property_metrics(&root_span->span);
    if (priority == DDTRACE_PRIORITY_SAMPLING_UNKNOWN || priority == DDTRACE_PRIORITY_SAMPLING_UNSET) {
        zend_hash_str_del(root_metrics, ZEND_STRL("_sampling_priority_v1"));
    } else {
        zval zv;
        ZVAL_LONG(&zv, priority);
        zend_hash_str_update(root_metrics, "_sampling_priority_v1", sizeof("_sampling_priority_v1") - 1, &zv);

        dd_update_upstream_services(root_span, DDTRACE_G(open_spans_top), DD_MECHANISM_MANUAL);
    }
}
