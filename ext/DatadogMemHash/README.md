# Datadog MemHash

This exposes hashing functions for C99 and C++14 on 64-bit platforms:

```c++
// headers
#include <datadog/memhash.h>
#include <datadog/memhash.hh>

// datadog/memhash.h
uint64_t datadog_memhash(uint64_t size, const char str[]);
uint64_t datadog_cantor_hash(uint64_t x, uint64_t y);

// datadog/memhash.hh
namespace datadog {
uint64_t memhash(uint64_t size, const char str[]) noexcept;
constexpr uint64_t cantor_hash(uint64_t x, uint64_t y) noexcept;
}
```

Currently, `datadog::memhash` uses Murmur3¹.

The CMake file exposes two targets:
  - `Datadog::CMemHash`
  - `Datadog::CxxMemHash`

The C++ target is header-only, whereas the C API requires linking with
`datadog_memhash`. It can be built as a shared library by setting the CMake
option `BUILD_SHARED_LIBS=On`, or as a static library by setting it to `Off`.

---

¹ MurmurHash3 is in the public domain. It was written by Austin Appleby, who has
disclaimed all copyrights to the source code.
