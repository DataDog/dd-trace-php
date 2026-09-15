#include "otel_sampling.h"
#include "tracestate.h"

#include <math.h>

#define DDTRACE_TRACESTATE_MAX_LEN 512
#define DDTRACE_TRACESTATE_MAX_MEMBERS 32
#define DDTRACE_OTEL_MAX_THRESHOLD (UINT64_C(1) << 56)
#define DDTRACE_OTEL_MAX_ENCODABLE_THRESHOLD (DDTRACE_OTEL_MAX_THRESHOLD - 1)

static const uint64_t DDTRACE_OTEL_KNUTH_FACTOR = UINT64_C(1111111111111111111);

typedef struct {
  const char* datadog_member;
  size_t datadog_member_len;
  const char* otel_member;
  size_t otel_member_len;
  size_t member_count;
} ddtrace_otel_tracestate_members;

static bool ddtrace_otel_parse_lower_hex(const char* value, size_t len, uint64_t* parsed) {
  uint64_t result = 0;
  for (size_t i = 0; i < len; ++i) {
    result <<= 4;
    if (value[i] >= '0' && value[i] <= '9') {
      result |= (uint64_t)(value[i] - '0');
    } else if (value[i] >= 'a' && value[i] <= 'f') {
      result |= (uint64_t)(value[i] - 'a' + 10);
    } else {
      return false;
    }
  }
  *parsed = result;
  return true;
}

static bool ddtrace_otel_field_is(const char* field, size_t field_len, const char* key) {
  return field_len >= 2 && field[0] == key[0] && field[1] == key[1] && (field_len == 2 || field[2] == ':');
}

static void ddtrace_otel_append_unknown_field(ddtrace_otel_sampling_state* state, const char* field, size_t field_len) {
  size_t separator_len = state->unknown_fields_len ? 1 : 0;
  if (state->unknown_fields_len + separator_len + field_len > DDTRACE_OTEL_MAX_VALUE_LEN) {
    return;
  }
  if (separator_len) {
    state->unknown_fields[state->unknown_fields_len++] = ';';
  }
  memcpy(state->unknown_fields + state->unknown_fields_len, field, field_len);
  state->unknown_fields_len += field_len;
}

void ddtrace_otel_sampling_parse(ddtrace_otel_sampling_state* state, const char* value, size_t value_len) {
  *state = (ddtrace_otel_sampling_state){0};
  const char* end = value + value_len;

  for (const char* field = value; field <= end;) {
    const char* field_end = memchr(field, ';', end - field);
    if (!field_end) {
      field_end = end;
    }
    size_t field_len = field_end - field;

    if (ddtrace_otel_field_is(field, field_len, "rv")) {
      state->random_value_len = 0;
      if (field_len == 17 && ddtrace_otel_parse_lower_hex(field + 3, 14, &state->random_value)) {
        state->random_value_len = 14;
      }
    } else if (ddtrace_otel_field_is(field, field_len, "th")) {
      state->threshold_len = 0;
      size_t threshold_len = field_len > 2 && field[2] == ':' ? field_len - 3 : 0;
      if (threshold_len >= 1 && threshold_len <= 14 && ddtrace_otel_parse_lower_hex(field + 3, threshold_len, &state->threshold)) {
        state->threshold_len = threshold_len;
      }
    } else if (field_len) {
      ddtrace_otel_append_unknown_field(state, field, field_len);
    }

    if (field_end == end) {
      break;
    }
    field = field_end + 1;
  }
}

static void ddtrace_otel_append_field(smart_str* result, const char* field, size_t field_len) {
  size_t separator_len = result->s ? 1 : 0;
  if ((result->s ? ZSTR_LEN(result->s) : 0) + separator_len + field_len > DDTRACE_OTEL_MAX_VALUE_LEN) {
    return;
  }
  if (separator_len) {
    smart_str_appendc(result, ';');
  }
  smart_str_appendl(result, field, field_len);
}

static uint64_t ddtrace_otel_threshold_for(double sample_rate) {
  if (sample_rate >= 1) {
    return 0;
  }

  double threshold = round((1 - sample_rate) * (double)DDTRACE_OTEL_MAX_THRESHOLD);
  if (threshold >= (double)DDTRACE_OTEL_MAX_THRESHOLD) {
    return DDTRACE_OTEL_MAX_ENCODABLE_THRESHOLD;
  }
  return (uint64_t)threshold;
}

