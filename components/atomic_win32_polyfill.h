#include <intrin.h>

#define _Atomic(type) volatile type

// helpful MSVC does not eliminate _Generic() branches early, but emits warnings for every branch: explicitly cast
#define _atomic_op_cast32(var, ...) ((volatile int32_t *) var, __VA_ARGS__)
#define _atomic_op_cast64(var, ...) ((volatile int64_t *) var, __VA_ARGS__)
#define _atomic_op(op, var, parens) _Generic(*(var), \
    int32_t: op _atomic_op_cast32 parens,                      \
    uint32_t: op _atomic_op_cast32 parens,                     \
    int64_t: op##64 _atomic_op_cast64 parens,                    \
    uint64_t: op##64 _atomic_op_cast64 parens                    \
)

#define atomic_exchange(var, val) _atomic_op(_InterlockedExchange, var, (var, val))
#define atomic_fetch_add(var, val) _atomic_op(_InterlockedExchangeAdd, var, (var, val))
#define atomic_fetch_sub(var, val) _atomic_op(_InterlockedExchangeAdd, var, (var, -(val)))
#define atomic_fetch_or(var, val) _atomic_op(_InterlockedOr, var, (var, val))
#define atomic_fetch_and(var, val) _atomic_op(_InterlockedAnd, var, (var, val))
#define atomic_compare_exchange_strong(var, expected, val) _atomic_op(_InterlockedCompareExchange, var, (var, *(expected), val))

// "Simple reads and writes to properly aligned 64 bit [also 32 bit] variables are atomic on 64-bit windows"
#define atomic_store(var, val) (*var = val)
#define atomic_load(var) (*var)
#define atomic_init atomic_store
