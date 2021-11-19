#include "predictive_changelog.h"

#if PHP_VERSION_ID >= 70200
#include <Zend/zend_interfaces.h>
#else
#include <ext/spl/spl_iterators.h>
#endif

#include "ddtrace.h"
#include "ddtrace_string.h"
#include "handlers_internal.h"
#include "logging.h"
#include "span.h"

static void dd_append_span_message(zend_string *message, zend_execute_data *ex, ddtrace_span_t *span) {
    zval *meta = ddtrace_spandata_property_meta(span);
    if (Z_TYPE_P(meta) != IS_ARRAY) {
        array_init(meta);
    }

    size_t index = 0;
    zend_string *key_message = NULL;
    do {
        if (key_message) {
            zend_string_release(key_message);
            index++;
        }
        key_message = zend_strpprintf(0, "changelog.%d.message", index);
    } while (zend_hash_str_find(Z_ARR_P(meta), ZSTR_VAL(key_message), ZSTR_LEN(key_message)));

    add_assoc_str_ex(meta, ZSTR_VAL(key_message), ZSTR_LEN(key_message), zend_string_copy(message));
    zend_string_release(key_message);

    zend_execute_data *prev = ex->prev_execute_data;
    if (prev) {
        if (prev->func) {
            zend_string *key_file = zend_strpprintf(0, "changelog.%d.file", index);
            add_assoc_str_ex(meta, ZSTR_VAL(key_file), ZSTR_LEN(key_file), zend_string_copy(prev->func->op_array.filename));
            zend_string_release(key_file);
        }

        zend_string *key_calling_scope = zend_strpprintf(0, "changelog.%d.calling_scope", index);
        zend_string *fqn;
        if (prev->func->common.function_name && prev->func->common.scope) {
            fqn = zend_strpprintf(0, "%s::%s()", ZSTR_VAL(prev->func->common.scope->name), ZSTR_VAL(prev->func->common.function_name));
        } else if (prev->func->common.function_name) {
            fqn = zend_strpprintf(0, "%s()", ZSTR_VAL(prev->func->common.function_name));
        } else {
            fqn = zend_string_init(ZEND_STRL("{main}"), 0);
        }
        add_assoc_str_ex(meta, ZSTR_VAL(key_calling_scope), ZSTR_LEN(key_calling_scope), fqn);
        zend_string_release(key_calling_scope);

        if (prev->opline->lineno) {
            zend_string *key_line_no = zend_strpprintf(0, "changelog.%d.line_no", index);
            add_assoc_long_ex(meta, ZSTR_VAL(key_line_no), ZSTR_LEN(key_line_no), (zend_long) prev->opline->lineno);
            zend_string_release(key_line_no);
        }
    }
}

static void dd_append_span_str_message(char *message, int message_len, zend_execute_data *ex, ddtrace_span_t *span) {
    zend_string *msg = zend_string_init(message, message_len, 0);
    dd_append_span_message(msg, ex, span);
    zend_string_release(msg);
}

#define BC_HANDLER_PARAMETERS zend_execute_data *ex, ddtrace_span_t *span

// TODO Macroize

// PHP 8.1
static void dd_php81_readonly(BC_HANDLER_PARAMETERS) {
    dd_append_span_str_message(ZEND_STRL("PHP 8.1: 'readonly' is now a reserved keyword so it cannot be used as a name for constants, classes, functions or methods."), ex, span);
}

// PHP 8.0
static void dd_php80___autoload(BC_HANDLER_PARAMETERS) {
    dd_append_span_str_message(ZEND_STRL("PHP 8.0: Support for __autoload() was removed. Use spl_autoload_register() instead."), ex, span);
}

static void dd_php80_create_function(BC_HANDLER_PARAMETERS) {
    dd_append_span_str_message(ZEND_STRL("PHP 8.0: The function create_function() was removed. Use anonymous functions (closures) instead."), ex, span);
}

static void dd_php80_each(BC_HANDLER_PARAMETERS) {
    dd_append_span_str_message(ZEND_STRL("PHP 8.0: Support for each() was removed. Use foreach or ArrayIterator instead."), ex, span);
}

