#include "php.h"
#include <ctype.h>
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
} process_tags_t;

static process_tags_t process_tags = {0};

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
}

DDTRACE_PUBLIC zend_string *ddtrace_process_tags_get_serialized(void) {
    return (ddtrace_process_tags_enabled() && process_tags.serialized) ? process_tags.serialized : NULL;
}

bool ddtrace_process_tags_enabled(void){
    return get_global_DD_EXPERIMENTAL_PROPAGATE_PROCESS_TAGS_ENABLED();
}

void ddtrace_process_tags_first_rinit(void) {
    // process_tags struct initializations
    process_tags.count = 0;
    process_tags.capacity = 4;
    process_tags.tag_list = pemalloc(process_tags.capacity * sizeof(process_tag_entry_t), 1);

    if (!process_tags.tag_list) {
        process_tags.capacity = 0;
        return;
    }

    collect_process_tags();
    serialize_process_tags();
}

void ddtrace_process_tags_mshutdown(void) {
    for (size_t i = 0; i < process_tags.count; i++) {
        ddog_free_normalized_tag_value(process_tags.tag_list[i].value);
    }
    pefree(process_tags.tag_list, 1);

    if (process_tags.serialized) {
        zend_string_release(process_tags.serialized);
    }
    memset(&process_tags, 0, sizeof(process_tags));
}
