#include "../tsrmls_cache.h"

#if PHP_VERSION_ID >= 80000
#include <Zend/zend_observer.h>
#endif
#if PHP_VERSION_ID >= 80200
#include <Zend/zend_extensions.h>
#endif

#include <Zend/zend_closures.h>
#include <ctype.h>
#include "sandbox.h"

#if PHP_VERSION_ID <= 70200
#define ZEND_ACC_FAKE_CLOSURE ZEND_ACC_INTERFACE
#endif

#if PHP_VERSION_ID >= 80000
// stack allocate some memory to avoid overwriting stack allocated things needed for observers
char (*zai_call_throwaway_buffer_pointer)[];
zend_result zend_call_function_wrapper(zend_fcall_info *fci, zend_fcall_info_cache *fci_cache) {
#ifdef __SANITIZE_ADDRESS__
#define STACK_BUFFER_SIZE 8192 // asan has more overhead
#else
#define STACK_BUFFER_SIZE 6144
#endif
    char buffer[STACK_BUFFER_SIZE];  // dynamic runtime symbol resolving can have some stack overhead
    zai_call_throwaway_buffer_pointer = &buffer;
    return zend_call_function(fci, fci_cache);
}

#define zend_call_function zend_call_function_wrapper
#endif

#if PHP_VERSION_ID >= 80200
#define ZEND_OBSERVER_NOT_OBSERVED ((void *) 2)

zend_execute_data *zai_set_observed_frame(zend_execute_data *execute_data) {
    // Although the tracer being present should always cause an observer to be
    // present, if zai is used from another extension, like say the profiler,
    // then this may not be set.
    if (zend_observer_fcall_op_array_extension < 0) {
        return NULL;
    }

    zend_execute_data fake_ex[2]; // 2 to have some space for observer temps
    zend_function dummy_observable_func;
    dummy_observable_func.type = ZEND_INTERNAL_FUNCTION;
    dummy_observable_func.common.fn_flags = 0;
    dummy_observable_func.common.T = 1; // the single temporary having the prev_observed address
    fake_ex->func = &dummy_observable_func;
    fake_ex->prev_execute_data = execute_data;
    ZEND_CALL_NUM_ARGS(fake_ex) = 0;

    size_t cache_size = zend_internal_run_time_cache_reserved_size();
    ALLOCA_FLAG(use_heap);
    void **rt_cache = do_alloca(cache_size, use_heap);
    memset(rt_cache, 0, cache_size);
    // Set the begin handler to not observed and the end handler (where ever it is) to NULL (implicitly due to ecalloc)
#if PHP_VERSION_ID >= 80400
    rt_cache[zend_observer_fcall_internal_function_extension] = ZEND_OBSERVER_NOT_OBSERVED;
#else
    rt_cache[zend_observer_fcall_op_array_extension] = ZEND_OBSERVER_NOT_OBSERVED;
#endif
    ZEND_MAP_PTR_INIT(dummy_observable_func.op_array.run_time_cache, rt_cache);

    // We have a run_time cache with nothing observed, meaning no uncontrolled code will be executed now
    // However, it will in any case update current_observed_frame to our fake frame (needed so that zend_observer_fcall_end() accepts our fake frame)
    zend_observer_fcall_begin(fake_ex);

    // write the prev_observed address
    zend_execute_data **prev_observed = (zend_execute_data **)&fake_ex[1], *cur_prev_observed = *prev_observed;
    *prev_observed = execute_data;

    // Now, fetch current_observed_frame from the prev_observed address of the fake frame
    zend_observer_fcall_end(fake_ex, NULL);

    free_alloca(rt_cache, use_heap);

    return cur_prev_observed;
}
#endif

#if PHP_VERSION_ID >= 80000
void zai_reset_observed_frame_post_bailout(void) {
    if (EG(current_execute_data)) {
        zend_execute_data *cur_ex = EG(current_execute_data);
        zend_execute_data backup_ex = *cur_ex;
        EG(current_execute_data) = &backup_ex;
        cur_ex->prev_execute_data = NULL;
        cur_ex->func = NULL;
        zend_observer_fcall_end_all();
        *cur_ex = *EG(current_execute_data);
        EG(current_execute_data) = cur_ex;
    } else {
        zend_observer_fcall_end_all();
    }
}
#endif

static inline int zai_sandbox_try_call(zend_fcall_info *fci, zend_fcall_info_cache *fcc) {
    volatile int ret;
    zend_try {
        ret = zend_call_function(fci, fcc);
    } zend_catch {
        ret = 2;
    } zend_end_try();
    return ret;
}

bool zai_sandbox_call(zai_sandbox *sandbox, zend_fcall_info *fci, zend_fcall_info_cache *fcc) {
    bool zai_sandbox_call_bailed = false;

#if PHP_VERSION_ID >= 80200
    zend_execute_data *prev_observed = zai_set_observed_frame(NULL);
#endif

    int zai_sandbox_call_result = zai_sandbox_try_call(fci, fcc);
    zai_sandbox_call_bailed = zai_sandbox_call_result == 2;

    if (zai_sandbox_call_bailed) {
        zai_sandbox_bailout(sandbox);
#if PHP_VERSION_ID >= 80000
        zai_reset_observed_frame_post_bailout();
#endif
    }

#if PHP_VERSION_ID >= 80200
    zai_set_observed_frame(prev_observed);
#endif

    return zai_sandbox_call_result == SUCCESS && EG(exception) == NULL;
}
