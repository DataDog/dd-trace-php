#include <assert.h>
#include <stdlib.h>

#include "string_table.h"

#ifdef D_SANITY_CHECKS
#include <signal.h>
#endif

// ONLY FOR INTERNAL USE.  ONLY.
#define STR_LEN_PTR(x) ((uint32_t *)&(x)[-4])
#define STR_LEN(x) (*STR_LEN_PTR(x))

#ifdef D_LOGGING_ENABLE
#include <stdio.h>
#include <unistd.h>
#endif

/* TODO

1. Better interface protection.
  a. Check for null pointers on function entry
*/

/******************************************************************************\
|*                          Internal Hash Functions                           *|
\******************************************************************************/
// ---- djb2
// NB, this is not a sophisticated hashing strategy.
uint32_t djb2_hash(const unsigned char *str, size_t len) {
  uint32_t ret = 5381;

  for (; len; len--)
    ret = ((ret << 5) + ret) + *str++;
  return ret;
}

// The below section as per https://github.com/wangyi-fudan/wyhash
// Commented lines are modifications to original source
/******************************************************************************\
|*                      Inlined wyhash32 Implementation                       *|
\******************************************************************************/
// Author: Wang Yi <godspeed_china@yeah.net>
#include <stdint.h>
#include <string.h>
#ifndef WYHASH32_BIG_ENDIAN
static inline unsigned _wyr32(const uint8_t *p) {
  unsigned v;
  memcpy(&v, p, 4);
  return v;
}
#elif defined(__GNUC__) || defined(__INTEL_COMPILER) || defined(__clang__)
static inline unsigned _wyr32(const uint8_t *p) {
  unsigned v;
  memcpy(&v, p, 4);
  return __builtin_bswap32(v);
}
#elif defined(_MSC_VER)
static inline unsigned _wyr32(const uint8_t *p) {
  unsigned v;
  memcpy(&v, p, 4);
  return _byteswap_ulong(v);
}
#endif
static inline unsigned _wyr24(const uint8_t *p, unsigned k) {
  return (((unsigned)p[0]) << 16) | (((unsigned)p[k >> 1]) << 8) | p[k - 1];
}
static inline void _wymix32(unsigned *A, unsigned *B) {
  uint64_t c = *A ^ 0x53c5ca59u;
  c *= *B ^ 0x74743c1bu;
  *A = (unsigned)c;
  *B = (unsigned)(c >> 32);
}
static inline unsigned wyhash32(const void *key, uint64_t len, unsigned seed) {
  const uint8_t *p = (const uint8_t *)key;
  uint64_t i = len;
  unsigned see1 = (unsigned)len;
  seed ^= (unsigned)(len >> 32);
  _wymix32(&seed, &see1);
  for (; i > 8; i -= 8, p += 8) {
    seed ^= _wyr32(p);
    see1 ^= _wyr32(p + 4);
    _wymix32(&seed, &see1);
  }
  if (i >= 4) {
    seed ^= _wyr32(p);
    see1 ^= _wyr32(p + i - 4);
  } else if (i)
    seed ^= _wyr24(p, i);
  _wymix32(&seed, &see1);
  _wymix32(&seed, &see1);
  return seed ^ see1;
}
// static inline uint64_t wyrand(uint64_t *seed){
//  *seed+=0xa0761d6478bd642full;
//  uint64_t  see1=*seed^0xe7037ed1a0b428dbull;
//  see1*=(see1>>32)|(see1<<32);
//  return  (*seed*((*seed>>32)|(*seed<<32)))^((see1>>32)|(see1<<32));
//}
// static inline unsigned wy32x32(unsigned a,  unsigned  b) { _wymix32(&a,&b);
// _wymix32(&a,&b); return a^b;  } static inline float wy2u01(unsigned r) {
// const float _wynorm=1.0f/(1ull<<23); return (r>>9)*_wynorm;} static inline
// float wy2gau(unsigned r) { const float _wynorm=1.0f/(1ull<<9); return
// ((r&0x3ff)+((r>>10)&0x3ff)+((r>>20)&0x3ff))*_wynorm-3.0f;}