static char *dd_get_arg_name(zend_execute_data *ex, size_t arg_num) {
    if (ex && ex->func && ex->func->op_array.vars[arg_num - 1]) {
        return ZSTR_VAL(ex->func->op_array.vars[arg_num - 1]);
    }
    return "<var_name>";
}

static void dd_php80_is_resource(BC_HANDLER_PARAMETERS) {
    zval *arg = ZEND_CALL_ARG(ex, 1);
    if (Z_TYPE_P(arg) == IS_RESOURCE) {
        const char *name = zend_rsrc_list_get_rsrc_type(Z_RES_P(arg));
        if (name && strcmp("curl", name) == 0) {
            char *cv_name = dd_get_arg_name(ex->prev_execute_data, 1);
            zend_string *msg = zend_strpprintf(0, "PHP 8.0: Curl handles are now instances of CurlHandle so is_resource() will return false for curl handles. For PHP 7 and 8 compatibility change the expression to:\n\n(is_resource($%s) || $%s instanceof \\CurlHandle)", cv_name, cv_name);
            dd_append_span_message(msg, ex, span);
            zend_string_release(msg);
        }
        // TODO Add other exts that changed
    }
}

static void dd_php80_match(BC_HANDLER_PARAMETERS) {
    dd_append_span_str_message(ZEND_STRL("PHP 8.0: 'match' is now a reserved keyword so it cannot be used as a name for constants, classes, functions or methods."), ex, span);
}

// PHP 7.4
static void dd_php74_fn(BC_HANDLER_PARAMETERS) {
    dd_append_span_str_message(ZEND_STRL("PHP 7.4: 'fn' is now a reserved keyword so it cannot be used as a name for constants, classes, functions or methods."), ex, span);
}

// PHP 7.2
static void dd_php72_count(BC_HANDLER_PARAMETERS) {
    zval *arg = ZEND_CALL_ARG(ex, 1);
    if (Z_TYPE_P(arg) == IS_ARRAY) return;
    if (Z_TYPE_P(arg) == IS_OBJECT) {
#if PHP_VERSION_ID >= 70200
        if (Z_OBJ_HT_P(arg)->count_elements || instanceof_function(Z_OBJCE_P(arg), zend_ce_countable)) return;
#else
        if (Z_OBJ_HT_P(arg)->count_elements || instanceof_function(Z_OBJCE_P(arg), spl_ce_Countable)) return;
#endif
    }
    // Cover the case of sizeof() as well
    zend_string *msg = zend_strpprintf(0, "PHP 7.2: non-countable argument passed to %s(). This now raises an E_WARNING.", ZSTR_VAL(ex->func->common.function_name));
    dd_append_span_message(msg, ex, span);
    zend_string_release(msg);
}

ddpcl_function_breaking_change bcs[] = {
    // PHP 8.1
    {
        .version = DDPCL_PHP81,
        .function_name = "readonly",
        .function_name_len = sizeof("readonly") - 1,
        .handler = dd_php81_readonly,
    },
    // PHP 8.0
    {
        .version = DDPCL_PHP80,
        .function_name = "__autoload",
        .function_name_len = sizeof("__autoload") - 1,
        .handler = dd_php80___autoload,
    },
    {
        .version = DDPCL_PHP80,
        .function_name = "create_function",
        .function_name_len = sizeof("create_function") - 1,
        .handler = dd_php80_create_function,
    },
    {
        .version = DDPCL_PHP80,
        .function_name = "each",
        .function_name_len = sizeof("each") - 1,
        .handler = dd_php80_each,
    },
    {
        .version = DDPCL_PHP80,
        .function_name = "is_resource",
        .function_name_len = sizeof("is_resource") - 1,
        .handler = dd_php80_is_resource,
    },
    {
        .version = DDPCL_PHP80,
        .function_name = "match",
        .function_name_len = sizeof("match") - 1,
        .handler = dd_php80_match,
    },
    // PHP 7.4
    {
        .version = DDPCL_PHP74,
        .function_name = "fn",
        .function_name_len = sizeof("fn") - 1,
        .handler = dd_php74_fn,
    },
    // PHP 7.3
    // PHP 7.2
    {
        .version = DDPCL_PHP72,
        .function_name = "count",
        .function_name_len = sizeof("count") - 1,
        .handler = dd_php72_count,
    },
    {
        .version = DDPCL_PHP72,
        .function_name = "sizeof",
        .function_name_len = sizeof("sizeof") - 1,
        .handler = dd_php72_count,
    },
};
size_t bcs_len = sizeof bcs / sizeof bcs[0];

