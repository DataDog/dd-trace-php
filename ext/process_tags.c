#include "php.h"
#include <ctype.h>
#include <inttypes.h>
#include <stdbool.h>
#include <stdlib.h>
#include <string.h>
#include "process_tags.h"
#include "configuration.h"
#include "Zend/zend_smart_str.h"
#include "components-rs/ddtrace.h"
#include "SAPI.h"

#ifndef PATH_MAX
#define PATH_MAX 4096
#endif

#define TAG_ENTRYPOINT_NAME "entrypoint.name"
#define TAG_ENTRYPOINT_BASEDIR "entrypoint.basedir"
#define TAG_ENTRYPOINT_WORKDIR "entrypoint.workdir"
#define TAG_ENTRYPOINT_TYPE "entrypoint.type"
#define TAG_RUNTIME_SAPI "runtime.sapi"

#define TYPE_SCRIPT "script"
#define TYPE_EXECUTABLE "executable"

typedef struct {
    const char *key;
    const char *value;
} process_tag_entry_t;

typedef struct {
    process_tag_entry_t *tag_list;
    size_t count;
    size_t capacity;
    zend_string *serialized;
    zend_string *base_hash;
    zend_string *container_tags_hash;
    ddog_Vec_Tag vec;
} process_tags_t;

static process_tags_t process_tags = {0};

static void clear_process_tags(void) {
    for (size_t i = 0; i < process_tags.count; i++) {
        ddog_free_normalized_tag_value(process_tags.tag_list[i].value);
    }

    if (process_tags.tag_list) {
        pefree(process_tags.tag_list, 1);
    }

    if (process_tags.serialized) {
        zend_string_release(process_tags.serialized);
    }

    if (process_tags.vec.ptr) {
        ddog_Vec_Tag_drop(process_tags.vec);
    }

    if (process_tags.base_hash) {
        zend_string_release(process_tags.base_hash);
    }

    if (process_tags.container_tags_hash) {
        zend_string_release(process_tags.container_tags_hash);
    }

    memset(&process_tags, 0, sizeof(process_tags));
}

static inline const char *get_basename(const char *path) {
    if (!path || !*path) return NULL;

    const char *base = path;
    for (const char *p = path; *p; p++) {
        if ((*p == '/' || *p == '\\') && p[1] != '\0') {
            base = p + 1;
        }
    }
    return base;
}

static void get_basedir(const char* script_path, char *out, size_t out_size) {
    if (!script_path || !*script_path || !out || out_size == 0) {
        out[0] = '\0';
        return;
    }

    const char *last_sep = NULL;
    for (const char *p = script_path; *p; p++) {
        if (*p == '/' || *p == '\\') last_sep = p;
    }

    if (!last_sep) {
        out[0] = '\0';
        return;
    }

    const char *prev_sep = NULL;
    for (const char *p = script_path; p < last_sep; p++) {
        if (*p == '/' || *p == '\\') prev_sep = p;
    }

    const char *start = prev_sep ? prev_sep + 1 : script_path;
    size_t len = last_sep - start;

    if (len >= out_size) len = out_size - 1;
    memcpy(out, start, len);
    out[len] = '\0';
}

static void strip_extension(const char *filename, char *out, size_t out_size) {
    const char *dot = strrchr(filename, '.');
    size_t len = dot && dot != filename ? (size_t)(dot - filename)
                                        : strlen(filename);

    if (len >= out_size) len = out_size - 1;
    memcpy(out, filename, len);
    out[len] = '\0';
}

static void add_process_tag(const char* tag_key, const char* tag_value) {
    if (!tag_key || !tag_value) {
        return;
    }

    const char* normalized_value = ddog_normalize_process_tag_value((ddog_CharSlice){
        .ptr = tag_value,
        .len = strlen(tag_value)
    });
    if (!normalized_value) {
        return;
    }

    size_t count = process_tags.count;
    if (count == process_tags.capacity) {
        process_tags.capacity *= 2;
        process_tags.tag_list = perealloc(
            process_tags.tag_list,
            process_tags.capacity * sizeof(process_tag_entry_t),
            1
        );
    }

    process_tags.tag_list[count].key = tag_key;
    process_tags.tag_list[count].value = normalized_value;
    process_tags.count++;

}

static void collect_process_tags(void) {
    bool is_cli = (strcmp(sapi_module.name, "cli") == 0 || strcmp(sapi_module.name, "phpdbg") == 0);

    char cwd[PATH_MAX];
    if (VCWD_GETCWD(cwd, sizeof(cwd))) {
        const char* entrypoint_workdir = get_basename(cwd);
        if (entrypoint_workdir) {
            add_process_tag(TAG_ENTRYPOINT_WORKDIR, entrypoint_workdir);
        }
    }

    add_process_tag(TAG_RUNTIME_SAPI, sapi_module.name);

    const char *script = NULL;
    if (SG(request_info).path_translated && *SG(request_info).path_translated) {
        script = SG(request_info).path_translated;
    } else if (SG(request_info).argv && SG(request_info).argc > 0 && SG(request_info).argv[0]) {
        script = SG(request_info).argv[0];
    }

    const char *entrypoint_name = get_basename(script);
    if (entrypoint_name) {
        if (is_cli) {
            char name_without_ext[PATH_MAX];
            strip_extension(entrypoint_name, name_without_ext, sizeof(name_without_ext));
            add_process_tag(TAG_ENTRYPOINT_NAME, name_without_ext);
        }
        add_process_tag(TAG_ENTRYPOINT_TYPE, TYPE_SCRIPT);
    } else {
        add_process_tag(TAG_ENTRYPOINT_NAME, "php");
        add_process_tag(TAG_ENTRYPOINT_TYPE, TYPE_EXECUTABLE);
    }

    if (is_cli) {
        char basedir_buffer[PATH_MAX];
        get_basedir(script, basedir_buffer, sizeof(basedir_buffer));
        const char *base_dir = basedir_buffer[0] ? basedir_buffer : NULL;

        if (base_dir) {
            add_process_tag(TAG_ENTRYPOINT_BASEDIR, base_dir);
        }
    }
}

