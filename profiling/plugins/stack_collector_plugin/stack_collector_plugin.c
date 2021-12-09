#include "stack_collector_plugin.h"

#include "../log_plugin/log_plugin.h"
#include "../recorder_plugin/recorder_plugin.h"
#include <components/time/time.h>
#include <stack-collector/stack-collector.h>

#include <Zend/zend_execute.h>
#include <errno.h>
#include <php_config.h>
#include <stdatomic.h>
#include <uv.h>

typedef datadog_php_stack_sample stack_sample_t;
typedef datadog_php_stack_sample_frame stack_sample_frame_t;
typedef datadog_php_stack_sample_iterator stack_sample_iterator_t;

struct globals_s {
  bool have_thread;
  pthread_t thread;

  void (*prev_interrupt_function)(zend_execute_data *);
  void (*prev_execute_internal)(zend_execute_data *, zval *);
};

static struct globals_s globals;

ZEND_TLS int64_t zend_thread_id;
static _Atomic bool enabled;

void datadog_php_stack_collector_first_activate(bool profiling_enabled) {
  zend_thread_id = (int64_t)uv_thread_self();

  if (datadog_php_recorder_plugin_cpu_time_is_enabled()) {
    datadog_php_cpu_time_result now = datadog_php_cpu_time_now();
    if (now.tag == DATADOG_PHP_CPU_TIME_ERR) {
      datadog_php_string_view messages[] = {
          datadog_php_string_view_from_cstr(
              "[Datadog Profiling] Failed to retrieve cpu time; profiles will not be collected: "),
          datadog_php_string_view_from_cstr(now.err),
      };
      prof_logger.logv(DATADOG_PHP_LOG_ERROR,
                       sizeof messages / sizeof *messages, messages);
      enabled = false;
      return;
    }
  }

  enabled = profiling_enabled;
}

/* By default, no interrupt function is set. Other extensions may set one, and
 * if so then it ought to be called when overriding it. The _helper version
 * will call the previous interrupt function.
 */
static void datadog_php_stack_collector_interrupt_function(zend_execute_data *);
static void
datadog_php_stack_collector_interrupt_function_helper(zend_execute_data *);

/* previous execute_internal function _must not_ be null; set to
 * execute_internal if necessary
 */
static void datadog_php_stack_collector_execute_internal(zend_execute_data *,
                                                         zval *retval);

DDTRACE_COLD void
datadog_php_stack_collector_startup(zend_extension *extension) {
  (void)extension;

#if !defined(ZTS)
  globals.prev_interrupt_function = zend_interrupt_function;
  zend_interrupt_function = globals.prev_interrupt_function
      ? datadog_php_stack_collector_interrupt_function_helper
      : datadog_php_stack_collector_interrupt_function;

  globals.prev_execute_internal =
      zend_execute_internal ? zend_execute_internal : execute_internal;
  zend_execute_internal = datadog_php_stack_collector_execute_internal;
#endif
}

typedef uint64_t uv_hrtime_t;

/**
 * We need to pass the address of the VM interrupt (or the whole globals) to the
 * interrupt function. We also need to pass our own interrupt flag, as other
 * extensions can trigger interrupts as well, and we should only handle our own.
 *
 * The sigevent struct can only pass an integer or a single pointer, so we make
 * a composite struct to hold everything we need.
 */
typedef struct stack_collector_thread_globals {
  _Atomic uint64_t interrupt_count;
  zend_executor_globals *eg;
  bool have_uv_loop;
  uv_loop_t uv_loop;
  uv_timer_t uv_timer;
  uv_async_t stop_async;
  uv_hrtime_t last_event_at;
  struct timespec last_cpu;
  stack_sample_t sample; // this is big!
} stack_collector_thread_globals;

_Thread_local stack_collector_thread_globals thread_globals;

void datadog_php_stack_collector_deactivate(void) {
  if (!enabled)
    return;

  if (thread_globals.have_uv_loop) {
    // return code is not documented; quick scan of src on unix only returns 0
    (void)uv_async_send(&thread_globals.stop_async);
  }

  if (globals.have_thread) {
    enum {
      PTHREAD_JOIN_SUCCESS = 0,
      PTHREAD_JOIN_EDEADLK = EDEADLK,
      PTHREAD_JOIN_EINVAL = EINVAL,
      PTHREAD_JOIN_ESRCH = ESRCH,

    } status = pthread_join(globals.thread, NULL);

    if (status != PTHREAD_JOIN_SUCCESS) {
      datadog_php_log_level level = DATADOG_PHP_LOG_ERROR;
      datadog_php_string_view messages[2] = {
          datadog_php_string_view_from_cstr(
              "[Datadog Profiling] Stack Collector failed to join: "),
          datadog_php_string_view_from_cstr(strerror(status)),
      };

      prof_logger.logv(level, 2, messages);
    }
  }

  globals.have_thread = false;
}