static inline unsigned wyhash32(const void *, uint64_t, unsigned);
uint32_t wyhash_hash(const unsigned char *str, size_t len) {
  static unsigned seed = 3913693727; // random large 32-bit prime
  return wyhash32((const void *)str, len, seed);
}
/******************************************************************************\
|*                   End of Inlined wyhash32 Implementation                   *|
\******************************************************************************/

/******************************************************************************\
|*                           Internal Declarations                            *|
\******************************************************************************/
static StringTableArena *_StringTableArena_init(StringTableArena *);
static void _StringTableArena_free(StringTableArena *);
static char _StringTableArena_reserve(StringTableArena *, size_t);

static StringTableNodes *_StringTableNodes_init(StringTableNodes *);
static void _StringTableNodes_free(StringTableNodes *);
static char _StringTableNodes_reserve(StringTableNodes *);
static StringTableNode *_StringTableNodes_get(StringTableNodes *,
                                              const unsigned char *, size_t,
                                              uint32_t *, HashFun);

/******************************************************************************\
|*                              StringTableArena                              *|
\******************************************************************************/
#define STA_ALIGNMENT_MASK 15ull
#define STA_ALIGN(x)                                                           \
  ((__typeof__(x))(((uint64_t)(x) + STA_ALIGNMENT_MASK) & ~STA_ALIGNMENT_MASK))

/*******************************************************************************
 * Initializes a StringTableArena.
 *
 * @param sta A recently initialized or freed StringTableArena.  Cal also be a
 *            NULL pointer, in which case the ownership of the arena is marked
 *            internal.  Internal objects are freed by the library on
 *            _StringTableArena_free().
 ******************************************************************************/
static StringTableArena *_StringTableArena_init(StringTableArena *sta) {
  unsigned char *sta_buf = calloc(1, ST_ARENA_SIZE);
  if (!sta_buf)
    goto STA_INIT_CLEANUP00;

  unsigned char **entry_buf = calloc(sizeof(unsigned char *), ST_ARENA_NELEM);
  if (!entry_buf)
    goto STA_INIT_CLEANUP01;

  if (!sta) {
    sta = calloc(1, sizeof(StringTableArena));
    if (!sta)
      goto STA_INIT_CLEANUP02;
    sta->ownership = 1;
  }

  sta->arena = sta_buf;
  sta->regions[0] = sta_buf;
  sta->capacity = ST_ARENA_SIZE;
  sta->arena_off = 0;
  sta->filled_regions = 1; // Points to the next region to fill

  sta->entry = entry_buf;
  sta->entry_capacity = ST_ARENA_NELEM;
  sta->entry_idx = 0;

  return sta;

STA_INIT_CLEANUP02:
  free(entry_buf);
STA_INIT_CLEANUP01:
  free(sta_buf);
STA_INIT_CLEANUP00:
  return NULL;
}

/*******************************************************************************
 * Frees a StringTableArena.
 *
 * If the StringTableArena was initialized by the library, setting the ownership
 * flag, then the input will become invalid after this call.  Strongly assumes
 * struct internals have not been modified by a user.
 *
 * @param sta An initialized StringTableArena
 ******************************************************************************/
static void _StringTableArena_free(StringTableArena *sta) {
  for (int i = 0; i < 32; i++)
    if (sta->regions[i])
      free(sta->regions[i]), sta->regions[i] = NULL;

  free(sta->entry);

  sta->filled_regions = 0;
  sta->arena = NULL;
  sta->capacity = 0;
  sta->arena_off = 0;
  sta->entry = NULL;
  sta->entry_capacity = 0;
  sta->entry_idx = 0;

  if (sta->ownership)
    free(sta);
}

/*******************************************************************************
 * Increases the space allocated to the StringTableArena storage area
 *
 * @param sta An initialized StringTableArena
 ******************************************************************************/
