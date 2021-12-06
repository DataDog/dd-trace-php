#include "recorder_plugin.h"

#include <php_datadog-profiling.h>
#include <plugins/log_plugin/log_plugin.h>

#include <SAPI.h>
#include <components/arena/arena.h>
#include <components/channel/channel.h>
#include <components/string_view/string_view.h>
#include <components/time/time.h>
#include <ddprof/ffi.h>
#include <php.h>
#include <stdatomic.h>
#include <stdlib.h>
#include <uv.h>

// must come after php.h
#include <ext/standard/info.h>

#define SLICE_LITERAL(str)                                                     \
  (struct ddprof_ffi_Slice_c_char) { .ptr = (str), .len = sizeof(str) - 1 }

ddprof_ffi_ByteSlice to_byteslice(const char *str) {
  return (ddprof_ffi_ByteSlice){(const uint8_t *)str, strlen(str)};
}

typedef struct string_s {
  uint32_t len;
  char *data;
} string;

ddprof_ffi_ByteSlice string_to_byteslice(struct string_s str) {
  uint8_t *ptr = (uint8_t *)str.data;
  return (ddprof_ffi_ByteSlice){.ptr = ptr, .len = str.len};
}

struct {
  /**
   * We need to make copies of the environment variables because they can be
   * invalidated by the environment. We store all of these strings in the arena.
   */
  unsigned char strings[4096];
  datadog_php_arena *arena;

  string agent_url;
  string env;
  string service;
  string version;
  string cpu_time_enabled;
} globals;

static _Atomic bool enabled = false;
static bool cpu_time_enabled = false;

bool datadog_php_recorder_plugin_is_enabled(void) { return enabled; }
bool datadog_php_recorder_plugin_cpu_time_is_enabled(void) {
  return cpu_time_enabled;
}

static string arena_string_alloc(datadog_php_arena *arena, const uint32_t len) {
  /* We ensure that every string is padded at the end by null bytes to align it
   * to max_align_t, ensuring there is at least 1 null byte. This is would
   * likely happen anyway if another object of the same or larger alignment is
   * allocated next, but we guarantee it because it is useful.
   */
  uint32_t align = _Alignof(max_align_t);
  uintptr_t len_with_one_null_byte = len + 1;
  uint32_t diff = datadog_php_arena_align_diff(len_with_one_null_byte, align);
  uint32_t allocation_size = len_with_one_null_byte + diff;

  char *data = (char *)datadog_php_arena_alloc(arena, allocation_size, align);
  if (data) {
    // zero trailing bytes
    memset(data + len, 0, allocation_size - len);
    return (string){.len = allocation_size, .data = data};
  }
  return (string){0, ""};
}

static string arena_string_new(datadog_php_arena *arena, uint32_t len,
                               const char *source) {
  string dest = arena_string_alloc(arena, len);
  if (dest.len) {
    memcpy(dest.data, source, len);
    dest.len = len;
  }
  return dest;
}

/* thread_id will point to thread_id_v if the thread is created successfully;
 * null otherwise.
 */
static uv_thread_t thread_id_v, *thread_id = NULL;
static datadog_php_channel channel;
static ddprof_ffi_ProfileExporterV3 *exporter = NULL;

typedef struct record_msg_s record_msg;
struct record_msg_s {
  datadog_php_record_values record_values;
  int64_t thread_id;
  datadog_php_stack_sample sample;
};

__attribute__((nonnull)) bool
datadog_php_recorder_plugin_record(datadog_php_record_values record_values,
                                   int64_t tid,
                                   const datadog_php_stack_sample *sample) {
  if (!enabled) {
    const char *str =
        "[Datadog Profiling] Sample dropped because profiling has been disabled.";
    datadog_php_string_view msg = {strlen(str), str};
    prof_logger.log(DATADOG_PHP_LOG_WARN, msg);
    return false;
  }

  record_msg *message = malloc(sizeof(record_msg));
  if (message) {
    message->record_values = record_values;
    message->sample = *sample;
    message->thread_id = tid;

    bool success = channel.sender.send(&channel.sender, message);
    if (!success) {
      // todo: is this too noisy even for debug?
      const char *str =
          "[Datadog Profiling] Failed to store sample for aggregation; queue is likely full or closed.\n";
      datadog_php_string_view msg = {strlen(str), str};
      prof_logger.log(DATADOG_PHP_LOG_DEBUG, msg);
      free(message);
    }
    return success;
  }
  return false;
}