static void datadog_php_stack_collector_collect_cb(uv_timer_t *handle) {
  stack_collector_thread_globals *remote_globals = handle->data;

  /* There is a race condition here; the VM could handle the interrupt after
   * the counter has been incremented but before the global vm_interrupt has
   * been set.
   * Mostly, this means that sometimes the vm_interrupt will have an extra
   * count, or sometimes it will run and be 0. Both situations should be
   * tolerable.
   */
  uint64_t prev_val = atomic_fetch_add(&remote_globals->interrupt_count, 1);
  if (prev_val == 0) {
    remote_globals->eg->vm_interrupt = 1;
  }
}

void stop_uv_async_cb(uv_async_t *handle) {
  stack_collector_thread_globals *remote_globals = handle->data;
  uv_stop(handle->loop);
  uv_close((uv_handle_t *)&remote_globals->uv_timer, NULL);
  uv_close((uv_handle_t *)&remote_globals->stop_async, NULL);
  remote_globals->have_uv_loop = false;
}

static void libuv_close_handles(uv_handle_t *handle, void *arg) {
  (void)arg;
  uv_close(handle, NULL);
}

static void *datadog_php_stack_collector_loop(
    stack_collector_thread_globals *remote_globals) {
  prof_logger.log_cstr(DATADOG_PHP_LOG_DEBUG,
                       "[Datadog Profiling] Stack Collector online.");

  uv_loop_t *loop = &remote_globals->uv_loop;

  // If there are unclosed handles this will return non-zero
  if (uv_run(loop, UV_RUN_DEFAULT)) {
    // make an attempt to close all handles
    uv_walk_cb walk_cb = libuv_close_handles;
    uv_walk(loop, walk_cb, NULL);

    // run it once to allow libuv to call the close handlers
    (void)uv_run(loop, UV_RUN_NOWAIT);
  }

  // if there are open handles this will return UV_EBUSY
  if (uv_loop_close(loop)) {
    char *cstr =
        "[Datadog Profiling] Stack Collector uncleanly exited; memory leaks are likely.";
    prof_logger.log_cstr(DATADOG_PHP_LOG_WARN, cstr);
  } else {
    prof_logger.log_cstr(DATADOG_PHP_LOG_DEBUG,
                         "[Datadog Profiling] Stack Collector cleanly exited.");
  }

  return NULL;
}

static bool libuv_activate(stack_collector_thread_globals *remote_globals) {
  uv_loop_t *loop = &remote_globals->uv_loop;
  if (uv_loop_init(loop) != 0) {
    const char *msg =
        "[Datadog Profiling] Stack Collector uv_loop_init returned non-zero status";
    prof_logger.log_cstr(DATADOG_PHP_LOG_ERROR, msg);
    return false;
  }

  uv_timer_t *timer = &remote_globals->uv_timer;
  if (uv_timer_init(loop, timer)) {
    const char *msg =
        "[Datadog Profiling] Stack Collector uv_timer_init returned non-zero status.";
    prof_logger.log_cstr(DATADOG_PHP_LOG_ERROR, msg);
    goto cleanup_loop;
  }

  timer->data = remote_globals;

  // timeout and repeat are in milliseconds.
  uv_timer_cb cb = datadog_php_stack_collector_collect_cb;
  if (uv_timer_start(timer, cb, 10, 10) != 0) {
    const char *msg =
        "[Datadog Profiling] Stack Collector uv_timer_start returned non-zero status.";
    prof_logger.log_cstr(DATADOG_PHP_LOG_ERROR, msg);
    goto cleanup_timer;
  }

  uv_async_t *stop_async = &remote_globals->stop_async;
  stop_async->data = remote_globals;
  if (uv_async_init(loop, stop_async, stop_uv_async_cb) != 0) {
    const char *msg =
        "[Datadog Profiling] Stack Collector uv_async_init  returned non-zero status.";
    prof_logger.log_cstr(DATADOG_PHP_LOG_ERROR, msg);
    goto cleanup_timer;
  }

  return true;

cleanup_timer:
  uv_timer_stop(timer);

cleanup_loop:
  uv_loop_close(loop);
  return false;
}

