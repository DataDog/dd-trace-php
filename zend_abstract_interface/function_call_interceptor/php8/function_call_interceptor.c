#include "../function_call_interceptor.h"

#include <ctype.h>

#define QUALIFIED_SYMBOL_BUFSIZ 256

static HashTable fci_qualified_symbol_table;

static zai_fci_target *zai_fci_target_ctor(void) {
    return calloc(1, sizeof(zai_fci_target));
}

static void zai_fci_target_dtor(zval *zv) {
    zai_fci_target *target = Z_PTR_P(zv);
    if (target) free(target);
}

void zai_fci_minit(void) {
    zend_hash_init(&fci_qualified_symbol_table, 8, NULL, zai_fci_target_dtor, /* persistent */ 1);
}

void zai_fci_mshutdown(void) {
    zend_hash_destroy(&fci_qualified_symbol_table);
}

static size_t fci_make_qualified_symbol(const char *src, char *dest) {
    char *ptr = (char *)src;
    size_t len = 0;
    size_t colon_count = 0;

    if (*ptr == '\\') ptr++;

    for (; *ptr && len < QUALIFIED_SYMBOL_BUFSIZ; ptr++) {
        if (isalnum(*ptr) || *ptr == '\\' || *ptr == '_') {
            dest[len++] = tolower(*ptr);
        } else if (*ptr == ':' && colon_count < 2) {
            if (colon_count == 0 && *(ptr + 1) != ':') return 0;
            dest[len++] = ':';
            colon_count++;
        } else {
            return 0;
        }
    }

    if (!len || len >= QUALIFIED_SYMBOL_BUFSIZ) return 0;
    dest[len] = '\0';
    
    return len;
}

static zai_fci_target *fci_find_or_add_target(const char *qualified_name) {
    char buf[QUALIFIED_SYMBOL_BUFSIZ];
    size_t len = fci_make_qualified_symbol(qualified_name, buf);
    if (!len) return NULL;

    zend_string *qualified_symbol = zend_string_init(buf, len, /* persistent */ true);
    zai_fci_target *target = (zai_fci_target *)zend_hash_find(&fci_qualified_symbol_table, qualified_symbol);
    if (!target) {
        target = zai_fci_target_ctor();
        zend_hash_add_new_ptr(&fci_qualified_symbol_table, qualified_symbol, (void *)target);
    }
    return target;
}

bool zai_fci_startup_prehook(const char *qualified_name, zai_fci_prehook prehook) {
    zai_fci_target *target = fci_find_or_add_target(qualified_name);
    if (target) {
        target->prehook = prehook;
        return true;
    }
    return false;
}

bool zai_fci_startup_posthook(const char *qualified_name, zai_fci_posthook posthook) {
    zai_fci_target *target = fci_find_or_add_target(qualified_name);
    if (target) {
        target->posthook = posthook;
        return true;
    }
    return false;
}

void zai_fci_rinit(void) {}

void zai_fci_rshutdown(void) {}

bool zai_fci_runtime_hook_ex(zend_string class_name, zend_string func_name, void *runtime_hook) {
    return true;
}