static uint64_t ddtrace_otel_derive_random_value(uint64_t trace_id) {
  return ~(trace_id * DDTRACE_OTEL_KNUTH_FACTOR) >> 8;
}

static uint64_t ddtrace_otel_reconcile_random_value(uint64_t random_value, uint64_t threshold, bool kept) {
  if (kept && random_value < threshold) {
    return threshold;
  }
  if (!kept && random_value >= threshold) {
    return threshold > 0 ? threshold - 1 : 0;
  }
  return random_value;
}

void ddtrace_otel_sampling_decide_probability(ddtrace_otel_sampling_state* state, uint64_t trace_id, zend_long sampling_priority,
                                              double sample_rate) {
  uint64_t threshold = ddtrace_otel_threshold_for(sample_rate);
  state->random_value = ddtrace_otel_reconcile_random_value(ddtrace_otel_derive_random_value(trace_id), threshold, sampling_priority > 0);
  state->random_value_len = 14;
  state->threshold_len = 14;
  while (state->threshold_len > 1 && (threshold & 0xf) == 0) {
    threshold >>= 4;
    --state->threshold_len;
  }
  state->threshold = threshold;
}

void ddtrace_otel_sampling_decide_non_probability(ddtrace_otel_sampling_state* state) {
  state->threshold_len = 0;
}

static bool ddtrace_otel_is_member(const char* member, size_t member_len, const char** value, size_t* value_len) {
  while (member_len && (*member == ' ' || *member == '\t')) {
    ++member;
    --member_len;
  }
  while (member_len && (member[member_len - 1] == ' ' || member[member_len - 1] == '\t')) {
    --member_len;
  }

  if (member_len < 3 || memcmp(member, "ot=", 3) != 0) {
    return false;
  }

  *value = member + 3;
  *value_len = member_len - 3;
  return true;
}

zend_string* ddtrace_otel_sampling_extract_tracestate(zend_string* tracestate, ddtrace_otel_sampling_state* state) {
  *state = (ddtrace_otel_sampling_state){0};
  smart_str vendors = {0};
  bool found_otel = false;
  const char* raw = ZSTR_VAL(tracestate);
  const char* end = raw + ZSTR_LEN(tracestate);

  for (const char* member = raw; member < end;) {
    const char* member_end = memchr(member, ',', end - member);
    if (!member_end) {
      member_end = end;
    }
    size_t member_len = member_end - member;
    const char* otel_value;
    size_t otel_value_len;

    if (ddtrace_otel_is_member(member, member_len, &otel_value, &otel_value_len)) {
      if (!found_otel) {
        ddtrace_otel_sampling_parse(state, otel_value, otel_value_len);
        found_otel = true;
      }
    } else if (member_len) {
      if (vendors.s) {
        smart_str_appendc(&vendors, ',');
      }
      smart_str_appendl(&vendors, member, member_len);
    }

    member = member_end == end ? end : member_end + 1;
  }

  if (vendors.s) {
    smart_str_0(&vendors);
    return vendors.s;
  }
  return zend_string_init("", 0, 0);
}

void ddtrace_otel_sampling_append_to_tracestate(smart_str* tracestate, const ddtrace_otel_sampling_state* state) {
  smart_str value = {0};
  if (state->random_value_len) {
    smart_str_append_printf(&value, "rv:%0*" PRIx64, state->random_value_len, state->random_value);
  }
  if (state->threshold_len) {
    if (value.s) {
      smart_str_appendc(&value, ';');
    }
    smart_str_append_printf(&value, "th:%0*" PRIx64, state->threshold_len, state->threshold);
  }

  const char* unknown = state->unknown_fields;
  const char* end = unknown + state->unknown_fields_len;
  for (const char* field = unknown; field < end;) {
    const char* field_end = memchr(field, ';', end - field);
    if (!field_end) {
      field_end = end;
    }
    ddtrace_otel_append_field(&value, field, field_end - field);
    field = field_end == end ? end : field_end + 1;
  }

  if (!value.s) {
    return;
  }
  if (tracestate->s) {
    smart_str_appendc(tracestate, ',');
  }
  smart_str_appends(tracestate, "ot=");
  smart_str_append(tracestate, value.s);
  smart_str_free(&value);
}