typedef struct instant_s instant;
struct instant_s {
  // private:
  uint64_t started_at_nanos;
};

static uint64_t instant_elapsed(instant self) {
  return (uv_hrtime() - self.started_at_nanos);
}

static instant instant_now(void) {
  instant now = {.started_at_nanos = uv_hrtime()};
  return now;
}

static bool ddprof_ffi_export(datadog_php_static_logger *logger,
                              const struct ddprof_ffi_Profile *profile) {
  struct ddprof_ffi_EncodedProfile *encoded_profile =
      ddprof_ffi_Profile_serialize(profile);
  if (!encoded_profile) {
    logger->log_cstr(DATADOG_PHP_LOG_WARN,
                     "[Datadog Profiling] Failed to serialize profile.");
    return false;
  }

  ddprof_ffi_Timespec start = encoded_profile->start;
  ddprof_ffi_Timespec end = encoded_profile->end;

  ddprof_ffi_Buffer profile_buffer = {
      .ptr = encoded_profile->buffer.ptr,
      .len = encoded_profile->buffer.len,
      .capacity = encoded_profile->buffer.capacity,
  };

  ddprof_ffi_File files_[] = {{
      .name = to_byteslice("profile.pprof"),
      .file = &profile_buffer,
  }};

  struct ddprof_ffi_Slice_file files = {.ptr = files_,
                                        .len = sizeof files_ / sizeof *files_};
  ddprof_ffi_Request *request =
      ddprof_ffi_ProfileExporterV3_build(exporter, start, end, files, 10000);
  bool succeeded = false;
  if (request) {
    struct ddprof_ffi_SendResult result =
        ddprof_ffi_ProfileExporterV3_send(exporter, request);

    if (result.tag == DDPROF_FFI_SEND_RESULT_FAILURE) {
      datadog_php_string_view messages[2] = {
          datadog_php_string_view_from_cstr(
              "[Datadog Profiling] Failed to upload profile: "),
          {result.failure.len, (const char *)result.failure.ptr},
      };
      logger->logv(DATADOG_PHP_LOG_WARN, 2, messages);
    } else if (result.tag == DDPROF_FFI_SEND_RESULT_HTTP_RESPONSE) {
      uint16_t code = result.http_response.code;
      if (200 <= code && code < 300) {
        logger->log_cstr(DATADOG_PHP_LOG_INFO,
                         "[Datadog Profiling] Successfully uploaded profile.");
        succeeded = true;
      } else {
        char code_string[8] = {'u', 'n', 'k', 'n', 'o', 'w', 'n', '\0'};
        (void)snprintf(code_string, sizeof code_string, "%" PRIu16, code);
        datadog_php_string_view messages[2] = {
            datadog_php_string_view_from_cstr(
                "[Datadog Profiling] Unexpected HTTP status code when sending profile: "),
            {strlen(code_string), code_string},
        };
        datadog_php_log_level log_level =
            code >= 400 ? DATADOG_PHP_LOG_ERROR : DATADOG_PHP_LOG_WARN;
        logger->logv(log_level, 2, messages);
      }
    }
  } else {
    logger->log_cstr(DATADOG_PHP_LOG_WARN,
                     "[Datadog Profiling] Failed to create HTTP request.");
  }
  ddprof_ffi_EncodedProfile_delete(encoded_profile);
  return succeeded;
}

/**
 * A frame is empty if it has neither a file name nor a function name.
 */
static bool is_empty_frame(datadog_php_stack_sample_frame *frame) {
  return (frame->function.len | frame->file.len) == 0;
}

