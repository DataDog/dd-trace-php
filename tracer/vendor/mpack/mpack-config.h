#pragma once

// Configuration of the vendored mpack, shared by the tracer and AppSec. Every
// source is compiled with MPACK_HAS_CONFIG=1, so mpack.h includes this file
// and all users agree on the struct layouts.
//
// mpack allocates on the request heap: memory held by a message is released
// if the request bails out halfway through it. Only call allocating mpack
// functions (growable writers, trees, tracking) on PHP threads; the
// background sender only uses the inline mpack_store_* helpers.

#include <Zend/zend.h>

#define MPACK_MALLOC emalloc
#define MPACK_REALLOC erealloc
#define MPACK_FREE efree

#if ZEND_DEBUG
#define MPACK_DEBUG 1
// Exercise the buffer growth paths in tests
#define MPACK_BUFFER_SIZE 32
#else
#define MPACK_STDIO 0
#define MPACK_WRITE_TRACKING 1
#endif
