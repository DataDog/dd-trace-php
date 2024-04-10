#include "../compat_string.h"
#include "priority_sampling.h"

#include <vendor/mt19937/mt19937-64.h>

#include <uri_normalization/uri_normalization.h>
#include <json/json.h>

#include "../configuration.h"

#include "../limiter/limiter.h"
#include "ddshared.h"
#include "ddtrace.h"
#include "span.h"
#include "components/log/log.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

void ddtrace_try_read_agent_rate(void) {
    ddog_CharSlice data;
    if (DDTRACE_G(agent_config_reader) && ddog_agent_remote_config_read(DDTRACE_G(agent_config_reader), &data)) {
        zval json;
        if ((int)data.len > 0 && zai_json_decode_assoc_safe(&json, data.ptr, (int)data.len, 3, true) == SUCCESS) {
            if (Z_TYPE(json) == IS_ARRAY) {
                zval *rules = zend_hash_str_find(Z_ARR(json), ZEND_STRL("rate_by_service"));
                if (rules && Z_TYPE_P(rules) == IS_ARRAY) {
                    if (DDTRACE_G(agent_rate_by_service)) {
                        zai_json_release_persistent_array(DDTRACE_G(agent_rate_by_service));
                    }

                    Z_TRY_ADDREF_P(rules);
                    DDTRACE_G(agent_rate_by_service) = Z_ARR_P(rules);
                }
            }
            zai_json_dtor_pzval(&json);
        }
    }
}

static void dd_update_decision_maker_tag(ddtrace_root_span_data *root_span, enum dd_sampling_mechanism mechanism) {
    zend_array *meta = ddtrace_property_array(&root_span->property_meta);

    zend_long sampling_priority = zval_get_long(&root_span->property_sampling_priority);
    if (Z_TYPE(root_span->property_propagated_sampling_priority) != IS_UNDEF && zval_get_long(&root_span->property_propagated_sampling_priority) == sampling_priority) {
        return;
    }

    if (sampling_priority > 0 && sampling_priority != DDTRACE_PRIORITY_SAMPLING_UNKNOWN) {
        zval dm;
        ZVAL_STR(&dm, zend_strpprintf(0, "-%d", mechanism));
        zend_hash_str_update(meta, "_dd.p.dm", sizeof("_dd.p.dm") - 1, &dm);
    } else {
        zend_hash_str_del(meta, "_dd.p.dm", sizeof("_dd.p.dm") - 1);
    }
}

static bool dd_check_sampling_rule(zend_array *rule, ddtrace_span_data *span) {
    zval *service = &span->property_service;

    zval *rule_pattern;
    if ((rule_pattern = zend_hash_str_find(rule, ZEND_STRL("service")))) {
        if (Z_TYPE_P(service) == IS_STRING) {
            zval *mapped_service = zend_hash_find(get_DD_SERVICE_MAPPING(), Z_STR_P(service));
            if (!mapped_service) {
                mapped_service = service;
            }
            if (!dd_rule_matches(rule_pattern, mapped_service, get_DD_TRACE_SAMPLING_RULES_FORMAT())) {
                return false;
            }
        }
    }
    if ((rule_pattern = zend_hash_str_find(rule, ZEND_STRL("name")))) {
        if (!dd_rule_matches(rule_pattern, &span->property_name, get_DD_TRACE_SAMPLING_RULES_FORMAT())) {
            return false;
        }
    }
    if ((rule_pattern = zend_hash_str_find(rule, ZEND_STRL("resource")))) {
        if (!dd_rule_matches(rule_pattern, &span->property_resource, get_DD_TRACE_SAMPLING_RULES_FORMAT())) {
            return false;
        }
    }
    if ((rule_pattern = zend_hash_str_find(rule, ZEND_STRL("tags"))) && Z_TYPE_P(rule_pattern) == IS_ARRAY) {
        zend_array *tag_rules = Z_ARR_P(rule_pattern);
        zend_array *meta = ddtrace_property_array(&span->property_meta);
        zend_array *metrics = ddtrace_property_array(&span->property_metrics);
        zend_string *tag_name;
        ZEND_HASH_FOREACH_STR_KEY_VAL(tag_rules, tag_name, rule_pattern) {
            if (tag_name) {
                zval *value;
                if (!(value = zend_hash_find(meta, tag_name)) && !(value = zend_hash_find(metrics, tag_name))) {
                    return false;
                }
                if (!dd_rule_matches(rule_pattern, value, get_DD_TRACE_SAMPLING_RULES_FORMAT())) {
                    return false;
                }
            }
        } ZEND_HASH_FOREACH_END();
    }

    return true;
}

