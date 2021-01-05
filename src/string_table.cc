#include "ddprof/string_table.hh"

#include <ddprof/memhash.hh>

namespace ddprof {

hashed_string::hashed_string(std::size_t len, const char *ptr) noexcept :
    hashed_string{ddprof::memhash::hash(0, len, ptr), len, ptr} {}

interned_string &string_table::intern(hashed_string hashed) {
    constexpr auto data_offset = offsetof(interned_string, data);
    std::size_t size = hashed.size();

    auto iter = map.find(hashed);
    if (iter == map.end()) {
        /* We add +1 for the null terminator. It's not required, but makes
         * working with it easier, as debuggers Just Work.
         */
        auto *addr = ddprof_arena_alloc(arena, data_offset + size + 1);
        auto *interned = reinterpret_cast<interned_string *>(addr);
        interned->hash = hashed.hash();
        interned->offset = vector.size();
        interned->size = size;
        memcpy(&interned->data[0], hashed.data(), size);
        interned->data[size] = '\0';

        vector.push_back(&interned->data[0]);

        hashed_string updated_hash_string{hashed.hash(), interned->size,
                                          &interned->data[0]};
        map.emplace(updated_hash_string, interned->offset);
        return *interned;
    }

    char *addr = const_cast<char *>(vector[iter->second] - data_offset);
    return *reinterpret_cast<interned_string *>(addr);
}

}  // namespace ddprof
