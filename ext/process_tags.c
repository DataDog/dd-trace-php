#include "process_tags.h"
#include "configuration.h"
#include "ddtrace.h"
#include <SAPI.h>
#include <ctype.h>
#include <string.h>
#include <stdlib.h>
#include <stdbool.h>

#ifdef _WIN32
#include <windows.h>
#include <direct.h>
#define getcwd _getcwd
#define PATH_MAX _MAX_PATH
#else
#include <unistd.h>
#endif

#ifndef PATH_MAX
#define PATH_MAX 4096
#endif

#define TAG_ENTRYPOINT_NAME "entrypoint.name"
#define TAG_ENTRYPOINT_BASEDIR "entrypoint.basedir"
#define TAG_ENTRYPOINT_WORKDIR "entrypoint.workdir"
#define TAG_ENTRYPOINT_TYPE "entrypoint.type"
#define TAG_SERVER_TYPE "server.type"
#define TYPE_CLI "cli"
#define MAX_PROCESS_TAGS 10

typedef struct {
    char *key;
    char *value;
} process_tag_entry_t;

typedef struct {
    process_tag_entry_t entries[MAX_PROCESS_TAGS];
    size_t count;
    zend_string *serialized;
} process_tags_t;

static process_tags_t process_tags = {0};

// Normalize tag value per RFC: lowercase, allow [a-z0-9/.-], replace rest with _
static char *normalize_value(const char *value) {
    if (!value || !*value) {
        return NULL;
    }

    size_t len = strlen(value);
    char *normalized = malloc(len + 1);
    if (!normalized) {
        return NULL;
    }

    for (size_t i = 0; i < len; i++) {
        char c = value[i];
        if (c >= 'A' && c <= 'Z') {
            normalized[i] = c + ('a' - 'A');
        } else if ((c >= 'a' && c <= 'z') || (c >= '0' && c <= '9') || 
                   c == '/' || c == '.' || c == '-') {
            normalized[i] = c;
        } else {
            normalized[i] = '_';
        }
    }
    normalized[len] = '\0';
    return normalized;
}

static char *get_basename(const char *path) {
    if (!path || !*path) {
        return NULL;
    }

    const char *last_slash = strrchr(path, '/');
#ifdef _WIN32
    const char *last_backslash = strrchr(path, '\\');
    if (last_backslash && (!last_slash || last_backslash > last_slash)) {
        last_slash = last_backslash;
    }
#endif

    const char *basename = last_slash ? last_slash + 1 : path;
    return *basename ? strdup(basename) : NULL;
}

static char *get_dirname(const char *path) {
    if (!path || !*path) {
        return NULL;
    }

    char *path_copy = strdup(path);
    if (!path_copy) {
        return NULL;
    }

    char *last_slash = strrchr(path_copy, '/');
#ifdef _WIN32
    char *last_backslash = strrchr(path_copy, '\\');
    if (last_backslash && (!last_slash || last_backslash > last_slash)) {
        last_slash = last_backslash;
    }
#endif

    char *basedir;
    if (last_slash) {
        *last_slash = '\0';
        basedir = get_basename(path_copy);
    } else {
        basedir = strdup(".");
    }
    free(path_copy);
    return basedir;
}

static void add_process_tag(const char *key, const char *value) {
    if (!key || !value || process_tags.count >= MAX_PROCESS_TAGS) {
        return;
    }

    char *normalized_value = normalize_value(value);
    if (!normalized_value) {
        return;
    }

    char *key_copy = strdup(key);
    if (!key_copy) {
        free(normalized_value);
        return;
    }

    process_tags.entries[process_tags.count].key = key_copy;
    process_tags.entries[process_tags.count].value = normalized_value;
    process_tags.count++;
}

static int compare_tags(const void *a, const void *b) {
    return strcmp(((const process_tag_entry_t *)a)->key, ((const process_tag_entry_t *)b)->key);
}

// Serialize process tags as comma-separated key:value pairs, sorted by key
static void serialize_process_tags(void) {
    if (process_tags.count == 0) {
        return;
    }

    qsort(process_tags.entries, process_tags.count, sizeof(process_tag_entry_t), compare_tags);

    size_t total_len = 0;
    for (size_t i = 0; i < process_tags.count; i++) {
        total_len += strlen(process_tags.entries[i].key) + 1 + strlen(process_tags.entries[i].value);
        if (i < process_tags.count - 1) {
            total_len++; // comma separator
        }
    }

    process_tags.serialized = zend_string_alloc(total_len, 1); // persistent allocation
    char *ptr = ZSTR_VAL(process_tags.serialized);
    
    for (size_t i = 0; i < process_tags.count; i++) {
        size_t key_len = strlen(process_tags.entries[i].key);
        size_t value_len = strlen(process_tags.entries[i].value);
        
        memcpy(ptr, process_tags.entries[i].key, key_len);
        ptr += key_len;
        *ptr++ = ':';
        memcpy(ptr, process_tags.entries[i].value, value_len);
        ptr += value_len;
        if (i < process_tags.count - 1) {
            *ptr++ = ',';
        }
    }
    *ptr = '\0';
}

static void collect_process_tags(void) {
    bool is_cli = (strcmp(sapi_module.name, "cli") == 0 || strcmp(sapi_module.name, "phpdbg") == 0);
    char *entrypoint_name = NULL;
    char *entrypoint_basedir = NULL;
    char *entrypoint_workdir = NULL;

    if (is_cli) {
        // CLI: collect script information (not the PHP binary)
        if (SG(request_info).path_translated && *SG(request_info).path_translated) {
            entrypoint_name = get_basename(SG(request_info).path_translated);
            entrypoint_basedir = get_dirname(SG(request_info).path_translated);
        }
    } else {
        // Web SAPI: collect server type (different requests may execute different scripts)
        add_process_tag(TAG_SERVER_TYPE, sapi_module.name);
    }

    char cwd[PATH_MAX];
    if (getcwd(cwd, sizeof(cwd))) {
        entrypoint_workdir = get_basename(cwd);
    }

    if (entrypoint_basedir) {
        add_process_tag(TAG_ENTRYPOINT_BASEDIR, entrypoint_basedir);
    }
    if (entrypoint_name) {
        add_process_tag(TAG_ENTRYPOINT_NAME, entrypoint_name);
    }
    if (is_cli) {
        add_process_tag(TAG_ENTRYPOINT_TYPE, TYPE_CLI);
    }
    if (entrypoint_workdir) {
        add_process_tag(TAG_ENTRYPOINT_WORKDIR, entrypoint_workdir);
    }

    free(entrypoint_name);
    free(entrypoint_basedir);
    free(entrypoint_workdir);

    serialize_process_tags();
}

void ddtrace_process_tags_first_rinit(void) {
    if (ddtrace_process_tags_enabled() && !process_tags.serialized) {
        collect_process_tags();
    }
}

void ddtrace_process_tags_mshutdown(void) {
    for (size_t i = 0; i < process_tags.count; i++) {
        free(process_tags.entries[i].key);
        free(process_tags.entries[i].value);
    }
    if (process_tags.serialized) {
        zend_string_release(process_tags.serialized);
    }
    memset(&process_tags, 0, sizeof(process_tags));
}

bool ddtrace_process_tags_enabled(void) {
    return get_global_DD_EXPERIMENTAL_PROPAGATE_PROCESS_TAGS_ENABLED();
}

zend_string *ddtrace_process_tags_get_serialized(void) {
    return (ddtrace_process_tags_enabled() && process_tags.serialized) ? process_tags.serialized : NULL;
}