static void datadog_php_recorder_add(struct ddprof_ffi_Profile *profile,
                                     record_msg *message) {
  uint32_t locations_capacity = message->sample.depth;
  struct ddprof_ffi_Location *locations =
      calloc(locations_capacity, sizeof(struct ddprof_ffi_Location));
  if (!locations) {
    prof_logger.log_cstr(
        DATADOG_PHP_LOG_WARN,
        "[Datadog Profiling] Failed to allocate storage for sample locations.");
    return;
  }

  // There is one line per location, at least as long as PHP doesn't inline
  struct ddprof_ffi_Line *lines =
      calloc(locations_capacity, sizeof(struct ddprof_ffi_Line));
  if (!lines) {
    prof_logger.log_cstr(
        DATADOG_PHP_LOG_WARN,
        "[Datadog Profiling] Failed to allocate storage for sample lines.");
    goto free_locations;
  }

  uint16_t locations_size = 0;
  datadog_php_stack_sample_iterator iterator;
  for (iterator = datadog_php_stack_sample_iterator_ctor(&message->sample);
       datadog_php_stack_sample_iterator_valid(&iterator);
       datadog_php_stack_sample_iterator_next(&iterator)) {
    datadog_php_stack_sample_frame frame =
        datadog_php_stack_sample_iterator_frame(&iterator);

    if (is_empty_frame(&frame)) {
      continue;
    }

    struct ddprof_ffi_Line *line = lines + locations_size;
    struct ddprof_ffi_Function function = {
        .name = {.ptr = frame.function.ptr, .len = frame.function.len},
        .filename = {.ptr = frame.file.ptr, .len = frame.file.len},
    };
    line->function = function;
    line->line = frame.lineno;

    struct ddprof_ffi_Location location = {
        /* Yes, we use an empty mapping! We don't map to a .so or anything
         * remotely like it, so we do not pretend.
         */
        .mapping = {},
        .lines = {.ptr = line, .len = 1},
        .is_folded = false,
    };
    locations[locations_size++] = location;
  }
  datadog_php_stack_sample_iterator_dtor(&iterator);

  int64_t values_storage[3] = {
      (int64_t)message->record_values.count,
      message->record_values.wall_time,
      message->record_values.cpu_time,
  };
  size_t values_storage_len = cpu_time_enabled ? 3 : 2;
  struct ddprof_ffi_Slice_i64 values = {.ptr = values_storage,
                                        .len = values_storage_len};

  char thread_id_str[24];
  size_t thread_id_len;
  int result = snprintf(thread_id_str, sizeof thread_id_str, "%" PRId64,
                        message->thread_id);
  if (result <= 0 || ((size_t)result) >= sizeof thread_id_str) {
    // include null byte in copy
    thread_id_len = sizeof("{unknown thread id}") - 1;
    memcpy(thread_id_str, "{unknown thread id}", thread_id_len + 1);
  } else {
    thread_id_len = result;
  }
  ddprof_ffi_Label thread_id_label = {
      .key = {"thread id", sizeof("thread id") - 1},
      .str = {thread_id_str, thread_id_len},
  };
  struct ddprof_ffi_Sample sample = {
      .values = values,
      .locations = {.ptr = locations, .len = locations_size},
      .labels = {.ptr = &thread_id_label, .len = 1},
  };

  ddprof_ffi_Profile_add(profile, sample);

  free(lines);
free_locations:
  free(locations);
}

static const struct ddprof_ffi_Period period = {
    .type_ =
        {
            .type_ = SLICE_LITERAL("wall-time"),
            .unit = SLICE_LITERAL("nanoseconds"),
        },

    /* An interval of 60 seconds often ends up with an HTTP 502 Bad Gateway
     * every 2nd request. I have not investigated this, but I suspect that it
     * has to do with some timeout set to 60 seconds and hasn't healed yet.
     *
     * This only occurs when going through the agent, not directly to intake.
     *
     * So, I did the sensible thing of picking a prime number close to 60
     * seconds. The choices 59 and 61 seems like they might be _too_ close, so
     * I went with 67 seconds.
     */
    .value = 67000000000,
};

static struct ddprof_ffi_Profile *profile_new(void) {

  /* Some tools assume the last value type is the "primary" one, so put
   * cpu-time last, as that's what the Datadog UI will default to (once it is
   * released).
   */
  static struct ddprof_ffi_ValueType value_types[3] = {
      {
          .type_ = SLICE_LITERAL("sample"),
          .unit = SLICE_LITERAL("count"),
      },
      {
          .type_ = SLICE_LITERAL("wall-time"),
          .unit = SLICE_LITERAL("nanoseconds"),
      },
      {
          .type_ = SLICE_LITERAL("cpu-time"),
          .unit = SLICE_LITERAL("nanoseconds"),
      },
  };

  struct ddprof_ffi_Slice_value_type sample_types = {
      .ptr = value_types,
      .len = cpu_time_enabled ? 3 : 2,
  };
  return ddprof_ffi_Profile_new(sample_types, &period);
}

