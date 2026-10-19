#include <libunwind.h>
#include <libunwind-ptrace.h>

int gnu_libunwind_shared_link_gate(void) {
    unw_context_t context;
    unw_cursor_t cursor;
    unw_addr_space_t remote;

    if (unw_getcontext(&context) || unw_init_local(&cursor, &context)) {
        return 1;
    }
    remote = unw_create_addr_space(&_UPT_accessors, 0);
    if (!remote) {
        return 2;
    }
    unw_destroy_addr_space(remote);
    return 0;
}