static char _StringTableArena_expandar(StringTableArena *sta) {
  // NB, there are various inefficiencies with this implementation for multi-
  // scale allocations.  TODO
  unsigned char *buf = calloc(2 * sizeof(unsigned char), sta->capacity);
  if (!buf)
    return -1;

  sta->arena = buf;
  sta->regions[sta->filled_regions++] = buf;
  sta->capacity *= 2;
  sta->arena_off = 0;
  return 0;
}

/*******************************************************************************
 * Increases the space allocated to the StringTableArena entry list
 *
 * @param sta An initialized StringTableArena
 ******************************************************************************/
static char _StringTableArena_expandcap(StringTableArena *sta) {
  unsigned char **buf =
      realloc(sta->entry, 2 * sizeof(unsigned char *) * sta->entry_capacity);
  if (!buf)
    return -1;

  sta->entry = buf;
  sta->entry_capacity *= 2;
  return 0;
}

// TODO something is wrong with these reservation functions

/*******************************************************************************
 * Ensures that a StringTableArena has enough space for a new object.  In the
 * future, will return a reservation to a portion of the arena in a thread-safe
 * fashion.
 *
 * Will return -1 if the user requests more data than can fit in a newly
 * initialized, initial-sized region.  We could certainly allow it to fit the
 * new power-of-two reservation size, but this creates runtime uncertainty about
 * allocation.  This is probably fine, since the intent is to intern name-type
 * strings (i.e., not descriptions or Project Gutenberg).
 *
 * @param sta An initialized StringTableArena
 *
 * @param length how much space is requested
 ******************************************************************************/
static char _StringTableArena_reserve(StringTableArena *sta, size_t length) {
  if (length > ST_ARENA_SIZE)
    return -1; // Will not grant oversized reservations

  // Check whether the arena has capacity
  if (STA_ALIGN(sta->arena_off + length + 1 + sizeof(uint32_t)) >=
      sta->capacity)
    if (_StringTableArena_expandar(sta))
      return -1;

  // Check whether the entries has capacity
  if (sta->entry_idx >= sta->entry_capacity)
    if (_StringTableArena_expandcap(sta))
      return -1;

  return 0;
}

/*******************************************************************************
 * Interns an item to a StringTableArena.
 *
 * Appends an item to a StringTableArena.  It does not de-duplicate insertion,
 * which is handled by the StringTable.  Note that each object is followed by
 * a '\0' and prepended by a four-byte header, encoding the size.
 *
 * After appending the item to the arena, it also adds a reference to the
 * pointer array (entry).
 *
 * @param sta An initialized StringTableArena
 *
 * @param val An object(probably a string) to intern.  Will be shallow copy.
 *
 * @param sz_val the size of the value in bytes.  Does not need to be aligned
 *        size, as that is handled during append
 ******************************************************************************/
#include <stdio.h>
static ssize_t _StringTableArena_append(StringTableArena *sta,
                                        const unsigned char *val,
                                        size_t sz_val) {
  // If the total size exceeds the minimum arena size, then we could either
  // silently truncate or we could throw an error.  Right now, we silently
  // truncate.  TODO OOB logs
  // ASSERT ?alloc aligns to word-boundaries, which should be true on x86_64
  size_t sz_total = STA_ALIGN(sz_val + sizeof(uint32_t) + 1);
  if (sz_total > ST_ARENA_SIZE)
    sz_val = ST_ARENA_SIZE; // if we subtract 5 bytes, it'll just realign

  // Ensure we have enough space for both the arena and the entries
  if (-1 == _StringTableArena_reserve(sta, sz_val))
    return -1;

  // Compute several constants related to setting up the arena
  unsigned char *dst = &sta->arena[sta->arena_off];  // Top of the object
  unsigned char *arena_ptr = dst + sizeof(uint32_t); // What we return
  uint32_t write_len = sz_val;                       // Size after padding

#ifdef D_SANITY_CHECKS
  if (STA_ALIGN(dst) != dst)
    printf("NOT ALIGNED\n"), raise(SIGINT);
  if (sz_total & STA_ALIGNMENT_MASK)
    printf("LENGTH NOT ALIGNED\n"), raise(SIGINT);
#endif

  // Copy the 4-byte header (length) TODO this can overrun?
  memcpy(dst, &write_len, sizeof(uint32_t));
  dst += sizeof(uint32_t);

  // Copy the string (either whole or truncated)
  memcpy(dst, val, sz_val);

  // ASSERT:
  // dst - &sta->arena[sta->arena_off] is in [1,8]
  // &st->arena[...] + sz_total = new aligned address
  sta->arena_off += sz_total;

  // Now also add this to the entries
  ssize_t ret = sta->entry_idx++;
  sta->entry[ret] = arena_ptr;
  return ret;
}

