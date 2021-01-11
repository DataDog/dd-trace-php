#ifndef _H_STRING_TABLE
#define _H_STRING_TABLE

#ifdef __cplusplus
  extern "C" {
#endif

#include <sys/types.h>
#include <string.h>
#include <stdint.h>

/*
 * All of the strings interned by this library into the string arena are
 * prepended by a FOUR BYTE LENGTH.  Yes, you are reading this correctly.  This
 * library inserts garbage into the string table, presuming that nobody is
 * going to want to serialize the whole thing in one go.
 */

typedef struct StringTableNode {
  unsigned char* string; // Offset into the arena
  ssize_t idx;           // Index into the table
  struct StringTableNode* next; // If this is linked, the next guy
} StringTableNode;

typedef struct StringTableOptions {
  uint64_t hash      : 2, // 0 - djb2, 1 - wyhash, 2 - ???
           alloc     : 2, // 0 - resized, 1 - chained, 2 - ???
           logging   : 1, // 0 - no, 1 - yes
           _reserved : 3;
} StringTableOptions;

typedef struct StringTable {
  // Elements governing the string arena
  unsigned char* arena;      // The place where the strings live
  uint32_t arena_reserved;   // How many BYTES are reserved
  uint32_t arena_size;       // How many bytes of the arena are used

  // Elements governing the node arena
  StringTableNode*  nodes;   // Arena
  StringTableNode** entry;   // Indirection for hashing
  uint32_t nodes_reserved;   // How many ELEMENTS are reserved
  uint32_t nodes_size;
  uint8_t mode;              // 0 - mmap/resize, 1 - alloc/chained
  union {
    struct {
      uint64_t _reserved[33];   // No state necessary for resize arena
    };
    struct {
      uint64_t chained_idx;
      StringTableNode* chained_arenas[32]; // Stores state for chained allocator
    };
  };

  // For convenience to prevent having to walk the table again later
  unsigned char** table;
  uint32_t table_reserved;  // ELEMENTS
  uint32_t table_size;

  // Governs the semantics of this string table
  // TODO 32 or 64-bit hashing?  Common wisdom is that dictionaries benefit
  //      from a 32-bit hash for reasons of cache-efficiency.
  uint32_t (*hash_fun)(unsigned char* key, size_t len);
  uint8_t logging      : 1, // disabled by default
          hash_type    : 2, // 0-djb2, 1-wyhash, 2-???; CURRENTLY UNUSED, good intentions etc
          __reserved_0 : 5; // Right.
} StringTable;

#define ST_ARENA_SIZE 16384 // Starting number of bytes for variable-sized arenas
#define ST_ARENA_NELEM 4096 // Starting number of elements for fixed-size arenas

// Public API
StringTable* stringtable_init(StringTableOptions* opts);
void stringtable_free(StringTable*);
ssize_t stringtable_lookup(StringTable*, unsigned char*, size_t);
unsigned char* stringtable_get(StringTable*, ssize_t);
ssize_t stringtable_add(StringTable*, unsigned char*, size_t);
ssize_t stringtable_add_cstr(StringTable*, char*);


#ifdef __cplusplus
}
#endif
#endif