void datadog_php_recorder_plugin_main(void) {
  if (period.value < 0) {
    // widest i64 is -9223372036854775808 (20 chars)
    char buffer[24] = {'(', 'u', 'n', 'k', 'n', 'o', 'w', 'n', ')', '\0'};

    (void)snprintf(buffer, sizeof buffer, "%" PRId64, period.value);

    datadog_php_string_view messages[] = {
        datadog_php_string_view_from_cstr(
            "[Datadog Profiling] Failed to start; invalid upload period of "),
        {strlen(buffer), buffer},
        datadog_php_string_view_from_cstr("."),
    };
    size_t n_messages = sizeof messages / sizeof *messages;
    prof_logger.logv(DATADOG_PHP_LOG_ERROR, n_messages, messages);
    return;
  }

  const uint64_t period_val = (uint64_t)period.value;
  datadog_php_receiver *receiver = &channel.receiver;
  struct ddprof_ffi_Profile *profile = profile_new();
  if (!profile) {
    const char *msg =
        "[Datadog Profiling] Failed to create profile. Samples will not be collected.";
    prof_logger.log_cstr(DATADOG_PHP_LOG_ERROR, msg);
    return;
  } else {
    const char *msg = "[Datadog Profiling] Recorder online.";
    prof_logger.log_cstr(DATADOG_PHP_LOG_DEBUG, msg);
  }

  while (enabled) {
    uint64_t sleep_for_nanos = period.value;
    instant before = instant_now();
    do {
      record_msg *message;
      if (receiver->recv(receiver, (void **)&message, sleep_for_nanos)) {
        // an empty message can be sent, such as when we're shutting down
        if (message) {
          datadog_php_recorder_add(profile, message);
          free(message);
        }
      }
      uint64_t duration = instant_elapsed(before);
      sleep_for_nanos = duration < period_val ? period_val - duration : 0;
      // protect against underflow
    } while (enabled && sleep_for_nanos);

    ddprof_ffi_export(&prof_logger, profile);
    (void)ddprof_ffi_Profile_reset(profile);
  }

  ddprof_ffi_Profile_free(profile);
  receiver->dtor(receiver);
}

void datadog_php_recorder_plugin_shutdown(zend_extension *extension) {
  (void)extension;

  if (!enabled)
    return;

  datadog_php_arena_delete(globals.arena);

  // Disable the plugin before sending as that flag's checked by the receiver.
  enabled = false;

  // Send an empty message to wake receiver up.
  channel.sender.send(&channel.sender, NULL);

  // Must clean up channel sender before thread join, or it will deadlock.
  channel.sender.dtor(&channel.sender);

  if (thread_id && uv_thread_join(thread_id)) {
    const char *str = "[Datadog Profiling] Recorder thread failed to join.";
    datadog_php_string_view message = {strlen(str), str};
    prof_logger.log(DATADOG_PHP_LOG_WARN, message);
  } else {
    const char *str = "[Datadog Profiling] Recorder offline.";
    datadog_php_string_view message = {strlen(str), str};
    prof_logger.log(DATADOG_PHP_LOG_INFO, message);
  }

  ddprof_ffi_ProfileExporterV3_delete(exporter);
}

// todo: extract this to sensible location (not recorder)
// Adapted from zai_env from Tracer project {{{
#if PHP_VERSION_ID >= 80000
#define sapi_getenv_compat(name, name_len) sapi_getenv((name), name_len)
#elif PHP_VERSION_ID >= 70000
#define sapi_getenv_compat(name, name_len) sapi_getenv((char *)(name), name_len)
#else
#define sapi_getenv_compat(name, name_len)                                     \
  sapi_getenv((char *)(name), name_len TSRMLS_CC)
#endif

/**
 * Only call during .first_activate.
 */
static string datadog_php_profiler_getenv(datadog_php_string_view name,
                                          datadog_php_arena *arena) {
  if (!name.len || !name.ptr || !arena)
    return (string){.len = 0, .data = ""};

  /* Some SAPIs do not initialize the SAPI-controlled environment variables
   * until SAPI RINIT. It is for this reason we cannot reliably access
   * environment variables until module RINIT.
   */
  if (!PG(modules_activated) && !PG(during_request_startup))
    return (string){.len = 0, .data = ""};

  /* sapi_getenv may or may not include process environment variables.
   * It will return NULL when it is not found in the possibly synthetic SAPI
   * environment. Hence, we need to do a getenv() in any case.
   */
  bool use_sapi_env = false;

  char *value = sapi_getenv_compat(name.ptr, name.len);
  if (value) {
    use_sapi_env = true;
  } else {
    value = getenv(name.ptr);
  }

  if (!value)
    return (string){.len = 0, .data = ""};

  size_t value_len = strlen(value);

  string result = arena_string_new(arena, value_len, value);

  if (use_sapi_env)
    efree(value);

  return result;
}
// }}}