static int cmp_process_tag_by_key(const void *tag1, const void* tag2) {
    const process_tag_entry_t *tag_entry_1 = tag1;
    const process_tag_entry_t *tag_entry_2 = tag2;

    return strcmp(tag_entry_1->key, tag_entry_2->key);
}

static void recompute_base_hash(void) {
    if (!ddtrace_process_tags_enabled() || !process_tags.serialized) {
        return;
    }

    if (process_tags.base_hash) {
        zend_string_release(process_tags.base_hash);
        process_tags.base_hash = NULL;
    }

    uint64_t hash_value;
    if (process_tags.container_tags_hash) {
        size_t total_len = ZSTR_LEN(process_tags.serialized) + ZSTR_LEN(process_tags.container_tags_hash);
        unsigned char *combined = emalloc(total_len);

        memcpy(combined, ZSTR_VAL(process_tags.serialized), ZSTR_LEN(process_tags.serialized));
        memcpy(combined + ZSTR_LEN(process_tags.serialized), ZSTR_VAL(process_tags.container_tags_hash), ZSTR_LEN(process_tags.container_tags_hash));

        hash_value = dd_fnv1a_64(combined, total_len);
        efree(combined);
    } else {
        hash_value = dd_fnv1a_64((const uint8_t *)ZSTR_VAL(process_tags.serialized), ZSTR_LEN(process_tags.serialized));
    }

    smart_str hash_buf = {0};
    smart_str_alloc(&hash_buf, 21, 1);
    smart_str_append_printf(&hash_buf, "%" PRIu64, hash_value);
    smart_str_0(&hash_buf);
    process_tags.base_hash = hash_buf.s;
}

static void serialize_process_tags(void) {
    if (!ddtrace_process_tags_enabled() || !process_tags.count) {
        return;
    }

    // sort process_tags by key alphabetical order
    qsort(process_tags.tag_list, process_tags.count, sizeof(process_tag_entry_t), cmp_process_tag_by_key);

    smart_str buf = {0};
    for (size_t i = 0; i < process_tags.count; i++) {
        smart_str_appends(&buf, process_tags.tag_list[i].key);
        smart_str_appendc(&buf, ':');
        smart_str_appends(&buf, process_tags.tag_list[i].value);
        if (i < process_tags.count - 1) {
            smart_str_appendc(&buf, ',');
        }
    }
    if (buf.s) {
        smart_str_0(&buf);
        process_tags.serialized = zend_string_init(ZSTR_VAL(buf.s), ZSTR_LEN(buf.s), 1);
    }

    smart_str_free(&buf);

    process_tags.vec = ddog_Vec_Tag_new();
    for (size_t i = 0; i < process_tags.count; i++) {
        const char* key = process_tags.tag_list[i].key;
        const char* value = process_tags.tag_list[i].value;

        UNUSED(ddog_Vec_Tag_push(&process_tags.vec,
            (ddog_CharSlice) {.ptr = key, .len = strlen(key)},
            (ddog_CharSlice) {.ptr = value, .len = strlen(value)}
        ));
    }

    recompute_base_hash();
}

static void init_process_tags(void) {
    process_tags.capacity = 4;
    process_tags.tag_list = pemalloc(process_tags.capacity * sizeof(process_tag_entry_t), 1);
    if (!process_tags.tag_list) {
        process_tags.capacity = 0;
        return;
    }

    collect_process_tags();
    serialize_process_tags();
}

void ddtrace_process_tags_set_container_tags_hash(zend_string *container_tags_hash) {
    if (!container_tags_hash || !ddtrace_process_tags_enabled()) {
        return;
    }

    if (process_tags.container_tags_hash) {
        zend_string_release(process_tags.container_tags_hash);
    }
    process_tags.container_tags_hash = zend_string_copy(container_tags_hash);

    recompute_base_hash();
}

zend_string *ddtrace_process_tags_get_serialized(void) {
    return (ddtrace_process_tags_enabled() && process_tags.serialized) ? process_tags.serialized : ZSTR_EMPTY_ALLOC();
}

const ddog_Vec_Tag *ddtrace_process_tags_get_vec(void) {
    if (ddtrace_process_tags_enabled() && process_tags.vec.ptr) {
        return &process_tags.vec;
    }

    static ddog_Vec_Tag empty_vec;
    if (!empty_vec.ptr) {
        empty_vec = ddog_Vec_Tag_new();
    }
    return &empty_vec;
}

zend_string *ddtrace_process_tags_get_base_hash(void) {
    return (ddtrace_process_tags_enabled() && process_tags.base_hash) ? process_tags.base_hash : NULL;
}

bool ddtrace_process_tags_enabled(void){
    return get_DD_EXPERIMENTAL_PROPAGATE_PROCESS_TAGS_ENABLED();
}

void ddtrace_process_tags_first_rinit(void) {
    init_process_tags();
}

void ddtrace_process_tags_reload(void) {
    clear_process_tags();
    init_process_tags();
}
void ddtrace_process_tags_mshutdown(void) {
    clear_process_tags();
}
