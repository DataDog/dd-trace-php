#include "otel_sampling.h"

#include <Zend/zend_smart_str.h>
#include <math.h>

#define DDTRACE_OTEL_MAX_VALUE_LEN 256
#define DDTRACE_TRACESTATE_MAX_LEN 512
#define DDTRACE_TRACESTATE_MAX_MEMBERS 32
#define DDTRACE_OTEL_MAX_THRESHOLD (UINT64_C(1) << 56)
#define DDTRACE_OTEL_MAX_ENCODABLE_THRESHOLD (DDTRACE_OTEL_MAX_THRESHOLD - 1)

static const uint64_t DDTRACE_OTEL_KNUTH_FACTOR = UINT64_C(1111111111111111111);

typedef struct {
  const char* random_value;
  size_t random_value_len;
  const char* threshold;
  size_t threshold_len;
} ddtrace_otel_fields;

typedef struct {
  const char* datadog_member;
  size_t datadog_member_len;
  const char* otel_member;
  size_t otel_member_len;
  const char* otel_value;
  size_t otel_value_len;
  size_t member_count;
} ddtrace_otel_tracestate_members;

typedef struct {
  size_t offset;
  bool comma_before;
  bool comma_after;
} ddtrace_otel_insertion;

static bool ddtrace_otel_is_lower_hex(const char* value, size_t len) {
  for (size_t i = 0; i < len; ++i) {
    if (!((value[i] >= '0' && value[i] <= '9') ||
          (value[i] >= 'a' && value[i] <= 'f'))) {
      return false;
    }
  }
  return true;
}

static bool ddtrace_otel_field_is(const char* field, size_t field_len,
                                  const char* key) {
  return field_len >= 2 && field[0] == key[0] && field[1] == key[1] &&
         (field_len == 2 || field[2] == ':');
}

static ddtrace_otel_fields ddtrace_otel_parse_fields(const char* value,
                                                     size_t value_len) {
  ddtrace_otel_fields fields = {0};
  const char* end = value + value_len;

  for (const char* field = value; field <= end;) {
    const char* field_end = memchr(field, ';', end - field);
    if (!field_end) {
      field_end = end;
    }
    size_t field_len = field_end - field;

    if (ddtrace_otel_field_is(field, field_len, "rv")) {
      fields.random_value = field_len > 2 && field[2] == ':' ? field + 3 : NULL;
      fields.random_value_len = fields.random_value ? field_len - 3 : 0;
    } else if (ddtrace_otel_field_is(field, field_len, "th")) {
      fields.threshold = field_len > 2 && field[2] == ':' ? field + 3 : NULL;
      fields.threshold_len = fields.threshold ? field_len - 3 : 0;
    }

    if (field_end == end) {
      break;
    }
    field = field_end + 1;
  }

  if (fields.random_value_len != 14 ||
      !ddtrace_otel_is_lower_hex(fields.random_value,
                                 fields.random_value_len)) {
    fields.random_value = NULL;
    fields.random_value_len = 0;
  }
  if (fields.threshold_len < 1 || fields.threshold_len > 14 ||
      !ddtrace_otel_is_lower_hex(fields.threshold, fields.threshold_len)) {
    fields.threshold = NULL;
    fields.threshold_len = 0;
  }

  return fields;
}

