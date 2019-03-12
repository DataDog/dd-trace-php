#include "request_hooks.h"
#include "compat_zend_string.h"

#include <Zend/zend.h>
#include <Zend/zend_compile.h>
#include <php_main.h>
#if PHP_VERSION_ID >= 70000
#include <php/ext/pcre/php_pcre.h>
#else
#include <ext/pcre/php_pcre.h>
#endif
#if PHP_VERSION_ID < 70000
int dd_no_blacklisted_modules(char *blacklist_regexp) {
    zend_module_entry *module;
    pcre *pce;
    int re_options, rv = 1;
    pcre_extra *re_extra;

	HashPosition pos;

    if ((pce = pcre_get_compiled_regex(blacklist_regexp, &re_extra, &re_options)) != NULL) {
		zend_hash_internal_pointer_reset_ex(&module_registry, &pos);

        while (zend_hash_get_current_data_ex(&module_registry, (void *) &module, &pos) != FAILURE) {
			if (!pcre_exec(pce, re_extra, module->name, strlen(module->name), 0, re_options, NULL, 0)) {
                php_error(E_WARNING, "Found blacklisted module: %s, disabling conflicting functionality", module->name);
                rv = 0;
                break;
            }
			zend_hash_move_forward_ex(&module_registry, &pos);
		}
    }

    return rv;
}

#elif PHP_VERSION_ID < 70300
int dd_no_blacklisted_modules(char *blacklist_regexp) {
    zend_module_entry *module;
    pcre *pce;
    int re_options, rv = 1;
    pcre_extra *re_extra;
    zend_string *pattern;

    pattern = zend_string_init(blacklist_regexp, strlen(blacklist_regexp), 0);
    if ((pce = pcre_get_compiled_regex(pattern, &re_extra, &re_options)) != NULL) {
        ZEND_HASH_FOREACH_PTR(&module_registry, module) {
            if (!pcre_exec(pce, re_extra, module->name, strlen(module->name), 0, re_options, NULL, 0)) {
                php_error(E_WARNING, "Found blacklisted module: %s, disabling conflicting functionality", module->name);
                rv = 0;
                break;
            }
        }
        ZEND_HASH_FOREACH_END();
    }

    zend_string_release(pattern);
    return rv;
}
#else
int dd_no_blacklisted_modules(char *blacklist_regexp) {
    zend_module_entry *module;
    pcre2_code *pce;
    int rv = 1;
    uint32_t capture_count, re_options;
    zend_string *pattern;

    pattern = zend_string_init(blacklist_regexp, strlen(blacklist_regexp), 0);
    if ((pce = pcre_get_compiled_regex(pattern, &capture_count, &re_options)) != NULL) {
        pcre2_match_data *match_data = php_pcre_create_match_data(capture_count, pce);
        if (match_data) {
            ZEND_HASH_FOREACH_PTR(&module_registry, module) {
                if (pcre2_match(pce, (PCRE2_SPTR)module->name, strlen(module->name), 0, re_options, match_data, php_pcre_mctx()) > 0) {
                    php_error(E_WARNING, "Found blacklisted module: %s, disabling conflicting functionality", module->name);
                    rv = 0;
                    break;
                }
            }
            ZEND_HASH_FOREACH_END();
            php_pcre_free_match_data(match_data);
        }
    }

    zend_string_release(pattern);
    return rv;
}
#endif

#if PHP_VERSION_ID < 70000
int dd_execute_php_file(const char *filename TSRMLS_DC) {
    int filename_len = strlen(filename);
    if (filename_len == 0) {
        return FAILURE;
    }
    int dummy = 1;
    zend_file_handle file_handle;
    zend_op_array *new_op_array;
    zval *result = NULL;
    int ret;

    ret = php_stream_open_for_zend_ex(filename, &file_handle, USE_PATH | STREAM_OPEN_FOR_INCLUDE TSRMLS_CC);

    if (ret == SUCCESS) {
        if (!file_handle.opened_path) {
            file_handle.opened_path = estrndup(filename, filename_len);
        }
        if (zend_hash_add(&EG(included_files), file_handle.opened_path, strlen(file_handle.opened_path) + 1,
                          (void *)&dummy, sizeof(int), NULL) == SUCCESS) {
            new_op_array = zend_compile_file(&file_handle, ZEND_REQUIRE TSRMLS_CC);
            zend_destroy_file_handle(&file_handle TSRMLS_CC);
        } else {
            new_op_array = NULL;
            zend_file_handle_dtor(&file_handle TSRMLS_CC);
        }
        if (new_op_array) {
            EG(return_value_ptr_ptr) = &result;
            EG(active_op_array) = new_op_array;
            if (!EG(active_symbol_table)) {
                zend_rebuild_symbol_table(TSRMLS_C);
            }

            zend_execute(new_op_array TSRMLS_CC);

            destroy_op_array(new_op_array TSRMLS_CC);
            efree(new_op_array);
            if (!EG(exception)) {
                if (EG(return_value_ptr_ptr)) {
                    zval_ptr_dtor(EG(return_value_ptr_ptr));
                }
            }

            return 1;
        }
    }
    return 0;
}
#else

int dd_execute_php_file(const char *filename TSRMLS_DC) {
    int filename_len = strlen(filename);
    if (filename_len == 0) {
        return FAILURE;
    }
    zval dummy;
    zend_file_handle file_handle;
    zend_op_array *new_op_array;
    zval result;
    int ret;

    ret = php_stream_open_for_zend_ex(filename, &file_handle, USE_PATH | STREAM_OPEN_FOR_INCLUDE);

    if (ret == SUCCESS) {
        zend_string *opened_path;
        if (!file_handle.opened_path) {
            file_handle.opened_path = zend_string_init(filename, filename_len, 0);
        }
        opened_path = zend_string_copy(file_handle.opened_path);
        ZVAL_NULL(&dummy);
        if (zend_hash_add(&EG(included_files), opened_path, &dummy)) {
            new_op_array = zend_compile_file(&file_handle, ZEND_REQUIRE);
            zend_destroy_file_handle(&file_handle);
        } else {
            new_op_array = NULL;
            zend_file_handle_dtor(&file_handle);
        }
        zend_string_release(opened_path);
        if (new_op_array) {
            ZVAL_UNDEF(&result);
            zend_execute(new_op_array, &result);

            destroy_op_array(new_op_array);
            efree(new_op_array);
            if (!EG(exception)) {
                zval_ptr_dtor(&result);
            }

            return 1;
        }
    }
    return 0;
}
#endif