/******************************************************************************\
|*                              StringTableNodes                              *|
\******************************************************************************/
/*******************************************************************************
 * Initializes the nodes portion of a StringTable
 *
 * TODO: could be harmonized with the StringTableArena initializer?
 *
 * @param stn An uninitialized StringTableNodes object
 ******************************************************************************/
static StringTableNodes *_StringTableNodes_init(StringTableNodes *stn) {
  StringTableNode *stn_buf = calloc(sizeof(StringTableNode), ST_ARENA_NELEM);
  if (!stn_buf)
    goto STN_INIT_CLEANUP00;

  StringTableNode **entry_buf =
      calloc(sizeof(StringTableNode *), ST_ARENA_NELEM);
  if (!entry_buf)
    goto STN_INIT_CLEANUP01;

  if (!stn) {
    stn = calloc(1, sizeof(StringTableNodes));
    if (!stn)
      goto STN_INIT_CLEANUP02;
    stn->ownership = 1;
  }

  stn->arena = stn_buf;
  stn->sz_region[0] = ST_ARENA_NELEM;
  stn->regions[0] = stn_buf;
  stn->capacity = ST_ARENA_NELEM;
  stn->arena_off = 0;
  stn->filled_regions = 1;

  stn->entry = entry_buf;
  stn->entry_capacity = ST_ARENA_NELEM;
  stn->entry_count = 0;

  return stn;

STN_INIT_CLEANUP02:
  free(entry_buf);
STN_INIT_CLEANUP01:
  free(stn_buf);
STN_INIT_CLEANUP00:
  return NULL;
}

/*******************************************************************************
 * De-allocates the contents of a StringTableNodes object
 *
 * If the StringTableNodes object was allocated internally, then this is where
 * it will be deallocated.
 *
 * @param stn An initialized StringTableNodes
 ******************************************************************************/
static void _StringTableNodes_free(StringTableNodes *stn) {
  for (int i = 0; i < 32; i++)
    if (stn->regions[i])
      free(stn->regions[i]), stn->regions[i] = NULL;

  free(stn->entry);

  stn->filled_regions = 0;
  stn->arena = NULL;
  stn->capacity = 0;
  stn->arena_off = 0;
  stn->entry = NULL;
  stn->entry_capacity = 0;
  stn->entry_count = 0;

  if (stn->ownership)
    free(stn);
}

/*******************************************************************************
 * Increases the space allocated to the StringTableNodes storage area
 *
 * @param stn An initialized StringTableNodes
 ******************************************************************************/
static char _StringTableNodes_expandar(StringTableNodes *stn) {
  StringTableNode *buf = calloc(2 * sizeof(StringTableNode), stn->capacity);
  if (!buf)
    return -1;

  stn->arena = buf;
  stn->capacity *= 2;
  stn->sz_region[stn->filled_regions] = stn->capacity;
  stn->regions[stn->filled_regions++] = buf;
  stn->arena_off = 0;
  return 0;
}

/*******************************************************************************
 * Increases the space allocated to the StringTableNodes entry list
 *
 * Note that until incremental hashing is enabled, this requires rehashing the
 * entire list so far.
 *
 * TODO: hilariously re-hashes using wyhash, which breaks every other hash user
 *
 * @param stn An initialized StringTableNodes
 ******************************************************************************/