void datadog_php_stack_collector_activate(void) {
  if (!enabled)
    return;

  atomic_store(&thread_globals.interrupt_count, 0);
  thread_globals.eg = &executor_globals;
  thread_globals.have_uv_loop = false;
  thread_globals.last_event_at = uv_hrtime();

  struct timespec cpu_spec = {};
  if (datadog_php_recorder_plugin_cpu_time_is_enabled()) {
    datadog_php_cpu_time_result cpu_now = datadog_php_cpu_time_now();
    if (cpu_now.tag == DATADOG_PHP_CPU_TIME_OK) {
      cpu_spec = cpu_now.ok;
    }
  }
  thread_globals.last_cpu = cpu_spec;

  datadog_php_stack_sample_ctor(&thread_globals.sample);

  thread_globals.have_uv_loop = libuv_activate(&thread_globals);
  if (!thread_globals.have_uv_loop) {
    return;
  }

  enum {
    PTHREAD_CREATE_SUCCESS = 0,
    PTHREAD_CREATE_EAGAIN = EAGAIN,
    PTHREAD_CREATE_EINVAL = EINVAL,
    PTHREAD_CREATE_EPERM = EPERM,
  } status = pthread_create(&globals.thread, NULL,
                            (void *(*)(void *))datadog_php_stack_collector_loop,
                            &thread_globals);

  if (status != PTHREAD_CREATE_SUCCESS) {
    const char *str = NULL;
    switch (status) {
    case PTHREAD_CREATE_EAGAIN:
      str = "[Datadog Profiling] Error creating pthread; EAGAIN.";
      break;

    case PTHREAD_CREATE_EINVAL:
      str = "[Datadog Profiling] Error creating pthread; EINVAL.";
      break;

    case PTHREAD_CREATE_EPERM:
      str = "[Datadog Profiling] Error creating pthread; EPERM.";
      break;

    default:
      str = "[Datadog Profiling] Error creating pthread; unknown error.";
    }
    prof_logger.log_cstr(DATADOG_PHP_LOG_ERROR, str);
    return;
  }

  globals.have_thread = true;
}

static void datadog_php_stack_collector_interrupt_function(
    zend_execute_data *execute_data) {
  if (!enabled || !datadog_php_recorder_plugin_is_enabled()) {
    return;
  }

  uint32_t interrupt_count =
      atomic_exchange(&thread_globals.interrupt_count, 0);

  /* This may be 0 due to legitimate cases. Our zend_execute_internal override
   * may call this function and then the engine may call it again when it does
   * its regular VM interrupt afterwards, and this may be 0 in such cases.
   * It may also be 0 if another extension triggered the interrupt.
   * Therefore, don't consider interrupt_count == 0 to be a defect.
   */
  if (interrupt_count == 0) {
    return;
  }

  uv_hrtime_t last_event_at = thread_globals.last_event_at;
  thread_globals.last_event_at = uv_hrtime();

  int64_t cpu_time = 0;
  if (datadog_php_recorder_plugin_cpu_time_is_enabled()) {
    datadog_php_cpu_time_result cpu_now = datadog_php_cpu_time_now();
    if (cpu_now.tag == DATADOG_PHP_CPU_TIME_OK) {
      struct timespec now = cpu_now.ok;
      struct timespec then = thread_globals.last_cpu;
      int64_t current = now.tv_sec * INT64_C(1000000000) + now.tv_nsec;
      int64_t prev = then.tv_sec * INT64_C(1000000000) + then.tv_nsec;
      cpu_time = current - prev;
      thread_globals.last_cpu = now;
    }
  }

  datadog_php_stack_collect(execute_data, &thread_globals.sample);
  if (!thread_globals.sample.depth) {
    return;
  }

  uv_hrtime_t ns_since_last = thread_globals.last_event_at - last_event_at;
  datadog_php_record_values values = {
      .count = (int64_t)interrupt_count,
      .wall_time = (int64_t)ns_since_last,
      .cpu_time = cpu_time,
  };

  datadog_php_recorder_plugin_record(values, zend_thread_id,
                                     &thread_globals.sample);
}

static void datadog_php_stack_collector_interrupt_function_helper(
    zend_execute_data *execute_data) {
  datadog_php_stack_collector_interrupt_function(execute_data);
  globals.prev_interrupt_function(execute_data);
}

/* The purpose of this hook is to be able to handle interrupts _before_ the
 * engine pops the internal call frame off the top of the stack. This has extra
 * performance cost.
 * Ideally we'd get a patch into upstream PHP to adjust precisely when the
 * interrupt handling occurs, so we can catch internal funcs, but the current
 * model relies on interrupts happening only at the end of opcodes so that
 * an interrupt can cause the engine to jump to another place. This could be
 * used to implement coroutine scheduling, for example. Since internal function
 * calls are a single opcode, we cannot trigger the interrupt before cleaning up
 * and preserve this semantic.
 */
static void
datadog_php_stack_collector_execute_internal(zend_execute_data *execute_data,
                                             zval *retval) {
  globals.prev_execute_internal(execute_data, retval);
  if (UNEXPECTED(EG(vm_interrupt))) {
    /* This calls the version that doesn't delegate to the previous interrupt
     * function since interrupt handlers are not designed to run at this
     * location of the VM.
     */
    datadog_php_stack_collector_interrupt_function(execute_data);
  }
}