static void ddtrace_otel_append_field(smart_str* result, const char* field,
                                      size_t field_len) {
  size_t separator_len = result->s ? 1 : 0;
  if ((result->s ? ZSTR_LEN(result->s) : 0) + separator_len + field_len >
      DDTRACE_OTEL_MAX_VALUE_LEN) {
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

  double threshold =
      round((1 - sample_rate) * (double)DDTRACE_OTEL_MAX_THRESHOLD);
  if (threshold <= 0) {
    return 0;
  }
  if (threshold >= (double)DDTRACE_OTEL_MAX_THRESHOLD) {
    return DDTRACE_OTEL_MAX_ENCODABLE_THRESHOLD;
  }
  return (uint64_t)threshold;
}

static void ddtrace_otel_encode_56_bit_hex(uint64_t value, char output[14]) {
  static const char hex_digits[] = "0123456789abcdef";
  for (size_t i = 14; i > 0; --i) {
    output[i - 1] = hex_digits[value & 0xf];
    value >>= 4;
  }
}

static uint64_t ddtrace_otel_derive_random_value(uint64_t trace_id) {
  return ~(trace_id * DDTRACE_OTEL_KNUTH_FACTOR) >> 8;
}

static uint64_t ddtrace_otel_reconcile_random_value(uint64_t random_value,
                                                    uint64_t threshold,
                                                    bool kept) {
  if (kept && random_value < threshold) {
    return threshold;
  }
  if (!kept && random_value >= threshold) {
    return threshold > 0 ? threshold - 1 : 0;
  }
  return random_value;
}

static void ddtrace_otel_generate_fields(ddtrace_otel_fields* fields,
                                         char random_value[14],
                                         char threshold[14], uint64_t trace_id,
                                         zend_long sampling_priority,
                                         double sample_rate) {
  uint64_t threshold_value = ddtrace_otel_threshold_for(sample_rate);
  uint64_t random_value_int = ddtrace_otel_reconcile_random_value(
      ddtrace_otel_derive_random_value(trace_id), threshold_value,
      sampling_priority > 0);

  ddtrace_otel_encode_56_bit_hex(random_value_int, random_value);
  ddtrace_otel_encode_56_bit_hex(threshold_value, threshold);

  size_t threshold_len = 14;
  while (threshold_len > 1 && threshold[threshold_len - 1] == '0') {
    --threshold_len;
  }
  fields->random_value = random_value;
  fields->random_value_len = 14;
  fields->threshold = threshold;
  fields->threshold_len = threshold_len;
}

static zend_string* ddtrace_otel_rebuild_value(
    const char* otel_value, size_t otel_value_len, uint64_t trace_id,
    zend_long sampling_priority, enum ddtrace_otel_sampling_decision decision,
    double sample_rate) {
  ddtrace_otel_fields fields =
      ddtrace_otel_parse_fields(otel_value, otel_value_len);
  char generated_random_value[14];
  char generated_threshold[14];

  if (decision == DDTRACE_OTEL_SAMPLING_DECISION_NON_PROBABILITY) {
    fields.threshold = NULL;
    fields.threshold_len = 0;
  } else if (decision == DDTRACE_OTEL_SAMPLING_DECISION_PROBABILITY &&
             sample_rate > 0) {
    ddtrace_otel_generate_fields(&fields, generated_random_value,
                                 generated_threshold, trace_id,
                                 sampling_priority, sample_rate);
  }

  smart_str result = {0};
  if (fields.random_value) {
    smart_str_appends(&result, "rv:");
    smart_str_appendl(&result, fields.random_value, fields.random_value_len);
  }
  if (fields.threshold) {
    if (result.s) {
      smart_str_appendc(&result, ';');
    }
    smart_str_appends(&result, "th:");
    smart_str_appendl(&result, fields.threshold, fields.threshold_len);
  }

  const char* end = otel_value + otel_value_len;
  for (const char* field = otel_value; field <= end;) {
    const char* field_end = memchr(field, ';', end - field);
    if (!field_end) {
      field_end = end;
    }
    size_t field_len = field_end - field;

    if (field_len && !ddtrace_otel_field_is(field, field_len, "rv") &&
        !ddtrace_otel_field_is(field, field_len, "th")) {
      ddtrace_otel_append_field(&result, field, field_len);
    }

    if (field_end == end) {
      break;
    }
    field = field_end + 1;
  }

  if (result.s) {
    smart_str_0(&result);
  }
  return result.s;
}

static bool ddtrace_otel_is_member(const char* member, size_t member_len,
                                   const char** value, size_t* value_len) {
  while (member_len && (*member == ' ' || *member == '\t')) {
    ++member;
    --member_len;
  }
  while (member_len &&
         (member[member_len - 1] == ' ' || member[member_len - 1] == '\t')) {
    --member_len;
  }

  if (member_len < 3 || memcmp(member, "ot=", 3) != 0) {
    return false;
  }

  *value = member + 3;
  *value_len = member_len - 3;
  return true;
}

static bool ddtrace_tracestate_member_is(const char* member, size_t member_len,
                                         const char* key) {
  while (member_len && (*member == ' ' || *member == '\t')) {
    ++member;
    --member_len;
  }
  return member_len >= 3 && member[0] == key[0] && member[1] == key[1] &&
         member[2] == '=';
}

static ddtrace_otel_tracestate_members ddtrace_otel_scan_tracestate(
    zend_string* tracestate) {
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

    if (!members.datadog_member &&
        ddtrace_tracestate_member_is(member, member_len, "dd")) {
      members.datadog_member = member;
      members.datadog_member_len = member_len;
    } else if (!members.otel_member &&
               ddtrace_otel_is_member(member, member_len, &members.otel_value,
                                      &members.otel_value_len)) {
      members.otel_member = member;
      members.otel_member_len = member_len;
    }

    member = member_end == end ? end : member_end + 1;
  }

  return members;
}

