#ifndef _H_STRING_TABLE
#define _H_STRING_TABLE

#include <stdint.h>
#include <string.h>
#include <sys/types.h>

// TODO
// Split the internment function in half, exposing the hash-laden side.  This
// will enable callers who had to perform a lookup to skip computing a second
// hash (NOTE--possibly it's more expensive to issue two calls)

/*
 * All of the strings interned by this library into the string arena are
 * prepended by a FOUR BYTE LENGTH.  Yes, you are reading this correctly.  This
 * library inserts garbage into the string table, presuming that nobody is
 * going to want to serialize the whole thing in one go.
 */

typedef uint32_t (*HashFun)(const unsigned char *, size_t);

typedef union HashRet {
  struct {
    uint32_t upper;
    uint32_t lower;
  };
  uint64_t value;
} HashRet;

typedef struct StringTableNode {
  unsigned char *value;         // Offset into the arena
  ssize_t idx;                  // Index into the table
  struct StringTableNode *next; // If this is linked, the next guy
} StringTableNode;

typedef struct StringTableOptions {
  uint64_t hash : 2, // 0 - djb2, 1 - wyhash, 2 - ???
      logging   : 1, // 0 - no, 1 - yes
      _reserved : 5;
} StringTableOptions;

typedef struct StringTableArena {
  unsigned char *regions[32]; // List of regions, so they can be freed
  unsigned char *arena;       // Points to the currently active region
  size_t capacity;            // How big is the current reservation?
  uint64_t arena_off;      // Word-aligned index into the region for new allocs
  uint64_t filled_regions; // Index into the next unallocated region
  unsigned char **entry;   // Frontend for the arena, for linear access
  size_t entry_capacity;   // How much total space is in the entries?
  uint64_t entry_idx;      // Next entry to populate
  char ownership;          // Should I free the StringTableArena?
} StringTableArena;

typedef struct StringTableNodes {
  StringTableNode *regions[32]; // All allocation regions (for freeing)
  uint32_t sz_region[32];       // A size for each region
  StringTableNode *arena;       // Current allocation region
  size_t capacity;              // How big is the current reservation?
  uint64_t arena_off;           // Index into the region for new allocs
  uint64_t filled_regions;      // Index into the next unallocated region
  StringTableNode **entry;      // Frontend for the arena, for linear access
  size_t entry_capacity;        // How much total space is in the entries?
  uint64_t entry_count;         // For computing use fraction
  char ownership;               // Should I free the StringTableArena?
} StringTableNodes;

typedef struct StringTable {
  StringTableArena *arena; // Storage for strings
  StringTableNodes *nodes; // Storage for nodes

  // Governs the semantics of this string table
  // TODO 32 or 64-bit hashing?  Common wisdom is that dictionaries benefit
  //      from a 32-bit hash for reasons of cache-efficiency.
  uint32_t (*hash_fun)(const unsigned char *key, size_t len);
  uint8_t logging : 1,  // disabled by default
      hash_type   : 2,  // 0-djb2, 1-wyhash, 2-???; CURRENTLY UNUSED, good
                        // intentions etc
      __reserved_0 : 5; // Right.
  char ownership;       // Do I free this object
} StringTable;

#define ST_ARENA_SIZE                                                          \
  16384 // Starting number of bytes for variable-sized arenas
#define ST_ARENA_NELEM 4096 // Starting number of elements for fixed-size arenas

// Public API
/*******************************************************************************
 * Initializes a StringTable
 *
 * @param st Either an un-initialized StringTable or NULL.  If the latter, will
 *           allocate the object on the heap and manage its lifecycle during
 *           subsequent free operations.  If not NULL, then it is up to the user
 *           to free() the object after calling StringTable_free()
 *
 * @param opts encodes the options
 ******************************************************************************/
StringTable *stringtable_init(StringTable *, StringTableOptions *);

/*******************************************************************************
 * Frees an initialized StringTable
 *
 * @param st An initialized StringTable.  If it was allocated internally, then
 *           the container will be deallocated as well.
 ******************************************************************************/
void stringtable_free(StringTable *);

/*******************************************************************************
 * Checks whether the specified string is interned in the StringTable.
 *
 * Performs a GET-type operation on a StringTable, returning either -1 or the
 * numeric ID of the requested input.  Allows for hash reuse.  NB that if a
 * hash is sent, it will be automatically coerced to the current width of the
 * lookup table.  TODO design and return a more sophisticated hash object which
 * would allow the callee to recompute the hash if the supplied arg is invalid.
 *
 * @param st An initialized StringTable
 *
 * @param val String to look up
 *
 * @param sz_val Length of the string.  No handling of \0 characters is done,
 *               since the interned value may represent binary data.  If you
 *               want to include/exclude nulls, account for the length.  For
 *               example, consider strlen() doesn't return space for null.
 *
 * @param hash Pointer to a valid hash or NULL.  If NULL, hash will be computed
 ******************************************************************************/
ssize_t stringtable_lookup(StringTable *, const unsigned char *, size_t,
                           uint32_t *);

/*******************************************************************************
 * Checks whether the specified string is interned in the StringTable.
 *
 * Performs a GET-type operation on a StringTable, returning either -1 or the
 * numeric ID of the requested input.  Allows for hash reuse.  NB that if a
 * hash is sent, it will be automatically coerced to the current width of the
 * lookup table.  TODO design and return a more sophisticated hash object which
 * would allow the callee to recompute the hash if the supplied arg is invalid.
 *
 * @param st An initialized StringTable
 *
 * @param val String to look up
 *
 * @param sz_val Length of the string.  No handling of \0 characters is done,
 *               since the interned value may represent binary data.  If you
 *               want to include/exclude nulls, account for the length.  For
 *               example, consider strlen() doesn't return space for null.
 *
 * @param hash Pointer to a valid hash or NULL.  If NULL, hash will be computed
 ******************************************************************************/
ssize_t stringtable_add(StringTable *, const unsigned char *, size_t);
ssize_t stringtable_add_cstr(StringTable *, const char *);

/*******************************************************************************
 *
 * @param st An initialized StringTable
 *
 * @param idx The index of a string in the table
 ******************************************************************************/
unsigned char *stringtable_get(StringTable *, ssize_t);

#endif
