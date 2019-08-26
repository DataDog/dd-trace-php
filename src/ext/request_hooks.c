#include "request_hooks.h"

#include <Zend/zend.h>
#include <Zend/zend_compile.h>
#include <php_main.h>
#include <string.h>

#include "compat_zend_string.h"
#include "ddtrace.h"
#include "env_config.h"
#include "logging.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

#define DELIMETER ','
static int find_exact_match(const char *haystack, const char *needle) {
    int found = 0;
    const char *match, *haystack_ptr = haystack;
    size_t needle_len = strlen(needle);

    while ((match = strstr(haystack_ptr, needle)) != NULL) {
        haystack_ptr = match + needle_len;
        if (match > haystack && *(match - 1) != DELIMETER) {
            continue;
        }

        if (*haystack_ptr == '\0' || *haystack_ptr == DELIMETER) {
            found = 1;
            break;
        }
    }

    return found;
}

int dd_no_blacklisted_modules(TSRMLS_D) {
    int no_blacklisted_modules = 1;

    char *blacklist = DDTRACE_G(internal_blacklisted_modules_list);
    zend_module_entry *module;

    if (blacklist == NULL) {
        return no_blacklisted_modules;
    }

#if PHP_VERSION_ID < 70000
    HashPosition pos;
    zend_hash_internal_pointer_reset_ex(&module_registry, &pos);

    while (zend_hash_get_current_data_ex(&module_registry, (void *)&module, &pos) != FAILURE) {
        if (module && module->name && find_exact_match(blacklist, module->name)) {
            ddtrace_log_errf("Found blacklisted module: %s, disabling conflicting functionality", module->name);
            no_blacklisted_modules = 0;
            break;
        }
        zend_hash_move_forward_ex(&module_registry, &pos);
    }
#else
    ZEND_HASH_FOREACH_PTR(&module_registry, module) {
        if (module && module->name && find_exact_match(blacklist, module->name)) {
            ddtrace_log_errf("Found blacklisted module: %s, disabling conflicting functionality", module->name);
            no_blacklisted_modules = 0;
            break;
        }
    }
    ZEND_HASH_FOREACH_END();
#endif
    return no_blacklisted_modules;
}

static inline BOOL_T _starts_with(const char *with, const char *str, size_t str_len) {
    size_t with_len = strlen(with);
    if (str_len < with_len) {
        return FALSE;
    } else {
        return memcmp(with, str, with_len) == 0;
    }
}

static inline void _resolve_path(const char *path, size_t path_len, const char *token, char *resolved_path) {
    size_t length = path_len + strlen(token) + 1;
    char *tmp_path = calloc(length, 1);
    int last_slash = strrchr(path, '/') - path;

    snprintf(tmp_path, length, "%.*s%s", last_slash, path, token);
    realpath(tmp_path, resolved_path);
    free(tmp_path);
}

char *ddtrace_fetch_computed_request_init_hook_path() {
    Dl_info dl_info;
    if (dladdr(ddtrace_fetch_computed_request_init_hook_path, &dl_info) == -1) {
        return NULL;
    }
    char *path = (char *)dl_info.dli_fname;

    if (access(path, F_OK) == -1) {
        return NULL;
    }
    size_t path_len = strlen(path);
    char *resolved_path = calloc(PATH_MAX + 1, 1);

    if (_starts_with("/opt/datadog-php/extensions/", path, path_len)) {
        strcpy(resolved_path, "/opt/datadog-php/dd-trace-sources/bridge/dd_wrap_autoloader.php");
    } else if (strstr(path, "tmp/build_extension/modules") != NULL) {
        _resolve_path(path, path_len, "/../../../bridge/dd_wrap_autoloader.php", resolved_path);
    } else {
        _resolve_path(path, path_len, "/../dd-trace-sources/bridge/dd_wrap_autoloader.php", resolved_path);
    }

    if (access(resolved_path, F_OK) != -1) {
        return resolved_path;
    } else {
        free(resolved_path);
        return NULL;
    }
}

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

    BOOL_T rv = FALSE;
    char *original_open_basedir = PG(open_basedir);
    PG(open_basedir) = NULL;

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
            rv = TRUE;
        }
    }

    PG(open_basedir) = original_open_basedir;
    return rv;
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
    int ret, rv = FALSE;
    char *original_open_basedir = PG(open_basedir);
    PG(open_basedir) = NULL;
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
            rv = TRUE;
        }
    }

    PG(open_basedir) = original_open_basedir;
    return rv;
}
#endif
