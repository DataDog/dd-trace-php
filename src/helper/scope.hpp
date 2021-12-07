// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#ifndef SCOPE_HPP
#define SCOPE_HPP

#include <atomic>

namespace dds {

// This class can be used to derive from if necessary also duck-typing is
// another acceptable option.
class ref_counted {
public:
    void add_reference() { count++; }
    void delete_reference() { count--; }
    unsigned reference_count() { return count; }

protected:
    std::atomic<unsigned> count{0};
};

template <typename T> class scope {
public:
    explicit scope(T &rc) : rc_(rc) { rc_.add_reference(); }
    ~scope() { rc_.delete_reference(); }

    scope(const scope<T> &) = delete;
    scope &operator=(scope<T> &) = delete;
    scope(scope<T> &&) = delete;
    scope &operator=(scope<T> &&) = delete;

private:
    T &rc_;
};

} // namespace dds
#endif
