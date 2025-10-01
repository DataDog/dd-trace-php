#include "process_tags.h"
#include "configuration.h"
#include "ddtrace.h"
#include <SAPI.h>
#include <ctype.h>
#include <string.h>
#include <stdlib.h>

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

// Tag name constants
#define TAG_ENTRYPOINT_NAME "entrypoint.name"
#define TAG_ENTRYPOINT_BASEDIR "entrypoint.basedir"
#define TAG_ENTRYPOINT_WORKDIR "entrypoint.workdir"
#define TAG_ENTRYPOINT_TYPE "entrypoint.type"

// Entrypoint type constants
#define TYPE_SCRIPT "script"
#define TYPE_CLI "cli"
#define TYPE_EXECUTABLE "executable"

// Maximum number of process tags
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

/**
 * Normalize a tag value according to RFC specifications:
 * - Convert to lowercase
 * - Allow only: a-z, 0-9, /, ., -
 * - Replace everything else with _
 * 
 * @param value The value to normalize
 * @return A newly allocated normalized string (caller must free)
 */
static char *normalize_value(const char *value) {
    if (!value || !*value) {
        return NULL;
    }

    size_t len = strlen(value);
    char *normalized = (char *)malloc(len + 1);
    if (!normalized) {
        return NULL;
    }

    for (size_t i = 0; i < len; i++) {
        char c = value[i];
        
        // Convert to lowercase
        if (c >= 'A' && c <= 'Z') {
            normalized[i] = c + ('a' - 'A');
        }
        // Allow: a-z, 0-9, /, ., -
        else if ((c >= 'a' && c <= 'z') ||
                 (c >= '0' && c <= '9') ||
                 c == '/' || c == '.' || c == '-') {
            normalized[i] = c;
        }
        // Replace everything else with _
        else {
            normalized[i] = '_';
        }
    }
    normalized[len] = '\0';
    
    return normalized;
}

/**
 * Get the base name (last path segment) from a path
 * 
 * @param path The full path
 * @return A newly allocated string with the base name (caller must free)
 */
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
    
    // If empty after slash, return NULL
    if (!*basename) {
        return NULL;
    }

    return strdup(basename);
}

/**
 * Get the directory name (parent directory) from a path
 * 
 * @param path The full path
 * @return A newly allocated string with the directory name (caller must free)
 */
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

    if (last_slash) {
        *last_slash = '\0';
        
        // Get the basename of the directory
        char *basedir = get_basename(path_copy);
        free(path_copy);
        return basedir;
    }

    free(path_copy);
    return strdup(".");
}

/**
 * Add a process tag entry
 * 
 * @param key The tag key (will be duplicated)
 * @param value The tag value (will be normalized and duplicated)
 */
static void add_process_tag(const char *key, const char *value) {
    if (!key || !value || process_tags.count >= MAX_PROCESS_TAGS) {
        return;
    }

    char *normalized_value = normalize_value(value);
    if (!normalized_value) {
        return;
    }

    process_tags.entries[process_tags.count].key = strdup(key);
    process_tags.entries[process_tags.count].value = normalized_value;
    
    if (process_tags.entries[process_tags.count].key) {
        process_tags.count++;
    } else {
        free(normalized_value);
    }
}

/**
 * Comparison function for qsort to sort process tags by key
 */
static int compare_tags(const void *a, const void *b) {
    const process_tag_entry_t *tag_a = (const process_tag_entry_t *)a;
    const process_tag_entry_t *tag_b = (const process_tag_entry_t *)b;
    return strcmp(tag_a->key, tag_b->key);
}

/**
 * Serialize process tags into a comma-separated string
 * Format: key1:value1,key2:value2,...
 * Keys are sorted alphabetically
 */
