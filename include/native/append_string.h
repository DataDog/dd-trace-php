#ifndef _H_APPEND_STRING
#define _H_APPEND_STRING

#include <stddef.h>

/******************************************************************************\
|*                             AppendString Stuff                             *|
\******************************************************************************/
// AppendString is, well, and append-only string on the heap...
#define AS_CHUNK 4096
typedef struct AppendString {
  char *str;      // Base of the string
  size_t sz;      // Total size available
  size_t n;       // Always points to the start of the next allocation
  char ownership; // Do I clear myself?
} AppendString;

AppendString *as_init(AppendString *);
void as_free(AppendString *);
char as_clear(AppendString *);
char as_grow(AppendString *, size_t);
char as_add(AppendString *, const unsigned char *, size_t);
char as_sprintf(AppendString *, const char *, ...);

#endif
