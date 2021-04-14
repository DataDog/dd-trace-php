#include "zai_sapi_ini.h"

#include <stdio.h>
#include <stdlib.h>
#include <string.h>

size_t zai_sapi_ini_entries_alloc(const char *src, char **dest) {
    if (!src || !dest) return 0;

    /* Prevent a potential dangling pointer in case the caller accidentally
     * sent in an allocated dest.
     */
    if (*dest != NULL) return 0;

    size_t len = strlen(src);

    if ((*dest = (char *)malloc(len + 1)) == NULL) return 0;
    memcpy(*dest, src, (len + 1));

    return len;
}

void zai_sapi_ini_entries_free(char **entries) {
    if (entries != NULL && *entries != NULL) {
        free(*entries);
        *entries = NULL;
    }
}

size_t zai_sapi_ini_entries_realloc_append(char **entries, size_t entries_len, const char *key, const char *value) {
    if (!entries || !*entries || !key || !value || entries_len <= 0) return 0;

    size_t append_len = strlen(key) + strlen(value) + (sizeof("=\n") - 1);

    char *newents;
    if ((newents = (char *)realloc(*entries, (entries_len + append_len + 1))) == NULL) return 0;
    *entries = newents;

    char *ptr = (*entries + entries_len);
    size_t written_len = (size_t)snprintf(ptr, (append_len + 1), "%s=%s\n", key, value);

    return (written_len != append_len) ? 0 : (entries_len + append_len);
}
