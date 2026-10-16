#include <libunwind.h>
#include <libunwind-ptrace.h>

int main(void) {
    unw_context_t context;
    unw_cursor_t cursor;
    unw_addr_space_t remote;
    unw_word_t ip;

    if (unw_getcontext(&context) || unw_init_local(&cursor, &context)) {
        return 1;
    }
    if (unw_step(&cursor) <= 0 || unw_get_reg(&cursor, UNW_REG_IP, &ip) || !ip) {
        return 2;
    }
    remote = unw_create_addr_space(&_UPT_accessors, 0);
    if (!remote) {
        return 3;
    }
    unw_destroy_addr_space(remote);
    return 0;
}
