#include <Zend/zend_extensions.h>
#include <include/extension.h>
#include <include/sapi.h>

// clang-format off
#define TEA_EXTENSION_NAME_MAX_LEN 128

static char __tea_extension_name[TEA_EXTENSION_NAME_MAX_LEN + 1] = {'T', 'E', 'A', '\0'};

typedef struct {
    zend_module_entry           *modules;
    size_t                       size;
} tea_extension_dummy_list_t;

typedef struct {
    tea_extension_init_function *handlers;
    size_t                       size;
} tea_extension_init_list_t;

typedef struct {
    tea_extension_shutdown_function *handlers;
    size_t                           size;
} tea_extension_shutdown_list_t;

typedef struct {
    zend_function_entry             *entries;
    size_t                           size;
} tea_extension_functions_list_t;

typedef struct {
    tea_extension_op_array_function *handlers;
    size_t                           size;
} tea_extension_op_array_list_t;

typedef struct {
    tea_extension_startup_function *handlers;
    size_t                          size;
} tea_extension_startup_list_t;

#define TEA_EXTENSION_LIST_EMPTY {NULL, 0}

tea_extension_dummy_list_t      tea_extension_dummy_list            = TEA_EXTENSION_LIST_EMPTY;
tea_extension_init_list_t       tea_extension_minit_list            = TEA_EXTENSION_LIST_EMPTY;
tea_extension_init_list_t       tea_extension_rinit_list            = TEA_EXTENSION_LIST_EMPTY;
tea_extension_shutdown_list_t   tea_extension_rshutdown_list        = TEA_EXTENSION_LIST_EMPTY;
tea_extension_shutdown_list_t   tea_extension_mshutdown_list        = TEA_EXTENSION_LIST_EMPTY;
tea_extension_functions_list_t  tea_extension_functions_list        = TEA_EXTENSION_LIST_EMPTY;
tea_extension_op_array_list_t   tea_extension_op_array_handler_list = TEA_EXTENSION_LIST_EMPTY;
tea_extension_op_array_list_t   tea_extension_op_array_ctor_list    = TEA_EXTENSION_LIST_EMPTY;
tea_extension_op_array_list_t   tea_extension_op_array_dtor_list    = TEA_EXTENSION_LIST_EMPTY;
tea_extension_startup_list_t    tea_extension_startup_list          = TEA_EXTENSION_LIST_EMPTY;

static void tea_extension_function(const zend_function_entry *entry);

static inline zend_result_t tea_extension_init_run(tea_extension_init_list_t *list, INIT_FUNC_ARGS) {
    if (list->size == 0) {
        return SUCCESS;
    }

    for (size_t handler = 0; handler < list->size; handler++)
        list->handlers[handler](INIT_FUNC_ARGS_PASSTHRU);

    return SUCCESS;
}

static inline zend_result_t tea_extension_shutdown_run(tea_extension_shutdown_list_t *list, SHUTDOWN_FUNC_ARGS) {
    if (list->size == 0) {
        return SUCCESS;
    }

    for (size_t handler = 0; handler < list->size; handler++)
        list->handlers[handler](SHUTDOWN_FUNC_ARGS_PASSTHRU);

    return SUCCESS;
}

static inline void tea_extension_op_array_run(tea_extension_op_array_list_t *list, zend_op_array *op_array) {
    if (list->size == 0) {
        return;
    }

    for (size_t handler = 0; handler < list->size; handler++) {
        list->handlers[handler](op_array);
    }
}

static inline int tea_extension_startup_run() {
    tea_extension_startup_list_t *list = &tea_extension_startup_list;

    if (list->size == 0) {
        return SUCCESS;
    }

    for (size_t handler = 0; handler < list->size; handler++) {
        list->handlers[handler]();
    }

    return SUCCESS;
}

static PHP_MINIT_FUNCTION(tea_extension);
static PHP_MSHUTDOWN_FUNCTION(tea_extension);
static PHP_RINIT_FUNCTION(tea_extension);
static PHP_RSHUTDOWN_FUNCTION(tea_extension);
static void tea_extension_op_array_handler_run(zend_op_array *op_array);
static void tea_extension_op_array_ctor_run(zend_op_array *op_array);
static void tea_extension_op_array_dtor_run(zend_op_array *op_array);

static zend_module_entry __tea_extension_module = {
    STANDARD_MODULE_HEADER,
    (const char*) __tea_extension_name,
    NULL,
    PHP_MINIT(tea_extension),      // MINIT
    PHP_MSHUTDOWN(tea_extension),  // MSHUTDOWN
    PHP_RINIT(tea_extension),      // RINIT
    PHP_RSHUTDOWN(tea_extension),  // RSHUTDOWN
    NULL,
    PHP_VERSION,
    STANDARD_MODULE_PROPERTIES
};