static bool ddtrace_otel_append_member(smart_str* result, const char* member,
                                       size_t member_len,
                                       size_t* member_count) {
  size_t separator_len = result->s ? 1 : 0;
  if (*member_count >= DDTRACE_TRACESTATE_MAX_MEMBERS ||
      (result->s ? ZSTR_LEN(result->s) : 0) + separator_len + member_len >
          DDTRACE_TRACESTATE_MAX_LEN) {
    return false;
  }
  if (separator_len) {
    smart_str_appendc(result, ',');
  }
  smart_str_appendl(result, member, member_len);
  ++*member_count;
  return true;
}

static size_t ddtrace_otel_datadog_member_prefix_len(
    const ddtrace_otel_tracestate_members* members) {
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

  while (max_datadog_len > 0 &&
         members->datadog_member[max_datadog_len] != ';') {
    --max_datadog_len;
  }
  return max_datadog_len;
}

static void ddtrace_otel_append_value_member(smart_str* result,
                                             const char key[2],
                                             zend_string* value) {
  if (result->s) {
    smart_str_appendc(result, ',');
  }
  smart_str_appendl(result, key, 2);
  smart_str_appendc(result, '=');
  smart_str_append(result, value);
}

// Takes ownership of a tracestate which is known to exceed a W3C limit.
static zend_string* ddtrace_otel_limit_oversized_tracestate(
    zend_string* tracestate) {
  if (!tracestate) {
    return NULL;
  }

  const char* raw = ZSTR_VAL(tracestate);
  const char* end = raw + ZSTR_LEN(tracestate);
  ddtrace_otel_tracestate_members members =
      ddtrace_otel_scan_tracestate(tracestate);

  smart_str limited = {0};
  size_t member_count = 0;
  if (members.datadog_member) {
    size_t datadog_member_len =
        ddtrace_otel_datadog_member_prefix_len(&members);
    if (datadog_member_len) {
      ddtrace_otel_append_member(&limited, members.datadog_member,
                                 datadog_member_len, &member_count);
    }
  }
  if (members.otel_member) {
    ddtrace_otel_append_member(&limited, members.otel_member,
                               members.otel_member_len, &member_count);
  }

  for (const char* member = raw; member < end;) {
    const char* member_end = memchr(member, ',', end - member);
    if (!member_end) {
      member_end = end;
    }
    size_t member_len = member_end - member;
    if (member != members.datadog_member && member != members.otel_member &&
        member_len &&
        !ddtrace_otel_append_member(&limited, member, member_len,
                                    &member_count)) {
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

static char* ddtrace_otel_write_generated_member(
    char* output, const ddtrace_otel_fields* fields) {
  memcpy(output, "ot=rv:", 6);
  output += 6;
  memcpy(output, fields->random_value, fields->random_value_len);
  output += fields->random_value_len;
  memcpy(output, ";th:", 4);
  output += 4;
  memcpy(output, fields->threshold, fields->threshold_len);
  return output + fields->threshold_len;
}

static ddtrace_otel_insertion ddtrace_otel_generated_member_insertion(
    zend_string* tracestate) {
  ddtrace_otel_insertion insertion = {0};
  size_t raw_len = tracestate ? ZSTR_LEN(tracestate) : 0;
  if (!raw_len) {
    return insertion;
  }

  const char* first_comma = memchr(ZSTR_VAL(tracestate), ',', raw_len);
  size_t first_member_len =
      first_comma ? (size_t)(first_comma - ZSTR_VAL(tracestate)) : raw_len;
  if (!ddtrace_tracestate_member_is(ZSTR_VAL(tracestate), first_member_len,
                                    "dd")) {
    insertion.comma_after = true;
  } else if (first_comma) {
    insertion.offset = first_member_len + 1;
    insertion.comma_after = true;
  } else {
    insertion.offset = raw_len;
    insertion.comma_before = true;
  }

  return insertion;
}

// Takes ownership of tracestate and inserts the generated member in-place.
static zend_string* ddtrace_otel_insert_generated_member(
    zend_string* tracestate, uint64_t trace_id, zend_long sampling_priority,
    double sample_rate, size_t member_count) {
  ddtrace_otel_fields fields = {0};
  char random_value[14];
  char threshold[14];
  ddtrace_otel_generate_fields(&fields, random_value, threshold, trace_id,
                               sampling_priority, sample_rate);

  size_t raw_len = tracestate ? ZSTR_LEN(tracestate) : 0;
  ddtrace_otel_insertion insertion =
      ddtrace_otel_generated_member_insertion(tracestate);
  size_t otel_member_len =
      3 + 3 + fields.random_value_len + 1 + 3 + fields.threshold_len;
  size_t insertion_len =
      otel_member_len + insertion.comma_before + insertion.comma_after;
  size_t result_len = raw_len + insertion_len;
  zend_string* result = tracestate
                            ? zend_string_extend(tracestate, result_len, 0)
                            : zend_string_alloc(result_len, 0);
  char* raw = ZSTR_VAL(result);
  memmove(raw + insertion.offset + insertion_len, raw + insertion.offset,
          raw_len - insertion.offset);

  char* output = raw + insertion.offset;
  if (insertion.comma_before) {
    *output++ = ',';
  }
  output = ddtrace_otel_write_generated_member(output, &fields);
  if (insertion.comma_after) {
    *output++ = ',';
  }
  ZEND_ASSERT(output == raw + insertion.offset + insertion_len);
  ZSTR_VAL(result)[result_len] = '\0';

  if (member_count + 1 <= DDTRACE_TRACESTATE_MAX_MEMBERS &&
      ZSTR_LEN(result) <= DDTRACE_TRACESTATE_MAX_LEN) {
    return result;
  }
  return ddtrace_otel_limit_oversized_tracestate(result);
}

// Takes ownership of tracestate and rewrites the first ot member. Any duplicate
// ot members are dropped while the remaining member order is preserved.
static zend_string* ddtrace_otel_rewrite_existing_member(
    zend_string* tracestate, const ddtrace_otel_tracestate_members* members,
    uint64_t trace_id, zend_long sampling_priority,
    enum ddtrace_otel_sampling_decision decision, double sample_rate) {
  zend_string* updated_value = ddtrace_otel_rebuild_value(
      members->otel_value, members->otel_value_len, trace_id, sampling_priority,
      decision, sample_rate);
  smart_str result = {0};
  size_t result_member_count = 0;
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

    if (ddtrace_otel_is_member(member, member_len, &otel_value,
                               &otel_value_len)) {
      if (!found_otel) {
        if (updated_value) {
          ddtrace_otel_append_value_member(&result, "ot", updated_value);
          ++result_member_count;
        }
        found_otel = true;
      }
    } else if (member_len) {
      if (result.s) {
        smart_str_appendc(&result, ',');
      }
      smart_str_appendl(&result, member, member_len);
      ++result_member_count;
    }

    member = member_end == end ? end : member_end + 1;
  }

  if (updated_value) {
    zend_string_release(updated_value);
  }
  zend_string_release(tracestate);
  if (result.s) {
    smart_str_0(&result);
  }
  if (result_member_count <= DDTRACE_TRACESTATE_MAX_MEMBERS &&
      (!result.s || ZSTR_LEN(result.s) <= DDTRACE_TRACESTATE_MAX_LEN)) {
    return result.s;
  }
  return ddtrace_otel_limit_oversized_tracestate(result.s);
}

zend_string* ddtrace_otel_sampling_update_tracestate(
    zend_string* tracestate, uint64_t trace_id, zend_long sampling_priority,
    enum ddtrace_otel_sampling_decision decision, double sample_rate) {
  bool should_generate =
      decision == DDTRACE_OTEL_SAMPLING_DECISION_PROBABILITY && sample_rate > 0;
  if (!tracestate || ZSTR_LEN(tracestate) == 0) {
    return should_generate
               ? ddtrace_otel_insert_generated_member(
                     tracestate, trace_id, sampling_priority, sample_rate, 0)
               : tracestate;
  }

  ddtrace_otel_tracestate_members members =
      ddtrace_otel_scan_tracestate(tracestate);
  if (!members.otel_member) {
    if (should_generate) {
      return ddtrace_otel_insert_generated_member(
          tracestate, trace_id, sampling_priority, sample_rate,
          members.member_count);
    }
    if (members.member_count <= DDTRACE_TRACESTATE_MAX_MEMBERS &&
        ZSTR_LEN(tracestate) <= DDTRACE_TRACESTATE_MAX_LEN) {
      return tracestate;
    }
    return ddtrace_otel_limit_oversized_tracestate(tracestate);
  }

  return ddtrace_otel_rewrite_existing_member(
      tracestate, &members, trace_id, sampling_priority, decision, sample_rate);
}
