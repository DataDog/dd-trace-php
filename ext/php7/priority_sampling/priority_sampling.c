#include "priority_sampling.h"

#include <mt19937-64.h>

#include <ext/pcre/php_pcre.h>

#include "../configuration.h"
#include "../span.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

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

        add_assoc_double_ex(ddtrace_spandata_property_metrics(&span->span), ZEND_STRL("_dd.rule_psr"), sample_rate);

        bool sampling = (double)genrand64_int64() < sample_rate * (double)~0ULL;

        if (explicit_rule) {
            priority = sampling ? PRIORITY_SAMPLING_USER_KEEP : PRIORITY_SAMPLING_USER_REJECT;
        } else {
            priority = sampling ? PRIORITY_SAMPLING_AUTO_KEEP : PRIORITY_SAMPLING_AUTO_REJECT;
        }
    }
    add_assoc_long_ex(ddtrace_spandata_property_metrics(&span->span), ZEND_STRL("_sampling_priority_v1"), priority);
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

    zval *root_metrics = ddtrace_spandata_property_metrics(&root_span->span);
    ZVAL_DEREF(root_metrics);
    if (Z_TYPE_P(root_metrics) != IS_ARRAY) {
        zval_ptr_dtor(root_metrics);
        array_init(root_metrics);
    }

    if (!(priority_zv = zend_hash_str_find(Z_ARR_P(root_metrics), ZEND_STRL("_sampling_priority_v1")))) {
        if (DDTRACE_G(default_priority_sampling) == DDTRACE_PRIORITY_SAMPLING_UNSET) {
            return DDTRACE_PRIORITY_SAMPLING_UNKNOWN;
        }

        dd_decide_on_sampling(root_span);
        priority_zv = zend_hash_str_find(Z_ARR_P(root_metrics), ZEND_STRL("_sampling_priority_v1"));
    }

    return zval_get_long(priority_zv);
}

void ddtrace_set_prioritySampling_on_root(zend_long priority) {
    ddtrace_span_fci *root_span = DDTRACE_G(root_span);

    if (!root_span) {
        return;
    }

    zval *root_metrics = ddtrace_spandata_property_metrics(&root_span->span);
    ZVAL_DEREF(root_metrics);
    if (Z_TYPE_P(root_metrics) != IS_ARRAY) {
        zval_ptr_dtor(root_metrics);
        array_init(root_metrics);
    }

    if (priority == DDTRACE_PRIORITY_SAMPLING_UNKNOWN || priority == DDTRACE_PRIORITY_SAMPLING_UNSET) {
        zend_hash_str_del(Z_ARR_P(root_metrics), ZEND_STRL("_sampling_priority_v1"));
    } else {
        zval zv;
        ZVAL_LONG(&zv, priority);
        zend_hash_str_update(Z_ARR_P(root_metrics), "_sampling_priority_v1", sizeof("_sampling_priority_v1") - 1, &zv);
    }
}
