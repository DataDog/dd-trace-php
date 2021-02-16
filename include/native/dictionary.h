#ifndef _H_DICTIONARY
#define _H_DICTIONARY

#ifdef __cplusplus
extern "C" {
#endif

#include "string_table.h"

typedef struct DictionaryOptions {
  uint64_t hash   : 2, // passthrough (copypasta from string_table.h)
      alloc       : 2, // passthrough TODO use a better way of inlining this
      logging     : 1, // passthrough      ... or don't inline
      _reserved_0 : 3; // passthrough
  uint64_t copy   : 2, // 0 - do not copy values, 1 - copy values
      _reserved_1 : 6;
} DictionaryOptions;

typedef struct Dictionary {
  StringTable st;
  char copy_vals; // Do we copy void* or leave them be?
  void **values;
  uint32_t values_reserved;
} Dictionary;

#define DIC_ARENA_NELEM ST_ARENA_NELEM
extern void *dictionary_dflt_na;
#define DICT_NA dictionary_dflt_na

// Public API
Dictionary *dictionary_init(Dictionary *, DictionaryOptions *);
void dictionary_free(Dictionary *);
void *dictionary_get(Dictionary *, unsigned char *, size_t);
void *dictionary_get_cstr(Dictionary *, char *);
ssize_t dictionary_add(Dictionary *, unsigned char *, size_t, void *,
                       size_t); // An ADD operation only succeeds if the key is
                                // unpopulated (or deleted)
ssize_t dictionary_add_cstr(Dictionary *, char *, void *, size_t);
ssize_t dictionary_put(Dictionary *, unsigned char *, size_t, void *,
                       size_t); // A PUT operation succeeds whether or not the
                                // key is already populated
ssize_t dictionary_put_cstr(Dictionary *, char *, void *, size_t);
ssize_t dictionary_del(Dictionary *, unsigned char *, size_t);
ssize_t dictionary_del_cstr(Dictionary *, char *);

#ifdef __cplusplus
}
#endif
#endif
