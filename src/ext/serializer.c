#include <php.h>
#include <stdio.h>
#include "mpack/mpack.h"

int ddtrace_serialize_trace(zval *trace, zval *retval) {
    // encode to memory buffer
    char* data;
    size_t size;
    mpack_writer_t writer;
    mpack_writer_init_growable(&writer, &data, &size);
    // write the example on the msgpack homepage
    mpack_start_map(&writer, 2);
    mpack_write_cstr(&writer, "compact");
    mpack_write_bool(&writer, true);
    mpack_write_cstr(&writer, "schema");
    mpack_write_uint(&writer, 0);
    mpack_finish_map(&writer);
    // finish writing
    if (mpack_writer_destroy(&writer) != mpack_ok) {
        return 0;
    }
    ZVAL_STRING(retval, data);
    //free(data);
    return 1;
}