static void serialize_process_tags(void) {
    if (process_tags.count == 0) {
        return;
    }

    // Sort tags by key
    qsort(process_tags.entries, process_tags.count, sizeof(process_tag_entry_t), compare_tags);

    // Calculate total length needed
    size_t total_len = 0;
    for (size_t i = 0; i < process_tags.count; i++) {
        total_len += strlen(process_tags.entries[i].key);
        total_len += 1; // for ':'
        total_len += strlen(process_tags.entries[i].value);
        if (i < process_tags.count - 1) {
            total_len += 1; // for ','
        }
    }

    // Allocate and build the serialized string
    char *buffer = (char *)malloc(total_len + 1);
    if (!buffer) {
        return;
    }

    char *ptr = buffer;
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

    // Create a persistent zend_string
    process_tags.serialized = zend_string_init(buffer, total_len, 1);
    free(buffer);
}

/**
 * Collect process tags from the environment
 */
static void collect_process_tags(void) {
    const char *entrypoint_type = NULL;
    char *entrypoint_name = NULL;
    char *entrypoint_basedir = NULL;
    char *entrypoint_workdir = NULL;

    // Determine entrypoint information based on SAPI
    // For consistency, always use the executable path at MINIT time
    if (strcmp(sapi_module.name, "cli") == 0 || strcmp(sapi_module.name, "phpdbg") == 0) {
        entrypoint_type = TYPE_CLI;
    } else {
        entrypoint_type = TYPE_EXECUTABLE;
    }

    // Try to get executable path
#ifdef _WIN32
    char exe_path[PATH_MAX];
    DWORD len = GetModuleFileNameA(NULL, exe_path, PATH_MAX);
    if (len > 0 && len < PATH_MAX) {
        entrypoint_name = get_basename(exe_path);
        entrypoint_basedir = get_dirname(exe_path);
    }
#else
    char exe_path[PATH_MAX];
    ssize_t len = readlink("/proc/self/exe", exe_path, sizeof(exe_path) - 1);
    if (len != -1) {
        exe_path[len] = '\0';
        entrypoint_name = get_basename(exe_path);
        entrypoint_basedir = get_dirname(exe_path);
    } else {
        // Fallback: use argv[0] or executable_location if available
        if (sapi_module.executable_location) {
            entrypoint_name = get_basename(sapi_module.executable_location);
            entrypoint_basedir = get_dirname(sapi_module.executable_location);
        }
    }
#endif

    // If we still don't have entrypoint info, set type to executable
    if (!entrypoint_name && sapi_module.executable_location) {
        entrypoint_name = get_basename(sapi_module.executable_location);
        entrypoint_basedir = get_dirname(sapi_module.executable_location);
    }

    // Get current working directory
    char cwd[PATH_MAX];
    if (getcwd(cwd, sizeof(cwd))) {
        entrypoint_workdir = get_basename(cwd);
    }

    // Add tags in the order specified in RFC
    if (entrypoint_basedir) {
        add_process_tag(TAG_ENTRYPOINT_BASEDIR, entrypoint_basedir);
    }
    if (entrypoint_name) {
        add_process_tag(TAG_ENTRYPOINT_NAME, entrypoint_name);
    }
    if (entrypoint_type) {
        add_process_tag(TAG_ENTRYPOINT_TYPE, entrypoint_type);
    }
    if (entrypoint_workdir) {
        add_process_tag(TAG_ENTRYPOINT_WORKDIR, entrypoint_workdir);
    }

    // Clean up temporary strings
    free(entrypoint_name);
    free(entrypoint_basedir);
    free(entrypoint_workdir);

    // Serialize the collected tags
    serialize_process_tags();
}

void ddtrace_process_tags_minit(void) {
    // Initialize the process_tags structure
    memset(&process_tags, 0, sizeof(process_tags));
    
    // Only collect if enabled
    if (ddtrace_process_tags_enabled()) {
        collect_process_tags();
    }
}

void ddtrace_process_tags_mshutdown(void) {
    // Free all allocated memory
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
    if (!ddtrace_process_tags_enabled() || !process_tags.serialized) {
        return NULL;
    }
    
    return process_tags.serialized;
}