static zend_extension __tea_zend_extension = {
    __tea_extension_name,
    PHP_VERSION,
    "Datadog",
    "https://github.com/DataDog/dd-trace-php",
    "Copyright Datadog",
    tea_extension_startup_run,
    NULL,
    NULL,
    NULL,
    NULL,
    tea_extension_op_array_handler_run,
    NULL,
    NULL,
    NULL,
    tea_extension_op_array_ctor_run,
    tea_extension_op_array_dtor_run,
    STANDARD_ZEND_EXTENSION_PROPERTIES
};

static PHP_MINIT_FUNCTION(tea_extension) {
    if (tea_extension_functions_list.size) {
        /* PHP_FE_END */
        tea_extension_function(NULL);

        zend_register_functions(
            NULL,
            tea_extension_functions_list.entries,
            NULL,
            __tea_extension_module.type);
    }

    zend_register_extension(&__tea_zend_extension, __tea_extension_module.handle);

    return tea_extension_init_run(
        &tea_extension_minit_list, INIT_FUNC_ARGS_PASSTHRU);
}

static PHP_MSHUTDOWN_FUNCTION(tea_extension) {
#if PHP_VERSION_ID < 80300
    if (tea_extension_functions_list.size) {
        zend_unregister_functions(
            tea_extension_functions_list.entries,
            tea_extension_functions_list.size - 1,
            NULL);
    }
#endif

    return tea_extension_shutdown_run(
        &tea_extension_mshutdown_list, SHUTDOWN_FUNC_ARGS_PASSTHRU);
}

static PHP_RINIT_FUNCTION(tea_extension) {
    return tea_extension_init_run(
        &tea_extension_rinit_list, INIT_FUNC_ARGS_PASSTHRU);
}

static PHP_RSHUTDOWN_FUNCTION(tea_extension) {
    return tea_extension_shutdown_run(
        &tea_extension_rshutdown_list, SHUTDOWN_FUNC_ARGS_PASSTHRU);
}

static void tea_extension_op_array_handler_run(zend_op_array *op_array) {
    tea_extension_op_array_run(&tea_extension_op_array_handler_list, op_array);
}

static void tea_extension_op_array_ctor_run(zend_op_array *op_array) {
    tea_extension_op_array_run(&tea_extension_op_array_ctor_list, op_array);
}

static void tea_extension_op_array_dtor_run(zend_op_array *op_array) {
    tea_extension_op_array_run(&tea_extension_op_array_dtor_list, op_array);
}

zend_module_entry* tea_extension_dummy() {
    tea_extension_dummy_list.size++;
    tea_extension_dummy_list.modules =
        realloc(
            tea_extension_dummy_list.modules,
            tea_extension_dummy_list.size * sizeof(zend_module_entry));

    zend_module_entry *dummy =
        &tea_extension_dummy_list.modules[
            tea_extension_dummy_list.size - 1];

    memset(dummy, 0, sizeof(zend_module_entry));

    return dummy;
}

void tea_extension_name(const char *name, size_t len) {
    if (len > TEA_EXTENSION_NAME_MAX_LEN) {
        return;
    }

    memcpy(__tea_extension_name, name, len);

    __tea_extension_name[len] = 0;
}

void tea_extension_minit(tea_extension_init_function handler) {
    tea_extension_minit_list.size++;
    tea_extension_minit_list.handlers =
        realloc(
            tea_extension_minit_list.handlers,
            tea_extension_minit_list.size * sizeof(tea_extension_init_function));
    tea_extension_minit_list.handlers[
        tea_extension_minit_list.size - 1] = handler;
}

void tea_extension_rinit(tea_extension_init_function handler) {
    tea_extension_rinit_list.size++;
    tea_extension_rinit_list.handlers =
        realloc(
            tea_extension_rinit_list.handlers,
            tea_extension_rinit_list.size * sizeof(tea_extension_init_function));
    tea_extension_rinit_list.handlers[
        tea_extension_rinit_list.size - 1] = handler;
}

void tea_extension_rshutdown(tea_extension_shutdown_function handler) {
    tea_extension_rshutdown_list.size++;
    tea_extension_rshutdown_list.handlers =
        realloc(
            tea_extension_rshutdown_list.handlers,
            tea_extension_rshutdown_list.size * sizeof(tea_extension_shutdown_function));
    tea_extension_rshutdown_list.handlers[
        tea_extension_rshutdown_list.size - 1] = handler;
}

