#include "../interceptor.h"

#include <ctype.h>

#define QUALIFIED_SYMBOL_BUFSIZ 256

static zai_interceptor_begin fci_static_begin;
static zai_interceptor_end fci_static_end;
static zai_interceptor_begin fci_dynamic_begin;
static zai_interceptor_end fci_dynamic_end;

static zai_interceptor_dynamic_ptr_ctor fci_dynamic_ptr_ctor;
static zai_interceptor_dynamic_ptr_dtor fci_dynamic_ptr_dtor;

static HashTable fci_static_targets;

// TODO Explain context switch between static and dynamic modes
ZEND_TLS HashTable fci_dynamic_targets;
ZEND_TLS HashTable *fci_active_targets;

ZEND_TLS void (*fci_begin)(zai_interceptor_caller_owned *ptr, zend_execute_data *execute_data);
ZEND_TLS void (*fci_end)(zai_interceptor_caller_owned *ptr, zend_execute_data *execute_data, zval *retval);

void zai_interceptor_minit(
    zai_interceptor_begin static_begin, zai_interceptor_end static_end,
    zai_interceptor_begin dynamic_begin, zai_interceptor_end dynamic_end,
    zai_interceptor_dynamic_ptr_ctor ctor,
    zai_interceptor_dynamic_ptr_dtor dtor
) {
    zend_hash_init(&fci_static_targets, 8, NULL, NULL, /* persistent */ 1);
    fci_static_begin = static_begin;
    fci_static_end = static_end;
    fci_dynamic_begin = dynamic_begin;
    fci_dynamic_end = dynamic_end;
    fci_dynamic_ptr_ctor = ctor;
    fci_dynamic_ptr_dtor = dtor;
}

void zai_interceptor_mshutdown(void) {
    zend_hash_destroy(&fci_static_targets);
}

void zai_interceptor_rinit(void) {
    fci_active_targets = &fci_static_targets;
    fci_begin = fci_static_begin;
    fci_end = fci_static_end;
}

void zai_interceptor_rshutdown(void) {
    if (fci_active_targets == &fci_dynamic_targets) {
        zend_hash_destroy(&fci_dynamic_targets);
    }
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

void zai_interceptor_add_target_startup(const char *qualified_name, zai_interceptor_caller_owned *ptr) {
    char buf[QUALIFIED_SYMBOL_BUFSIZ];
    size_t len = fci_make_qualified_symbol(qualified_name, buf);
    if (len) {
        zend_string *qualified_symbol = zend_string_init(buf, len, /* persistent */ true);
        assert(zend_hash_find(&fci_static_targets, qualified_symbol) == NULL && "Startup target already in use");
        zend_hash_add_new_ptr(&fci_static_targets, qualified_symbol, ptr);
    } else {
        assert(false && "Failed to create qualified symbol");
    }
}

static void fci_dynamic_targets_dtor(zval *zv) {
    fci_dynamic_ptr_dtor(Z_PTR_P(zv));
}

void fci_dynamic_targets_init(void) {
    zend_hash_init(&fci_dynamic_targets, zend_hash_num_elements(&fci_static_targets) * 2, NULL, fci_dynamic_targets_dtor, /* persistent */ 0);
    zend_hash_copy(&fci_dynamic_targets, &fci_static_targets, NULL);

    fci_active_targets = &fci_dynamic_targets;
    fci_begin = fci_dynamic_begin;
    fci_end = fci_dynamic_end;
}

zai_interceptor_caller_owned *zai_interceptor_add_target_runtime(const char *class_name, const char *func_name) {
    if (fci_active_targets != &fci_dynamic_targets) {
        fci_dynamic_targets_init();
    }

    size_t len;
    char *qualified_name = (char *)func_name;
    char fqn_buf[QUALIFIED_SYMBOL_BUFSIZ];
    if (class_name) {
        len = snprintf(fqn_buf, QUALIFIED_SYMBOL_BUFSIZ, "%s::%s", class_name, func_name);
        if (len < 0 || len >= QUALIFIED_SYMBOL_BUFSIZ) {
            return NULL;
        }
        qualified_name = fqn_buf;
    }

    zai_interceptor_caller_owned *ptr = NULL;
    char buf[QUALIFIED_SYMBOL_BUFSIZ];
    len = fci_make_qualified_symbol((const char *) qualified_name, buf);
    if (len) {
        zend_string *qualified_symbol = zend_string_init(buf, len, /* persistent */ false);
        zval *orig_ptr = zend_hash_find(&fci_dynamic_targets, qualified_symbol);
        if (!orig_ptr) {
            ptr = fci_dynamic_ptr_ctor(NULL);
            zend_hash_add_new_ptr(&fci_dynamic_targets, qualified_symbol, ptr);
        } else {
            ptr = fci_dynamic_ptr_ctor(Z_PTR_P(orig_ptr));
            zend_hash_update_ptr(&fci_dynamic_targets, qualified_symbol, ptr);
        }
        zend_string_release(qualified_symbol);
    }
    return ptr;
}
