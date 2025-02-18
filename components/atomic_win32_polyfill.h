#include <intrin.h>

#define _Atomic(type) volatile type

#define atomic_exchange _InterlockedExchange64
#define atomic_fetch_add _InterlockedExchangeAdd64
#define atomic_fetch_sub(var, val) atomic_fetch_add(var, -(val))
#define atomic_fetch_or _InterlockedOr64
#define atomic_fetch_and _InterlockedAnd64
#define atomic_compare_exchange_strong(var, expected, val) (_InterlockedCompareExchange64(var, val, *(expected)) == *(expected))
#define atomic_compare_exchange_strong_int(var, expected, val) (_InterlockedCompareExchange(var, val, *(expected)) == *(expected))

// "Simple reads and writes to properly aligned 64 bit [also 32 bit] variables are atomic on 64-bit windows"
#define atomic_store(var, val) (*var = val)
#define atomic_load(var) (*var)
#define atomic_init atomic_store