// If there is one rule matching in *ANY* span, then all further rules are ignored.
// Thus we check only rules until a specific index then.
static ddtrace_rule_result dd_match_rules(ddtrace_span_data *span, bool eval_root, int skip_at) {
    int index = -3;

    if (++index >= skip_at) {
        return (ddtrace_rule_result){ .sampling_rate = 0, .rule = INT32_MAX, .mechanism = DD_MECHANISM_RULE };
    }

    zend_array *meta = ddtrace_property_array(&span->property_meta);
    if (zend_hash_str_exists(meta, ZEND_STRL("manual.keep"))) {
        // manual.keep and manual.drop count as manual
        return (ddtrace_rule_result){ .sampling_rate = 1, .rule = -2, .mechanism = DD_MECHANISM_MANUAL };
    }

    if (++index >= skip_at) {
        return (ddtrace_rule_result){ .sampling_rate = 0, .rule = INT32_MAX, .mechanism = DD_MECHANISM_RULE };
    }
    if (zend_hash_str_exists(meta, ZEND_STRL("manual.drop"))) {
        return (ddtrace_rule_result){ .sampling_rate = 0, .rule = -1, .mechanism = DD_MECHANISM_MANUAL };
    }

    zval *rule;
    ZEND_HASH_FOREACH_VAL(get_DD_TRACE_SAMPLING_RULES(), rule) {
        if (++index >= skip_at) {
            break;
        }

        if (Z_TYPE_P(rule) != IS_ARRAY) {
            continue;
        }

        if (!eval_root) {
            zval *applies = zend_hash_str_find(Z_ARR_P(rule), ZEND_STRL("target_span"));
            if (!applies || Z_TYPE_P(applies) != IS_STRING || !zend_string_equals_literal(Z_STR_P(applies), "any")) {
                continue;
            }
        }

        if (dd_check_sampling_rule(Z_ARR_P(rule), span)) {
            zval *sample_rate_zv = zend_hash_str_find(Z_ARR_P(rule), ZEND_STRL("sample_rate"));
            zval *provenance_zv = zend_hash_str_find(Z_ARR_P(rule), ZEND_STRL("_provenance"));
            enum dd_sampling_mechanism mechanism = DD_MECHANISM_RULE;
            if (provenance_zv && Z_TYPE_P(provenance_zv) == IS_STRING) {
                if (zend_string_equals_literal(Z_STR_P(provenance_zv), "customer")) {
                    mechanism = DD_MECHANISM_REMOTE_USER_RULE;
                } else if (zend_string_equals_literal(Z_STR_P(provenance_zv), "dynamic")) {
                    mechanism = DD_MECHANISM_REMOTE_DYNAMIC_RULE;
                }
            }
            return (ddtrace_rule_result){ .sampling_rate = sample_rate_zv ? zval_get_double(sample_rate_zv) : 1, .rule = index, .mechanism = mechanism };
        }
    } ZEND_HASH_FOREACH_END();

    return (ddtrace_rule_result){ .sampling_rate = 0, .rule = INT32_MAX, .mechanism = DD_MECHANISM_RULE };
}

void ddtrace_decide_on_closed_span_sampling(ddtrace_span_data *span) {
    ddtrace_root_span_data *root = span->root;

    if (zval_get_long(&root->property_propagated_sampling_priority) > 0) {
        return;
    }

    ddtrace_rule_result result = dd_match_rules(span, &root->span == span && !root->parent_id, root->sampling_rule.rule);
    if (result.rule != INT32_MAX) {
        LOGEV(DEBUG, {
            smart_str buf = {0};
            const char *rule_str = "<unknown>";
            if (result.rule == -2) {
                rule_str = "manual.keep";
            } else if (result.rule == -1) {
                rule_str = "manual.drop";
            } else {
                zval *rule = ZEND_HASH_ELEMENT(get_DD_TRACE_SAMPLING_RULES(), result.rule);
                zai_json_encode(&buf, rule, 0);
                smart_str_0(&buf);
                rule_str = ZSTR_VAL(buf.s);
            }
            log("Evaluated sampling rules for span %" PRIu64 " on trace %s. Matched rule %s.", span->span_id, Z_STRVAL(span->root->property_trace_id), rule_str);
            smart_str_free(&buf);
        });
        root->sampling_rule = result;
    }
}