static char _StringTableNodes_expandcap(StringTableNodes *stn) {
  StringTableNode **buf =
      calloc(2 * stn->entry_capacity, sizeof(StringTableNode *));
  if (!buf)
    return -1;
  stn->entry_capacity *= 2;
  stn->entry_count = 0;

  // Iterate through the regions, re-inserting every individual element...
  StringTableNode *node = NULL;
  uint32_t hash_val;
  for (uint64_t i = 0; i < stn->filled_regions; i++) {
    for (uint32_t j = 0; j < stn->sz_region[i]; j++) {
      node = &stn->regions[i][j];
      if (!node->value)
        continue;
      node->next = NULL;
      hash_val = wyhash_hash(node->value, STR_LEN(node->value));
      uint32_t idx = hash_val & (stn->entry_capacity - 1);
      StringTableNode *that_node = buf[idx];

      if (that_node) {
        while (that_node->next)
          that_node = that_node->next;
        that_node->next = node;
      } else {
        stn->entry_count++;
        buf[idx] = node;
      }
    }
  }

  free(stn->entry);
  stn->entry = buf;
  return 0;
}

/*******************************************************************************
 * Reserve space on a StringTableNodes object
 *
 * Ensures that a StringTableNodes has enough room for a single insert.  In the
 * future, this will return a numerical reservation which will be submitted
 * during the insertion
 *
 * @param stn An initialized StringTableNodes
 ******************************************************************************/
static char _StringTableNodes_reserve(StringTableNodes *stn) {
  if (stn->arena_off >= stn->capacity)
    if (_StringTableNodes_expandar(stn))
      return -1;

  if (stn->entry_count * 2 > stn->entry_capacity)
    if (_StringTableNodes_expandcap(stn))
      return -1;
  return 0;
}

/*******************************************************************************
 * Checks that a given value is interned in the nodes
 *
 * Checks the StringTableNodes for the node representing the given value.
 * Requires the user to provide an appropriate function and possibly a value.
 *
 * @param stn An initialized StringTableNodes object
 *
 * @param val A pointer to the input string.  Equivalently, NULL will be taken
 *            as the empty string
 *
 * @param sz_val The size of the given value
 *
 * @param hash A pointer to a computed hash, or NULL.  If not NULL, will compute
 *             the hash, otherwise will reuse the passed value.
 *
 * @param fun A function for computing the hash.  Passed along because the
 *            underlying function is not currently a property of the
 *            StringTableNodes object, but rather the containing struct.
 *****************************************************************************/
inline static StringTableNode *
_StringTableNodes_get(StringTableNodes *stn, const unsigned char *val,
                      size_t sz_val, uint32_t *hash, HashFun fun) {
  uint32_t hash_val;

  if (hash)
    hash_val = *hash;
  else if (fun)
    hash_val = fun(val, sz_val);
  else
    return NULL; // No hash and can't compute it

  // Now look it up
  StringTableNode *node = stn->entry[hash_val & (stn->entry_capacity - 1)];
  while (node) {
    if (sz_val == STR_LEN(node->value) && !memcmp(val, node->value, sz_val)) {
      return node;
    }
    node = node->next;
  }

  return NULL;
}

