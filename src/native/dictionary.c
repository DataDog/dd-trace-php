#include <stdlib.h>

#include "dictionary.h"

// Globals
void* dictionary_dflt_na = (void*)&(uint64_t){0}; // random pointer...  TODO this needs a whole strategy for deserialization

// TODO
// * this and string_table should have parameter-by-parameter defaulting.  We
//   can eat a few more bytes in the options interface to allow 0 to be a
//   value denoting "default".

// ---- Forward decl of purely internal functions
static char _dictionary_values_resize(Dictionary*);
static char _dictionary_insert(Dictionary*, ssize_t, void*, size_t);

Dictionary* dictionary_init(Dictionary* dict, DictionaryOptions* opts) {
  static DictionaryOptions default_opts = {.hash=1, .alloc=1, .logging=0, .copy=1};
  char clear_dict = 0;
  if(!opts) opts = &default_opts;
  if(!dict) {
    clear_dict=1;
    dict = calloc(1, sizeof(Dictionary));
    if(!dict)
      return NULL;
  }

  // Set dictionary-level options
  dict->copy_vals = opts->copy;

  // If the dictionary is being reused, then these will be lost, but in that
  // case there's no way to know the disposition of the previous dictionary, so
  // focus on initializing it in a referentially safe way
  dict->values_reserved = DIC_ARENA_NELEM;
  dict->values = calloc(dict->values_reserved, sizeof(void*));
  if(!dict->values) {
    dict->values = NULL;
    if(clear_dict) free(dict);
    return NULL;
  }

  // This is not configurable
  if(!stringtable_init((StringTable*)dict, (StringTableOptions*)opts)) {
    free(dict->values);
    if(clear_dict) free(dict);
    return NULL;
  }

  return dict;
}

void dictionary_free(Dictionary* dict) {
  StringTable* st = (StringTable*)dict;
  if(!dict) return;
  if(dict->copy_vals) {
    for(uint32_t i=0; i < st->nodes->entry_capacity; i++) {
      if(!st->nodes->entry[i]) continue;
      size_t idx = st->nodes->entry[i]->idx;
      if(dict->values[idx] && dict->values[idx] != DICT_NA) {
        free(dict->values[idx]);
        dict->values[idx] = NULL;
      }
    }
  }

  if(dict->values) free(dict->values);

  stringtable_free(st);
}

void* dictionary_get(Dictionary* dict, unsigned char* key, size_t sz_key) {
  ssize_t idx = stringtable_lookup((StringTable*)dict, key, sz_key, NULL);
  if(-1 == idx) return DICT_NA;
  return dict->values[idx];
}

void* dictionary_get_cstr(Dictionary* dict, char* key) {
  return dictionary_get(dict, (unsigned char*)key, strlen(key));
}

char _dictionary_values_resize(Dictionary* dict) {
  void** values_resize_buf = realloc(dict->values, 2*dict->values_reserved*sizeof(void*));
  if(!values_resize_buf) return -1;

  memset(&values_resize_buf[dict->values_reserved], 0, dict->values_reserved);
  dict->values_reserved *= 2;
  dict->values = values_resize_buf;
  return 0;
}

/*
 *  The next time I write this, I'm going to replace the void* with a fat pointer in order to
 *  properly represent length.  That way, I can avoid heap allocations when the user
 *  is just adding any kind of 64-bit type.
 */
static char _dictionary_insert(Dictionary* dict, ssize_t idx, void* val, size_t sz_val) {
  if(idx < 0)        return -1; // We don't create the entries here
  if(val && !sz_val) return -1; // Paradox.  NOT a delete operation.
  if(!val && !sz_val) val = DICT_NA; // Say what you mean or error?  Hard to say.

  // Do we need to resize?
  while(idx >= dict->values_reserved) {
    if(-1 == _dictionary_values_resize(dict)) return -1;
  }

  // Intern or reference the value, clearing the old one if semantically valid
  if(dict->copy_vals) {
    if(dict->values[idx] && dict->values[idx] != DICT_NA) {
      free(dict->values[idx]);
      dict->values[idx] = NULL;
    }

    if(val != DICT_NA) {
      dict->values[idx] = calloc(1, sz_val);
      memcpy(dict->values[idx], val, sz_val);
    } else {
      dict->values[idx] = DICT_NA;
    }
  } else {
    dict->values[idx] = val;
  }

  // K, we're done
  return 0;
}

ssize_t dictionary_add(Dictionary* dict, unsigned char* key, size_t sz_key, void* val, size_t sz_val) {
  ssize_t idx = stringtable_lookup((StringTable*)dict, key, sz_key, NULL);

  // If the value is NA or the idx is -1, we want to insert the value
  if(-1 == idx || dict->values[idx] == DICT_NA) {
    if(-1 == idx)
      idx = stringtable_add((StringTable*)dict, key, sz_key);
    if(-1 == _dictionary_insert(dict, idx, val, sz_val))
      return -1;
    return idx;
  }

  return -1;
}

ssize_t dictionary_put(Dictionary* dict, unsigned char* key, size_t sz_key, void* val, size_t sz_val) {
  ssize_t idx = stringtable_lookup((StringTable*)dict, key, sz_key, NULL);

  if(-1 == idx)
    idx = stringtable_add((StringTable*)dict, key, sz_key);
  if(-1 == _dictionary_insert(dict, idx, val, sz_val))
    return -1;
  return idx;
}

ssize_t dictionary_add_cstr(Dictionary* dict, char* key, void* val, size_t sz_val) {
  return dictionary_add(dict, (unsigned char*)key, strlen(key), val, sz_val);
}

ssize_t dictionary_put_cstr(Dictionary* dict, char* key, void* val, size_t sz_val) {
  return dictionary_put(dict, (unsigned char*)key, strlen(key), val, sz_val);
}

ssize_t dictionary_del(Dictionary* dict, unsigned char* key, size_t sz_key) {
  ssize_t idx = stringtable_lookup((StringTable*)dict, key, sz_key, NULL);
  if(-1 == idx) return -1;
  if(dict->copy_vals) free(dict->values[idx]);
  dict->values[idx] = DICT_NA;

  return idx;
}

ssize_t dictionary_del_cstr(Dictionary* dict, char* key) {
  return dictionary_del(dict, (unsigned char*)key, strlen(key));
}
