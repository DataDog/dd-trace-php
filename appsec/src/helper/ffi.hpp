extern "C" {
// push -Wno-nested-anon-types and -Wno-gnu-anonymous-struct on clang
#if defined(__clang__)
#    pragma clang diagnostic push
#    pragma clang diagnostic ignored "-Wnested-anon-types"
#    pragma clang diagnostic ignored "-Wgnu-anonymous-struct"
#endif
#include <sidecar-appsec.h>
#include <sidecar.h>
#if defined(__clang__)
#    pragma clang diagnostic pop
#endif
}

namespace dds {
inline std::string_view to_sv(ddog_CharSlice &slice) noexcept
{
    return std::string_view{slice.ptr, static_cast<std::size_t>(slice.len)};
}
} // namespace dds