/******************************************************************************\
|*                                 Public API                                 *|
\******************************************************************************/
ssize_t stringtable_add(StringTable *st, const unsigned char *_val,
                        size_t sz_val) {
  assert(_val != NULL || sz_val == 0);

  // Input sanitization
  const unsigned char *val = _val ? _val : 0;

  // Compute hash
  uint32_t hash_val;
  uint64_t stashed_capacity = st->nodes->entry_capacity;
  if (!st->hash_fun)
    return -1;
  hash_val = st->hash_fun(val, sz_val);

  // Now we can hash into a node to see whether one exists
  StringTableNode *node = st->nodes->entry[hash_val & (stashed_capacity - 1)];
  StringTableNode *node_prev = NULL;

  // Either find a matching node and return the index or run into an empty node
  // and quit.
  while (node) {
    if (sz_val == STR_LEN(node->value) && !memcmp(val, node->value, sz_val))
      return node->idx;
    node_prev = node;
    node = node->next;
  }

  // Node doesn't exist, which means the value is novel in the arena and thus
  // needs to be added.  We start out by reserving enough room for both the
  // string arena itself and for the hashtable nodes.  If
  if (-1 == _StringTableArena_reserve(st->arena, sz_val))
    return -1;
  if (-1 == _StringTableNodes_reserve(st->nodes))
    return -1;

  // It's possible that we rehashed in the last step, so refresh the lookup
  // because the capacity may have changed
  if (stashed_capacity != st->nodes->entry_capacity) {
    node = st->nodes->entry[hash_val & (st->nodes->entry_capacity - 1)];
    node_prev = NULL;

    // Either find a matching node and return the index or run into an empty
    // node and quit.
    while (node) {
      if (sz_val == STR_LEN(node->value) && !memcmp(val, node->value, sz_val))
        return node->idx;
      node_prev = node;
      node = node->next;
    }
  }

  // Now add the object into the arena and check consistency
  ssize_t arena_idx = _StringTableArena_append(st->arena, val, sz_val);
  if (-1 == arena_idx)
    return -1;
  unsigned char *arena_ptr = st->arena->entry[arena_idx];
  if (!arena_ptr)
    return -1;

  // At this point, we have what we need to populate a node and we've reserved
  // the space necessary to actually do so.
  node = &st->nodes->arena[st->nodes->arena_off++];
  node->value = arena_ptr;
  node->idx = arena_idx;
  node->next = NULL;

  // Now we need to either add the node to the entries or as a child of a
  // different node.  When we looked it up, we kept track of whether we
  // terminated immediately or after visiting a parent.
  if (!node_prev) {
    st->nodes->entry_count++;
    st->nodes->entry[hash_val & (st->nodes->entry_capacity - 1)] = node;
  } else {
    node_prev->next = node;
  }

  return node->idx;
}

StringTable *stringtable_init(StringTable *ret, StringTableOptions *opts) {
  static StringTableOptions default_opts = {.hash = 1, .logging = 0};
  if (!opts)
    opts = &default_opts;

  // If the user gave us a NULL pointer, then return a substantial one
  if (!ret) {
    ret = calloc(1, sizeof(StringTable));
    if (!ret)
      goto STRING_TABLE_INIT_CLEANUP00;
    ret->ownership = 1;
  }

  // Set options
  ret->logging = opts->logging;
  ret->hash_fun = opts->hash ? wyhash_hash : djb2_hash;

  // Run internal initializers
  if (!(ret->arena = _StringTableArena_init(NULL)))
    goto STRING_TABLE_INIT_CLEANUP01;
  if (!(ret->nodes = _StringTableNodes_init(NULL)))
    goto STRING_TABLE_INIT_CLEANUP02;

  return ret;

STRING_TABLE_INIT_CLEANUP02:
  _StringTableArena_free(ret->arena);
STRING_TABLE_INIT_CLEANUP01:
  if (ret->ownership) {
    free(ret);
    ret = NULL;
  }
STRING_TABLE_INIT_CLEANUP00:
  return NULL;
}

void stringtable_free(StringTable *st) {
  _StringTableArena_free(st->arena);
  st->arena = NULL;
  _StringTableNodes_free(st->nodes);
  st->nodes = NULL;

  if (st->ownership)
    free(st);
}

ssize_t stringtable_lookup(StringTable *st, const unsigned char *val,
                           size_t sz_val, uint32_t *hash) {
  StringTableNode *node =
      _StringTableNodes_get(st->nodes, val, sz_val, hash, st->hash_fun);

  return (!node) ? -1 : node->idx;
}

unsigned char *stringtable_get(StringTable *st, ssize_t idx) {
  return st->arena->entry[idx];
}

ssize_t stringtable_lookup_cstr(StringTable *st, const char *str) {
  return stringtable_lookup(st, (const unsigned char *)str, strlen(str), NULL);
}

ssize_t stringtable_add_cstr(StringTable *st, const char *str) {
  if (!str)
    str = "";
  return stringtable_add(st, (const unsigned char *)str, strlen(str));
}