void ddtrace_predictive_changelog_replace_internal_functions(void) {
    // Replace all possible handlers so that INI can be set at runtime
    for (size_t i = 0; i < bcs_len; ++i) {
        ddtrace_string name = { .ptr = (char *) bcs[i].function_name, .len = bcs[i].function_name_len };
        ddtrace_replace_internal_function(CG(function_table), name);
    }
}

void ddtrace_predictive_changelog_rinit(void) {
    zend_string *version = get_DD_TRACE_PCL_PHP_UPGRADE_VERSION();
    ddpcl_php_version min_php_version = DDPCL_PHP54;
    ddpcl_php_version max_php_version = DDPCL_PHP81;

    switch (PHP_API_VERSION) {
        case 20210902:
            min_php_version = DDPCL_PHP81;
            break;
        case 20200930:
            min_php_version = DDPCL_PHP80;
            break;
        case 20190902:
            min_php_version = DDPCL_PHP74;
            break;
        case 20180731:
            min_php_version = DDPCL_PHP73;
            break;
        case 20170718:
            min_php_version = DDPCL_PHP72;
            break;
        case 20160303:
            min_php_version = DDPCL_PHP71;
            break;
        case 20151012:
            min_php_version = DDPCL_PHP70;
            break;
        case 20131106:
            min_php_version = DDPCL_PHP56;
            break;
        case 20121113:
            min_php_version = DDPCL_PHP55;
            break;
        case 20100412:
            min_php_version = DDPCL_PHP54;
            break;
    }

    if (strcmp("8.1", ZSTR_VAL(version)) == 0) {
        max_php_version = DDPCL_PHP81;
    } else if (strcmp("8.0", ZSTR_VAL(version)) == 0) {
        max_php_version = DDPCL_PHP80;
    } else if (strcmp("7.4", ZSTR_VAL(version)) == 0) {
        max_php_version = DDPCL_PHP74;
    } else if (strcmp("7.3", ZSTR_VAL(version)) == 0) {
        max_php_version = DDPCL_PHP73;
    } else if (strcmp("7.2", ZSTR_VAL(version)) == 0) {
        max_php_version = DDPCL_PHP72;
    } else if (strcmp("7.1", ZSTR_VAL(version)) == 0) {
        max_php_version = DDPCL_PHP71;
    } else if (strcmp("7.0", ZSTR_VAL(version)) == 0) {
        max_php_version = DDPCL_PHP70;
    } else if (strcmp("5.6", ZSTR_VAL(version)) == 0) {
        max_php_version = DDPCL_PHP56;
    } else if (strcmp("5.5", ZSTR_VAL(version)) == 0) {
        max_php_version = DDPCL_PHP55;
    } else if (strcmp("5.4", ZSTR_VAL(version)) == 0) {
        max_php_version = DDPCL_PHP54;
    }

    for (size_t i = 0; i < bcs_len; ++i) {
        if (bcs[i].version <= min_php_version || bcs[i].version > max_php_version) continue;

        zval function, not_so_callable_lolz;
        ZVAL_STRINGL(&function, bcs[i].function_name, bcs[i].function_name_len);
        ZVAL_PTR(&not_so_callable_lolz, (void *) &bcs[i]);
        if (!ddtrace_trace(NULL, &function, &not_so_callable_lolz, DDTRACE_DISPATCH_POSTHOOK)) {
            ddtrace_log_debugf("Unable to install predictive changelog handler for %s()", bcs[i].function_name);
        }
        zval_dtor(&function);
    }
}