#define SV(literal)                                                            \
  (datadog_php_string_view) { sizeof(literal) - 1, literal }

static string datadog_php_profiler_getenv_or(datadog_php_string_view name,
                                             datadog_php_arena *arena,
                                             datadog_php_string_view default_) {
  string env_var = datadog_php_profiler_getenv(name, arena);
  if (!env_var.len || !env_var.data[0]) {
    // the env_var doesn't have any data -- use the default instead
    return arena_string_new(arena, default_.len, default_.ptr);
  }
  return env_var;
}

static bool recorder_first_activate_helper() {
  globals.arena =
      datadog_php_arena_new(sizeof globals.strings, globals.strings);
  bool success = globals.arena != NULL;

  if (success) {
    // todo: choose capacity non-arbitrarily
    success = datadog_php_channel_ctor(&channel, 128);
  }

  if (!success) {
    datadog_php_arena_delete(globals.arena);
    return false;
  }

  // todo: DD_SITE + DD_API_KEY
  const char *path = "/profiling/v1/input";
  datadog_php_arena *arena = globals.arena;

  // prioritize URL over HOST + PORT
  string url = datadog_php_profiler_getenv(SV("DD_TRACE_AGENT_URL"), arena);
  if (!url.len || !url.data[0]) {

    string env_host = datadog_php_profiler_getenv(SV("DD_AGENT_HOST"), arena);
    const char *host =
        env_host.len && env_host.data[0] ? env_host.data : "localhost";

    string env_port =
        datadog_php_profiler_getenv(SV("DD_TRACE_AGENT_PORT"), arena);
    const char *port =
        env_port.len && env_port.data[0] ? env_port.data : "8126";

    // todo: can I log here?
    int size = snprintf(NULL, 0, "http://%s:%s%s", host, port, path);
    if (size <= 0)
      return false;

    string buffer = arena_string_alloc(arena, size);
    if (!buffer.len) {
      const char *msg =
          "[Datadog Profiling] Failed to start; could not create memory arena for configuration settings.";
      prof_logger.log_cstr(DATADOG_PHP_LOG_ERROR, msg);
      return false;
    }

    int result =
        snprintf(buffer.data, buffer.len, "http://%s:%s%s", host, port, path);
    if (result < size) {
      // todo: log failure
      return false;
    }

    globals.agent_url = buffer;
  } else {
    globals.agent_url = url;
  }

  // empty string is permitted for service
  datadog_php_string_view empty = {0, ""};
  globals.service =
      datadog_php_profiler_getenv_or(SV("DD_SERVICE"), arena, empty);

  // empty string is permitted for service
  globals.version =
      datadog_php_profiler_getenv_or(SV("DD_VERSION"), arena, empty);

  // empty string is permitted for env
  globals.env = datadog_php_profiler_getenv_or(SV("DD_ENV"), arena, empty);

  // experimental: enable cpu-time profile
  datadog_php_string_view no = {2, "no"};
  globals.cpu_time_enabled = datadog_php_profiler_getenv_or(
      SV("DD_PROFILING_EXPERIMENTAL_CPU_TIME_ENABLED"), arena, no);

  cpu_time_enabled = datadog_php_string_view_is_boolean_true(
      (datadog_php_string_view){.len = globals.cpu_time_enabled.len,
                                .ptr = globals.cpu_time_enabled.data});

  ddprof_ffi_ByteSlice base_url = string_to_byteslice(globals.agent_url);
  ddprof_ffi_EndpointV3 endpoint = ddprof_ffi_EndpointV3_agent(base_url);

  ddprof_ffi_Tag tags_[] = {
      {.name = to_byteslice("language"), .value = to_byteslice("php")},
      {.name = to_byteslice("service"),
       .value = string_to_byteslice(globals.service)},
      {.name = to_byteslice("env"), .value = string_to_byteslice(globals.env)},
      {.name = to_byteslice("version"),
       .value = string_to_byteslice(globals.version)},
      {.name = to_byteslice("profiler_version"),
       .value = to_byteslice(PHP_DATADOG_PROFILING_VERSION)},
  };

  ddprof_ffi_Slice_tag tags = {.ptr = tags_,
                               .len = sizeof tags_ / sizeof *tags_};

  struct ddprof_ffi_NewProfileExporterV3Result exporter_result =
      ddprof_ffi_ProfileExporterV3_new(to_byteslice("php"), tags, endpoint);
  if (exporter_result.tag == DDPROF_FFI_NEW_PROFILE_EXPORTER_V3_RESULT_ERR) {
    const char *str =
        "[Datadog Profiling] Failed to start; could not create HTTP uploader: ";
    datadog_php_string_view messages[] = {
        {strlen(str), str},
        {exporter_result.err.len, (const char *)exporter_result.err.ptr},
    };
    prof_logger.logv(DATADOG_PHP_LOG_ERROR, sizeof messages / sizeof *messages,
                     messages);
    ddprof_ffi_NewProfileExporterV3Result_dtor(exporter_result);
    return false;
  }

  exporter = exporter_result.ok;

  thread_id = &thread_id_v;
  int result = uv_thread_create(
      thread_id, (uv_thread_cb)datadog_php_recorder_plugin_main, NULL);
  if (result != 0) {
    thread_id = NULL;
    ddprof_ffi_ProfileExporterV3_delete(exporter);
    channel.receiver.dtor(&channel.receiver);
    channel.sender.dtor(&channel.sender);

    const char *str =
        "[Datadog Profiling] Failed to start; could not create thread for aggregating profiles.";
    datadog_php_string_view msg = {strlen(str), str};
    prof_logger.log(DATADOG_PHP_LOG_ERROR, msg);
    return false;
  }
  return true;
}