void tea_extension_mshutdown(tea_extension_shutdown_function handler) {
    tea_extension_mshutdown_list.size++;
    tea_extension_mshutdown_list.handlers =
        realloc(
            tea_extension_mshutdown_list.handlers,
            tea_extension_mshutdown_list.size * sizeof(tea_extension_shutdown_function));
    tea_extension_mshutdown_list.handlers[
        tea_extension_mshutdown_list.size - 1] = handler;
}

static void tea_extension_function(const zend_function_entry *entry) {
    tea_extension_functions_list.size++;
    tea_extension_functions_list.entries =
        realloc(
            tea_extension_functions_list.entries,
            tea_extension_functions_list.size * sizeof(zend_function_entry));
    if (entry) {
        memcpy(
            &tea_extension_functions_list.entries[
                tea_extension_functions_list.size - 1],
            entry,
            sizeof(zend_function_entry));
    } else {
        /* PHP_FE_END */
        memset(
            &tea_extension_functions_list.entries[
                tea_extension_functions_list.size - 1],
                0,
                sizeof(zend_function_entry));
    }
}

void tea_extension_functions(const zend_function_entry *entries) {
    size_t entry = 0;

    do {
        tea_extension_function(&entries[entry]);
    } while (entries[++entry].fname);
}

void tea_extension_op_array_handler(tea_extension_op_array_function handler) {
    tea_extension_op_array_handler_list.size++;
    tea_extension_op_array_handler_list.handlers =
            realloc(
                    tea_extension_op_array_handler_list.handlers,
                    tea_extension_op_array_handler_list.size * sizeof(tea_extension_op_array_function));
    tea_extension_op_array_handler_list.handlers[
            tea_extension_op_array_handler_list.size - 1] = handler;
}

void tea_extension_op_array_ctor(tea_extension_op_array_function handler) {
    tea_extension_op_array_ctor_list.size++;
    tea_extension_op_array_ctor_list.handlers =
            realloc(
                    tea_extension_op_array_ctor_list.handlers,
                    tea_extension_op_array_ctor_list.size * sizeof(tea_extension_op_array_function));
    tea_extension_op_array_ctor_list.handlers[
            tea_extension_op_array_ctor_list.size - 1] = handler;
}

void tea_extension_op_array_dtor(tea_extension_op_array_function handler) {
    tea_extension_op_array_dtor_list.size++;
    tea_extension_op_array_dtor_list.handlers =
            realloc(
                    tea_extension_op_array_dtor_list.handlers,
                    tea_extension_op_array_dtor_list.size * sizeof(tea_extension_op_array_function));
    tea_extension_op_array_dtor_list.handlers[
            tea_extension_op_array_dtor_list.size - 1] = handler;
}

void tea_extension_startup(tea_extension_startup_function handler) {
    tea_extension_startup_list.size++;
    tea_extension_startup_list.handlers =
            realloc(
                    tea_extension_startup_list.handlers,
                    tea_extension_startup_list.size * sizeof(tea_extension_op_array_function));
    tea_extension_startup_list.handlers[
            tea_extension_startup_list.size - 1] = handler;
}

zend_module_entry* tea_extension_module() {
    return &__tea_extension_module;
}

static void tea_extension_free(void **address, size_t *reset) {
    if (!(*address)) {
        goto tea_extension_free_leave;
    }

    free(*address);

tea_extension_free_leave:
    *address = NULL;
    *reset   = 0;
}

void tea_extension_sinit(void) {
    tea_extension_name("TEA", sizeof("TEA")-1);

    tea_extension_free(
        (void**) &tea_extension_dummy_list.modules,
        &tea_extension_dummy_list.size);

    tea_extension_free(
        (void**) &tea_extension_minit_list.handlers,
        &tea_extension_minit_list.size);

    tea_extension_free(
        (void**) &tea_extension_rinit_list.handlers,
        &tea_extension_rinit_list.size);

    tea_extension_free(
        (void**) &tea_extension_rshutdown_list.handlers,
        &tea_extension_rshutdown_list.size);

    tea_extension_free(
        (void**) &tea_extension_mshutdown_list.handlers,
        &tea_extension_mshutdown_list.size);

    tea_extension_free(
        (void**) &tea_extension_functions_list.entries,
        &tea_extension_functions_list.size);

    tea_extension_free(
        (void**) &tea_extension_op_array_handler_list.handlers,
        &tea_extension_op_array_handler_list.size);

    tea_extension_free(
        (void**) &tea_extension_op_array_ctor_list.handlers,
        &tea_extension_op_array_ctor_list.size);

    tea_extension_free(
        (void**) &tea_extension_op_array_dtor_list.handlers,
        &tea_extension_op_array_dtor_list.size);

    tea_extension_free(
        (void**) &tea_extension_startup_list.handlers,
        &tea_extension_startup_list.size);
}
// clang-format on