static ddtrace_otel_tracestate_members ddtrace_otel_scan_tracestate(zend_string* tracestate) {
  ddtrace_otel_tracestate_members members = {0};
  const char* raw = ZSTR_VAL(tracestate);
  const char* end = raw + ZSTR_LEN(tracestate);

  for (const char* member = raw; member < end;) {
    const char* member_end = memchr(member, ',', end - member);
    if (!member_end) {
      member_end = end;
    }
    size_t member_len = member_end - member;
    ++members.member_count;

    if (!members.datadog_member && ddtrace_tracestate_member_is(member, member_len, "dd")) {
      members.datadog_member = member;
      members.datadog_member_len = member_len;
    } else if (!members.otel_member && ddtrace_tracestate_member_is(member, member_len, "ot")) {
      members.otel_member = member;
      members.otel_member_len = member_len;
    }

    member = member_end == end ? end : member_end + 1;
  }

  return members;
}

static bool ddtrace_otel_append_member(smart_str* result, const char* member, size_t member_len, size_t* member_count) {
  size_t separator_len = result->s ? 1 : 0;
  if (*member_count >= DDTRACE_TRACESTATE_MAX_MEMBERS ||
      (result->s ? ZSTR_LEN(result->s) : 0) + separator_len + member_len > DDTRACE_TRACESTATE_MAX_LEN) {
    return false;
  }
  if (separator_len) {
    smart_str_appendc(result, ',');
  }
  smart_str_appendl(result, member, member_len);
  ++*member_count;
  return true;
}

static size_t ddtrace_otel_datadog_member_prefix_len(const ddtrace_otel_tracestate_members* members) {
  size_t datadog_member_len = members->datadog_member_len;
  if (!members->otel_member) {
    return datadog_member_len;
  }

  size_t reserved_otel_len = members->otel_member_len + 1;
  if (reserved_otel_len >= DDTRACE_TRACESTATE_MAX_LEN) {
    return 0;
  }

  size_t max_datadog_len = DDTRACE_TRACESTATE_MAX_LEN - reserved_otel_len;
  if (datadog_member_len <= max_datadog_len) {
    return datadog_member_len;
  }

  while (max_datadog_len > 0 && members->datadog_member[max_datadog_len] != ';') {
    --max_datadog_len;
  }
  return max_datadog_len;
}

// Takes ownership of a tracestate which is known to exceed a W3C limit.
static zend_string* ddtrace_otel_limit_oversized_tracestate(zend_string* tracestate) {
  if (!tracestate) {
    return NULL;
  }

  const char* raw = ZSTR_VAL(tracestate);
  const char* end = raw + ZSTR_LEN(tracestate);
  ddtrace_otel_tracestate_members members = ddtrace_otel_scan_tracestate(tracestate);

  smart_str limited = {0};
  size_t member_count = 0;
  if (members.datadog_member) {
    size_t datadog_member_len = ddtrace_otel_datadog_member_prefix_len(&members);
    if (datadog_member_len) {
      ddtrace_otel_append_member(&limited, members.datadog_member, datadog_member_len, &member_count);
    }
  }
  if (members.otel_member) {
    ddtrace_otel_append_member(&limited, members.otel_member, members.otel_member_len, &member_count);
  }

  for (const char* member = raw; member < end;) {
    const char* member_end = memchr(member, ',', end - member);
    if (!member_end) {
      member_end = end;
    }
    size_t member_len = member_end - member;
    if (member != members.datadog_member && member != members.otel_member && member_len &&
        !ddtrace_otel_append_member(&limited, member, member_len, &member_count)) {
      break;
    }
    member = member_end == end ? end : member_end + 1;
  }

  zend_string_release(tracestate);
  if (limited.s) {
    smart_str_0(&limited);
  }
  return limited.s;
}

zend_string* ddtrace_otel_sampling_limit_tracestate(zend_string* tracestate) {
  if (!tracestate) {
    return NULL;
  }

  ddtrace_otel_tracestate_members members = ddtrace_otel_scan_tracestate(tracestate);
  if (members.member_count <= DDTRACE_TRACESTATE_MAX_MEMBERS && ZSTR_LEN(tracestate) <= DDTRACE_TRACESTATE_MAX_LEN) {
    return tracestate;
  }
  return ddtrace_otel_limit_oversized_tracestate(tracestate);
}
