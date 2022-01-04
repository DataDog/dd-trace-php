#include "zai_sapi_ini.h"

#include <stdio.h>
#include <stdlib.h>
#include <string.h>

ssize_t zai_sapi_ini_entries_alloc(const char *src, char **dest) {
    if (!src || !dest) return -1;

    /* Prevent a potential dangling pointer in case the caller accidentally
     * sent in an allocated dest.
     */
    if (*dest != NULL) return -1;

    size_t len = strlen(src);

    if ((*dest = (char *)malloc(len + 1)) == NULL) return -1;
    memcpy(*dest, src, (len + 1));

    return len;
}

void zai_sapi_ini_entries_free(char **entries) {
    if (entries != NULL && *entries != NULL) {
        free(*entries);
        *entries = NULL;
    }
}

ssize_t zai_sapi_ini_entries_realloc_append(char **entries, size_t entries_len, const char *key, const char *value) {
    if (!entries || !*entries || !key || !value) return -1;

    /* An empty 'value' is fine, but a 'key' must be non-empty. */
    if (*key == '\0') return -1;

    size_t append_len = strlen(key) + strlen(value) + (sizeof("=\n") - 1);

    char *newents;
    if ((newents = (char *)realloc(*entries, (entries_len + append_len + 1))) == NULL) return -1;
    *entries = newents;

    char *ptr = (*entries + entries_len);
    size_t written_len = (size_t)snprintf(ptr, (append_len + 1), "%s=%s\n", key, value);

    return (written_len != append_len) ? -1 : (ssize_t)(entries_len + append_len);
}

bool zai_sapi_php_ini_ignore(void) {
    const char *env = getenv("ZAI_SAPI_PHP_INI_IGNORE");
    if (env) {
        size_t len = strlen(env);
        if (len == 1) return *env != '0';
        if (len == 2) return strcasecmp("no", env) != 0;
        if (len == 3) return strcasecmp("off", env) != 0;
        if (len == 4) return strcasecmp("false", env) != 0;
    }
    return true;
}
