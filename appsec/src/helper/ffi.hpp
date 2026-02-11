#include <array>
#include <atomic>
#include <concepts>
#include <cstddef>
#include <functional>
#include <stdexcept>
#include <string>
#include <type_traits>
#include <utility>

extern "C" {
#include <dlfcn.h>
// push -Wno-nested-anon-types and -Wno-gnu-anonymous-struct on clang
#if defined(__clang__)
#    pragma clang diagnostic push
#    pragma clang diagnostic ignored "-Wnested-anon-types"
#    pragma clang diagnostic ignored "-Wgnu-anonymous-struct"
#endif
#include <sidecar.h>
#if defined(__clang__)
#    pragma clang diagnostic pop
#endif
}

#define SIDECAR_FFI_SYMBOL(symbol_name)                                        \
    namespace dds::ffi {                                                       \
    constinit inline ::dds::ffi::sidecar_function<decltype((symbol_name)),     \
        ::dds::ffi::fixed_string{#symbol_name}>                                \
        symbol_name = {} /* NOLINT(misc-use-internal-linkage,                  \
                              cert-err58-cpp) */                               \
    ;                                                                          \
    } // namespace ::dds::ffi

namespace dds::ffi {

template <std::size_t N> struct fixed_string {
    std::array<char, N> value{};

    // NOLINTNEXTLINE(cppcoreguidelines-avoid-c-arrays)
    explicit consteval fixed_string(const char (&str)[N])
    {
        for (std::size_t i = 0; i < N; i++) { value.at(i) = str[i]; }
    }

    [[nodiscard]] constexpr const char *c_str() const { return value.data(); }
};

template <typename Func, auto SymbolName>
    requires std::is_function_v<std::remove_reference_t<Func>>
class sidecar_function {
public:
    using function_type = std::remove_reference_t<Func>;

    constexpr sidecar_function() noexcept = default;

    template <typename... Args>
        requires std::invocable<std::remove_reference_t<Func> *, Args...>
    decltype(auto) operator()(Args &&...args) const
    {
        resolve();
        return std::invoke(get_fn(), std::forward<Args>(args)...);
    }

    [[nodiscard]] function_type *get_fn() const { return resolve(); }

private:
    static function_type *failed_sentinel() noexcept
    {
        // NOLINTNEXTLINE(cppcoreguidelines-pro-type-reinterpret-cast)
        return reinterpret_cast<function_type *>(
            static_cast<std::uintptr_t>(-1));
    }

    mutable std::atomic<function_type *> resolved_fn_{nullptr};

    function_type *resolve() const
    {
        function_type *fn = resolved_fn_.load(std::memory_order_relaxed);
        if (fn == failed_sentinel()) {
            throw std::runtime_error{
                std::string{"Failed to resolve symbol: "} + SymbolName.c_str()};
        }
        if (fn != nullptr) {
            return fn;
        }

        void *found = dlsym(RTLD_DEFAULT, SymbolName.c_str());
        if (found == nullptr) {
            fn = failed_sentinel();
        } else {
            // NOLINTNEXTLINE(cppcoreguidelines-pro-type-reinterpret-cast)
            fn = reinterpret_cast<function_type *>(found);
        }

        (void)resolved_fn_.store(fn, std::memory_order_relaxed);

        return fn;
    }
};

} // namespace dds::ffi

namespace dds {
inline std::string_view to_sv(ddog_CharSlice &slice) noexcept
{
    return std::string_view{slice.ptr, static_cast<std::size_t>(slice.len)};
}
} // namespace dds