static ddtrace_rule_result dd_decide_on_open_span_sampling(ddtrace_root_span_data *root) {
    ddtrace_span_properties *span_props = root->stack->active;

    if (!span_props) {
        return root->sampling_rule;
    }

    ddtrace_rule_result result = root->sampling_rule;
    do {
        ddtrace_span_data *span = SPANDATA(span_props);

        ddtrace_rule_result new_result = dd_match_rules(span, &root->span == span && !root->parent_id, result.rule);
        if (new_result.rule != INT32_MAX) {
            result = new_result;
        }
    } while ((span_props = span_props->parent));

    return result;
}

// When the priority is inherited from distributed tracing, and then only when drop, *only* target_span: any rule sampling (with limiter) is applied (no agent sampling)
static void dd_decide_on_sampling(ddtrace_root_span_data *span) {
    int priority;
    bool is_trace_root = !span->parent_id;
    enum dd_sampling_mechanism mechanism;

    ddtrace_rule_result result = dd_decide_on_open_span_sampling(span);
    double sample_rate = 0;
    bool explicit_rule = true;

    if (is_trace_root) {
        double default_sample_rate = get_DD_TRACE_SAMPLE_RATE();
        sample_rate = default_sample_rate >= 0 ? default_sample_rate : 1;

        if (result.rule != INT32_MAX) {
            sample_rate = result.sampling_rate;
        } else if (default_sample_rate >= 0) {
            result.mechanism = DD_MECHANISM_RULE;
        } else {
            explicit_rule = false;

            ddtrace_try_read_agent_rate();

            if (DDTRACE_G(agent_rate_by_service)) {
                zval *env = zend_hash_str_find(ddtrace_property_array(&span->property_meta), ZEND_STRL("env"));
                if (!env) {
                    env = &span->property_env;
                }
                zval *sample_rate_zv = NULL;
                zval *service = &span->property_service;
                if (Z_TYPE_P(service) == IS_STRING && env && Z_TYPE_P(env) == IS_STRING) {
                    zend_string *sample_key = zend_strpprintf(0, "service:%.*s,env:%.*s", (int) Z_STRLEN_P(service), Z_STRVAL_P(service),
                                                              (int) Z_STRLEN_P(env), Z_STRVAL_P(env));
                    sample_rate_zv = zend_hash_find(DDTRACE_G(agent_rate_by_service), sample_key);
                    zend_string_release(sample_key);
                }
                if (!sample_rate_zv) {
                    // Default rate if no service+env pair matches
                    sample_rate_zv = zend_hash_str_find(DDTRACE_G(agent_rate_by_service), ZEND_STRL("service:,env:"));
                    if (sample_rate_zv) {
                        LOG(DEBUG, "Evaluated agent sampling rules for root span for trace %s and applied a default sample_rate of %f",
                            Z_STRVAL(span->property_trace_id), zval_get_double(sample_rate_zv));
                    }
                } else {
                    LOG(DEBUG, "Evaluated agent sampling rules for root span for trace %s (service: %s, env: %s) and found a sample_rate of %f",
                        Z_STRVAL(span->property_trace_id), Z_STR_P(service), Z_STR_P(env), zval_get_double(sample_rate_zv));
                }
                if (sample_rate_zv) {
                    sample_rate = zval_get_double(sample_rate_zv);
                }
            }
        }
    } else if (result.rule == INT32_MAX) {
        // If we are in propagated mode, we only consider rules applying to all spans, hence default handling is not active
        // But let's restore the sampling priority to reject in case it was reset to avoid invalid values
        if (zval_get_long(&span->property_sampling_priority) == DDTRACE_PRIORITY_SAMPLING_UNKNOWN) {
            zval priority_zv;
            ZVAL_LONG(&priority_zv, PRIORITY_SAMPLING_AUTO_REJECT);
            ddtrace_assign_variable(&span->property_sampling_priority, &priority_zv);
        }
        return;
    } else {
        sample_rate = result.sampling_rate;
    }

    // this must be stable on re-evaluation
    bool sampling = (double)span->trace_id.low < sample_rate * (double)~0ULL;
    bool limited = false;
    if (result.mechanism != DD_MECHANISM_MANUAL && ddtrace_limiter_active() && sampling) {
        if (span->trace_is_limited == DD_TRACE_LIMIT_UNCHECKED) {
            span->trace_is_limited = ddtrace_limiter_allow() ? DD_TRACE_UNLIMITED : DD_TRACE_LIMITED;
        }
        limited = span->trace_is_limited == DD_TRACE_LIMITED;
    }

    zval sample_rate_zv;
    ZVAL_DOUBLE(&sample_rate_zv, sample_rate);

    zend_array *metrics = ddtrace_property_array(&span->property_metrics);
    if (explicit_rule) {
        mechanism = result.mechanism;
        priority = sampling && !limited ? PRIORITY_SAMPLING_USER_KEEP : PRIORITY_SAMPLING_USER_REJECT;

        if (mechanism == DD_MECHANISM_MANUAL) {
            zend_hash_str_del(metrics, ZEND_STRL("_dd.rule_psr"));
        } else {
            zend_hash_str_update(metrics, ZEND_STRL("_dd.rule_psr"), &sample_rate_zv);
        }

        zend_hash_str_del(metrics, ZEND_STRL("_dd.agent_psr"));
    } else {
        // manual if it's not just inherited, otherwise this value is irrelevant (as sampling priority will be default)
        mechanism = DDTRACE_G(agent_rate_by_service) ? DD_MECHANISM_AGENT_RATE : DD_MECHANISM_DEFAULT;
        priority = sampling && !limited ? PRIORITY_SAMPLING_AUTO_KEEP : PRIORITY_SAMPLING_AUTO_REJECT;

        zend_hash_str_update(metrics, ZEND_STRL("_dd.agent_psr"), &sample_rate_zv);
    }

    if (limited) {
        zval limit_zv;
        ZVAL_DOUBLE(&limit_zv, ddtrace_limiter_rate());
        zend_hash_str_update(ddtrace_property_array(&span->property_metrics), ZEND_STRL("_dd.limit_psr"),
                             &limit_zv);
    }

    zval priority_zv;
    ZVAL_LONG(&priority_zv, priority);
    ddtrace_assign_variable(&span->property_sampling_priority, &priority_zv);

    dd_update_decision_maker_tag(span, mechanism);
}

