#include "json.h"

#if PHP_VERSION_ID < 70100
#define zai_json_encode_signature(name) void name(smart_str *buf, zval *val, int options)
#define zai_json_decode_ex_signature(name) void name(zval *return_value, char *str, int str_len, int options, long depth)
#elif PHP_VERSION_ID < 80000
#define zai_json_encode_signature(name) int name(smart_str *buf, zval *val, int options)
#define zai_json_decode_ex_signature(name) int name(zval *return_value, char *str, size_t str_len, zend_long options, zend_long depth)
#else
#define zai_json_encode_signature(name) int name(smart_str *buf, zval *val, int options)
#define zai_json_decode_ex_signature(name) int name(zval *return_value, const char *str, size_t str_len, zend_long options, zend_long depth)
#endif

zai_json_encode_signature((*zai_json_encode));
zai_json_decode_ex_signature((*zai_json_decode_ex));
#ifndef _WIN32
__attribute__((weak)) zai_json_encode_signature(php_json_encode);
__attribute__((weak)) zai_json_decode_ex_signature(php_json_decode_ex);
#endif
#ifdef _WIN32
extern zend_class_entry *_php_json_serializable_ce = NULL;
#pragma comment(linker, "/alternatename:php_json_serializable_ce=_php_json_serializable_ce")

extern zai_json_encode_signature(php_json_encode);
#pragma comment(linker, "/alternatename:php_json_encode=_php_json_encode")
zai_json_encode_signature((*_php_json_encode)) = NULL;

extern zai_json_decode_ex_signature(php_json_decode_ex);
#pragma comment(linker, "/alternatename:php_json_decode_ex=_php_json_decode_ex")
zai_json_decode_ex_signature((*_php_json_decode_ex)) = NULL;
#elif !defined(__APPLE__)
__attribute__((weak)) zend_class_entry *php_json_serializable_ce;
#endif

bool zai_json_setup_bindings(void) {
    if (php_json_encode && php_json_decode_ex && php_json_serializable_ce) {
        zai_json_encode = php_json_encode;
        zai_json_decode_ex = php_json_decode_ex;
        return true;
    }

    zend_module_entry *json_me = zend_hash_str_find_ptr(&module_registry, ZEND_STRL("json"));

    void *handle = NULL;
    if (json_me && json_me->handle) {
        handle = json_me->handle;
#ifdef _WIN32
    } else {
        // some well known function
        // We need the php.dll, not the php.exe,
        GetModuleHandleEx(GET_MODULE_HANDLE_EX_FLAG_FROM_ADDRESS | GET_MODULE_HANDLE_EX_FLAG_UNCHANGED_REFCOUNT, (LPCTSTR)php_write, (HMODULE *)&handle);
#endif
    }

    zai_json_encode = (zai_json_encode_signature((*))) DL_FETCH_SYMBOL(handle, "php_json_encode");
    if (zai_json_encode == NULL) {
        zai_json_encode = (zai_json_encode_signature((*))) DL_FETCH_SYMBOL(handle, "_php_json_encode");
    }

    zai_json_decode_ex = (zai_json_decode_ex_signature((*))) DL_FETCH_SYMBOL(handle, "php_json_decode_ex");
    if (zai_json_decode_ex == NULL) {
        zai_json_decode_ex = (zai_json_decode_ex_signature((*))) DL_FETCH_SYMBOL(handle, "_php_json_decode_ex");
    }

    zend_class_entry **tmp_json_serializable_ce = (zend_class_entry **) DL_FETCH_SYMBOL(handle, "php_json_serializable_ce");
    if (tmp_json_serializable_ce == NULL) {
        tmp_json_serializable_ce = (zend_class_entry **) DL_FETCH_SYMBOL(handle, "_php_json_serializable_ce");
    }
    if (tmp_json_serializable_ce != NULL) {
        php_json_serializable_ce = *tmp_json_serializable_ce;
    }

    return zai_json_encode && zai_json_decode_ex;
}
