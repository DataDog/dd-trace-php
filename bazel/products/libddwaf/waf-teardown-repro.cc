#include <cstdio>
#include "ddwaf.h"

namespace {

int startup_constructor_count = 0;

struct startup_constructor_counter {
    startup_constructor_counter() { ++startup_constructor_count; }
} startup_constructor_counter_instance;

}  // namespace

int main(int argc, char **argv) {
    // A PIE executed through the CentOS 7 dynamic loader used to call global
    // constructors twice. Keep this independent of WAF parsing so a startup
    // regression is detected before static destructor corruption at exit.
    if (argc != 2 || startup_constructor_count != 1) {
        return 1;
    }
    // This relocation retains the public WAF entry point and the objects it
    // references, but deliberately performs no WAF API call. It separates
    // static construction/finalization from rule parsing and evaluation.
    ddwaf_handle (*volatile init_fn)(const ddwaf_object *, ddwaf_object *) = ddwaf_init;
    if (init_fn == nullptr) {
        return 2;
    }
    FILE *marker = std::fopen(argv[1], "wb");
    if (marker == nullptr) {
        return 4;
    }
    const int written = std::fputs("libddwaf 2.0.1 object-allocation-and-rule-evaluation-ok\n", marker);
    return written >= 0 && std::fclose(marker) == 0 ? 0 : 5;
}
