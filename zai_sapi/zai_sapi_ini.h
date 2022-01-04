#ifndef ZAI_SAPI_INI_H
#define ZAI_SAPI_INI_H

#include <stdbool.h>
#include <stddef.h>
#include <sys/types.h>

/* Allocates memory for 'dest' and copies the entries from 'src' into it.
 * 'dest' must point to NULL or no allocation will occur. Caller must free with
 * zai_sapi_ini_entries_free(). Returns '-1' on failure.
 */
ssize_t zai_sapi_ini_entries_alloc(const char *src, char **dest);

/* Frees the INI entries and sets the pointer to NULL to prevent a use after
 * free.
 */
void zai_sapi_ini_entries_free(char **entries);

/* Reallocates the INI entries and append a new entry. Returns the new length
 * of the entries (not counting the null terminator) after appending the new
 * entry. The pointer to 'entries' might change after reallocation.
 *
 * A length of '-1' will be returned if:
 *   - Reallocation fails (the 'entries' pointer will be unchanged)
 *   - There was an error copying the new entry into memory
 *   - 'key' is an empty string
 */
ssize_t zai_sapi_ini_entries_realloc_append(char **entries, size_t entries_len, const char *key, const char *value);

/* Will return false if ZAI_SAPI_PHP_INI_IGNORE env variable is a falsy value */
bool zai_sapi_php_ini_ignore(void);

#endif  // ZAI_SAPI_INI_H