zend_long ddtrace_fetch_priority_sampling_from_root(void) {
    if (!DDTRACE_G(active_stack)->root_span) {
        return DDTRACE_G(default_priority_sampling);
    }

    return ddtrace_fetch_priority_sampling_from_span(DDTRACE_G(active_stack)->root_span);
}

zend_long ddtrace_fetch_priority_sampling_from_span(ddtrace_root_span_data *root_span) {
    if (Z_TYPE(root_span->property_sampling_priority) == IS_UNDEF || Z_LVAL(root_span->property_sampling_priority) == DDTRACE_PRIORITY_SAMPLING_UNKNOWN) {
        root_span->explicit_sampling_priority = false;
    }

    bool decide = !root_span->explicit_sampling_priority;

    if (decide) {
        // If a decision of keep was inherited the sampling decision stays unchanged, regardless of the rules
        int sampling_priority = zval_get_long(&root_span->property_sampling_priority);
        if (zval_get_long(&root_span->property_propagated_sampling_priority) > 0 &&
            (sampling_priority == PRIORITY_SAMPLING_USER_KEEP || sampling_priority == PRIORITY_SAMPLING_AUTO_KEEP)) {
            decide = false;
        }
    }

    if (decide) {
        dd_decide_on_sampling(root_span);
    }

    return zval_get_long(&root_span->property_sampling_priority);
}

void ddtrace_set_priority_sampling_on_root(zend_long priority, enum dd_sampling_mechanism mechanism) {
    ddtrace_root_span_data *root_span = DDTRACE_G(active_stack)->root_span;

    if (!root_span) {
        return;
    }

    ddtrace_set_priority_sampling_on_span(root_span, priority, mechanism);
}

void ddtrace_set_priority_sampling_on_span(ddtrace_root_span_data *root_span, zend_long priority, enum dd_sampling_mechanism mechanism) {
    zval zv;
    ZVAL_LONG(&zv, priority);
    ddtrace_assign_variable(&root_span->property_sampling_priority, &zv);

    if (priority != DDTRACE_PRIORITY_SAMPLING_UNKNOWN) {
        dd_update_decision_maker_tag(root_span, mechanism);
        // Default is never explicit - e.g. distributed tracing.
        root_span->explicit_sampling_priority = mechanism != DD_MECHANISM_DEFAULT;
    }
}

DDTRACE_PUBLIC void ddtrace_set_priority_sampling_on_span_zobj(zend_object *root_span, zend_long priority, enum dd_sampling_mechanism mechanism) {
    assert(root_span->ce == ddtrace_ce_root_span_data);

    ddtrace_set_priority_sampling_on_span(ROOTSPANDATA(root_span), priority, mechanism);
}
