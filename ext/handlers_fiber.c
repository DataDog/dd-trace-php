#include "ddtrace.h"
#include "configuration.h"
#include "handlers_fiber.h"
#include "span.h"
#include <Zend/zend_extensions.h>
#include <Zend/zend_observer.h>
#if PHP_VERSION_ID < 80200
#include <interceptor/php8/interceptor.h>
#endif

static int dd_resource_handle;

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

// PHP-8.1 crashes hard on any bailout originating from a fiber with observers enabled. Work around it.
// a) It does not update the observed frame on fibers
// b) A bailout on fibers does not invoke zend_observer_fcall_end_all()
#if PHP_VERSION_ID < 80200
// True if pre-PHP 8.1.4
static bool dd_legacy_observers;

typedef struct _zend_observer_fcall_data {
    zend_observer_fcall_handlers *end;
    zend_observer_fcall_handlers handlers[1];
} zend_observer_fcall_data;


static void dd_dummy_end_observer(zend_execute_data *execute_data, zval *return_value) {
    UNUSED(execute_data, return_value);
}

static void dd_set_observed_frame(zend_execute_data *execute_data) {
    zend_execute_data fake_ex;
    zend_function dummy_observable_func;
    dummy_observable_func.common.fn_flags = 0;
    fake_ex.func = &dummy_observable_func;
    fake_ex.prev_execute_data = execute_data;

    volatile void *run_time_cache_start;
    zend_observer_fcall_data handlers = { .end = handlers.handlers + 1, .handlers = { { NULL, &dd_dummy_end_observer } } };
    zend_observer_fcall_end_handler end_handlers[2] = { &dd_dummy_end_observer, NULL };
    if (dd_legacy_observers) {
        run_time_cache_start = ((void **) &handlers) - zend_observer_fcall_op_array_extension;
    } else {
        run_time_cache_start = ((void **) end_handlers) - zend_observer_fcall_op_array_extension - zai_registered_observers;
    }

    ZEND_MAP_PTR_INIT(dummy_observable_func.op_array.run_time_cache, (void *) &run_time_cache_start);
    zend_observer_fcall_end(&fake_ex, NULL);
}

static ZEND_FUNCTION(dd_wrap_fiber_entry_call) {
    UNUSED(return_value);

    zend_try {
        zend_fiber *fiber = zend_fiber_from_context(EG(current_fiber_context));
        ddtrace_span_stack *stack = fiber->context.reserved[dd_resource_handle];
        fiber->fci_cache.function_handler = stack->fiber_entry_function;
        stack->fiber_entry_function = NULL;

        // remove ourselves from the stack trace
        EG(current_execute_data) = EX(prev_execute_data);

        zend_call_function(&fiber->fci, &fiber->fci_cache);
    } zend_catch {
        zend_observer_fcall_end_all();
        zend_bailout();
    } zend_end_try();
}

ZEND_BEGIN_ARG_INFO_EX(dd_fiber_wrapper_arg_info, 0, 0, 0)
ZEND_END_ARG_INFO()

#define DD_FIBER_WRAPPER_ARGS(fn_flags) \
        ZEND_INTERNAL_FUNCTION, /* type              */ \
        {0, 0, 0},              /* arg_flags         */ \
        fn_flags,               /* fn_flags          */ \
        NULL,                   /* name              */ \
        NULL,                   /* scope             */ \
        NULL,                   /* prototype         */ \
        0,                      /* num_args          */ \
        0,                      /* required_num_args */ \
        (zend_internal_arg_info *) dd_fiber_wrapper_arg_info + 1, /* arg_info          */ \
        NULL,                   /* attributes        */ \
        ZEND_FN(dd_wrap_fiber_entry_call), /* handler           */ \
        NULL,                   /* module            */ \
        {0}                     /* reserved          */

static const zend_internal_function dd_fiber_wrapper = { DD_FIBER_WRAPPER_ARGS(0) };
static const zend_internal_function dd_ref_fiber_wrapper = { DD_FIBER_WRAPPER_ARGS(ZEND_ACC_RETURN_REFERENCE) };

ZEND_TLS zend_execute_data *dd_main_execute_data;
#endif

static void dd_observe_fiber_switch(zend_fiber_context *from, zend_fiber_context *to) {
    from->reserved[dd_resource_handle] = DDTRACE_G(active_stack);
    DDTRACE_G(active_stack) = to->reserved[dd_resource_handle];

#if PHP_VERSION_ID < 80200
    if (to->kind == zend_ce_fiber) {
        zend_fiber *fiber = zend_fiber_from_context(to);
        dd_set_observed_frame(fiber->execute_data);
    } else if (to == EG(main_fiber_context)) {
        dd_set_observed_frame(dd_main_execute_data);
    }

    if (from == EG(main_fiber_context)) {
        dd_main_execute_data = EG(current_execute_data);
    }
#endif
}

static void dd_observe_fiber_init(zend_fiber_context *context) {
    ddtrace_span_stack *stack = get_DD_TRACE_ENABLED() ? ddtrace_init_span_stack() : ddtrace_init_root_span_stack();
    context->reserved[dd_resource_handle] = stack;

#if PHP_VERSION_ID < 80200
    zend_long patch_version = Z_LVAL_P(zend_get_constant_str(ZEND_STRL("PHP_RELEASE_VERSION")));
    dd_legacy_observers = patch_version < 4;

    if (context->kind == zend_ce_fiber) {
        zend_fiber *fiber = zend_fiber_from_context(context);
        stack->fiber_entry_function = fiber->fci_cache.function_handler;
        if (fiber->fci_cache.function_handler->common.fn_flags & ZEND_ACC_RETURN_REFERENCE) {
            fiber->fci_cache.function_handler = (zend_function *)&dd_ref_fiber_wrapper;
        } else {
            fiber->fci_cache.function_handler = (zend_function *)&dd_fiber_wrapper;
        }
    }
#endif
}

static void dd_observe_fiber_destroy(zend_fiber_context *context) {
    ddtrace_span_stack *stack = context->reserved[dd_resource_handle];
    OBJ_RELEASE(&stack->std);
}

void ddtrace_setup_fiber_observers(void) {
    dd_resource_handle = zend_get_resource_handle(PHP_DDTRACE_EXTNAME);

    zend_observer_fiber_init_register(dd_observe_fiber_init);
    zend_observer_fiber_switch_register(dd_observe_fiber_switch);
    zend_observer_fiber_destroy_register(dd_observe_fiber_destroy);
}