void datadog_php_recorder_plugin_first_activate(bool profiling_enabled) {
  enabled = profiling_enabled && recorder_first_activate_helper();
}

#if __cplusplus
#define C_STATIC(...)
#else
#define C_STATIC(...) static __VA_ARGS__
#endif

static int64_t
upload_logv(datadog_php_log_level level, size_t n_messages,
            datadog_php_string_view messages[C_STATIC(n_messages)]) {

  const char *key;
  switch (level) {
  default:
  case DATADOG_PHP_LOG_UNKNOWN:
    key = "unknown"; // shouldn't happen at this location
    break;
  case DATADOG_PHP_LOG_OFF:
    key = "off"; // shouldn't happen at this location
    break;
  case DATADOG_PHP_LOG_ERROR:
    key = "error";
    break;
  case DATADOG_PHP_LOG_WARN:
    key = "warn";
    break;
  case DATADOG_PHP_LOG_INFO:
    key = "info";
    break;
  case DATADOG_PHP_LOG_DEBUG:
    key = "debug";
    break;
  }

  size_t bytes = 2; // trailing newline and null byte
  for (size_t i = 0; i != n_messages; ++i) {
    bytes += messages[i].len;
  }

  char *message = malloc(bytes);
  if (message) {
    size_t offset = 0;
    for (size_t i = 0; i != n_messages; ++i) {
      memcpy(message + offset, messages[i].ptr, messages[i].len);
      offset += messages[i].len;
    }
    message[offset] = '\n';
    message[offset + 1] = '\0';
  }

  datadog_profiling_info_diagnostics_row(
      key, message ? message : "(error formatting messages)\n");

  free(message);

  return 0; // not intended to be checked
}

static void upload_log(datadog_php_log_level level,
                       datadog_php_string_view message) {
  (void)upload_logv(level, 1, &message);
}

static void upload_log_cstr(datadog_php_log_level level, const char *cstr) {
  upload_log(level, datadog_php_string_view_from_cstr(cstr));
}

#undef C_STATIC

ZEND_COLD void datadog_php_recorder_plugin_diagnose(void) {
  const char *yes = "yes", *no = "no";

  php_info_print_table_colspan_header(2, "Recorder Diagnostics");

  datadog_profiling_info_diagnostics_row("Enabled", enabled ? yes : no);

  struct ddprof_ffi_Profile *profile = profile_new();
  datadog_profiling_info_diagnostics_row("Can create profiles",
                                         profile ? yes : no);
  if (enabled && profile) {
    datadog_php_static_logger logger = {
        .log = upload_log,
        .logv = upload_logv,
        .log_cstr = upload_log_cstr,
    };

    php_info_print_table_colspan_header(2, "Upload Diagnostics");
    bool uploaded = ddprof_ffi_export(&logger, profile);
    datadog_profiling_info_diagnostics_row("Can upload profiles",
                                           uploaded ? yes : no);
  }
}
