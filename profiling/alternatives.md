# Heap-live free-path alternatives

Heap-live profiling must remove a sampled allocation when ZendMM frees or
reallocates it. The source of truth is currently a `DashMap` keyed by the
allocation pointer. Looking up every freed pointer in that map adds overhead to
the common case where the allocation was not sampled.

The allocation-prefix PoC makes sampled state cheap to query, but relocating
pointers requires a clean epoch after which every pointer passed to the custom
handlers has that prefix. This document describes two fallback alternatives
that preserve the pointer returned by ZendMM if that epoch cannot be guaranteed.

## Allocation footer

### Design

Request eight additional bytes from the underlying allocator, but return its
original pointer:

```text
┌──────────────────────────────┬──────────────────┐
│ user-visible requested bytes │ tracked sentinel │
└──────────────────────────────┴──────────────────┘
^
returned pointer
```

The footer is placed at the end of the allocation's usable ZendMM block rather
than immediately after the requested bytes. The free callback does not receive
the requested size, but it can recover the block size with
`zend_mm_block_size()` while the custom-heap flag is temporarily disabled.

The footer contains one of two values:

* zero for an allocation that is not tracked;
* a fixed sentinel for an allocation tracked by heap-live profiling.

A sampled allocation is inserted into the existing `DashMap` before its footer
is changed to the tracked sentinel. On free or realloc, only a matching sentinel
causes a `DashMap` removal.

The footer should not contain a pointer to a `LiveHeapSample`. Values stored in
the `DashMap` do not have a stable address, and a sentinel is sufficient to
filter the free path.

### Allocations created before hook installation

Their pointers remain valid because the footer design does not relocate them.
Their final block word contains arbitrary data rather than an initialized
footer. If it happens to equal the sentinel, the only consequence is a failed
`DashMap` removal for that pointer. ZendMM still receives the original pointer.

This makes false positives safe and false negatives impossible for correctly
written post-hook allocations.

### Reallocation

Before reallocating, inspect and clear the old allocation's tracked state. Ask
the underlying allocator for the new requested size plus the footer, then
initialize the new footer. If the new allocation is sampled and successfully
inserted into the live set, mark its footer as tracked.

The allocator may copy the old footer into newly exposed user bytes while
growing an allocation. Those bytes are unspecified by `realloc()`, and the new
footer is written at the end of the new usable block.

### Benefits

* The pointer returned by ZendMM is never changed.
* Pre-hook allocations are safe to free and realloc.
* Hooks may be installed and removed per request.
* The common free path avoids hashing and locking.
* The design may work on PHP versions older than 8.4.

### Costs and open questions

* Every hooked allocation requests eight additional bytes. Crossing a ZendMM
  size-class boundary can make the effective cost larger.
* Every free performs a block-size query and reads the final block word.
* PHP debug builds reserve allocator debug information at the end of each
  block. The footer must be placed before that engine-owned trailer.
* `USE_ZEND_ALLOC=0` and neighboring custom allocators may not provide a usable
  ZendMM block size. Those configurations need the current map lookup as a
  fallback.
* Code that incorrectly writes beyond its requested size into ZendMM size-class
  slack can overwrite the footer. This is outside the allocation contract but
  should be covered by stress and ASAN testing.

## ZendMM chunk-map filter

### Design

ZendMM stores a four-byte map entry for every page in a two-megabyte chunk. The
current page-entry layout leaves bit 29 unused for small, large, and free runs.
Use that bit as a first-level indicator that a page may contain a tracked
allocation.

Small runs contain multiple fixed-size slots, so a page bit is not exact. Keep a
thread-local bitmap for each active small run:

```text
chunk map bit 29
    clear -> no tracked allocation on this page
    set   -> inspect the run's slot bitmap
```

For large runs, the page bit identifies the allocation directly. Huge
allocations do not have a normal chunk-map entry and continue to use the
`DashMap` directly.

The `DashMap` remains the source of truth and remains available to the exporter
thread. The chunk map and slot bitmaps are only a free-path filter.

### Tracking

After a sampled allocation is successfully inserted into the `DashMap`:

1. Decode its ZendMM chunk, page, run, and slot.
2. Set the exact slot in the run bitmap for a small allocation.
3. Set bit 29 on the run's page entries when its first tracked slot appears.
4. For a large allocation, set bit 29 on its first page entry.

### Untracking

On free or realloc:

1. Read the pointer's page-map entry.
2. Return immediately when bit 29 is clear.
3. For a small run, test and clear the exact slot bit.
4. Clear the page bits and remove empty run state after its final tracked slot.
5. Remove the pointer from the `DashMap` only after an exact filter hit.

### Benefits

* No per-allocation size increase.
* The common free path is a single map-word load and branch.
* The pointer returned by ZendMM is unchanged.
* Allocations created before hook installation naturally have a clear bit.
* False positives only cause an unnecessary `DashMap` lookup.

### Costs and open questions

* `_zend_mm_chunk` and its map layout are private PHP internals.
* The map offset must be probed at build time for each PHP build and platform.
* Bit 29 is currently unused but is not reserved by a public API.
* Small allocations require additional per-run bitmap state and lifecycle
  management.
* Huge allocations still take the existing map path.
* Incorrect layout decoding can corrupt ZendMM metadata.
* Forks, heap resets, and thread teardown must clear filter state in lockstep
  with the live-allocation map.

For a production implementation, the preferred long-term form is an upstream
ZendMM API that reserves an observer bit or exposes safe per-allocation tagging.
Without that API, this design needs strict runtime validation and a fallback
when the expected map layout is unavailable.

## Comparison

| Property | Footer | Chunk-map filter |
|---|---|---|
| Pointer relocation | None | None |
| Per-allocation memory | At least 8 bytes | None |
| Common free path | Block-size query and footer load | Page-map load |
| Private ZendMM layout | Debug trailer only | Chunk map and bin layout |
| Pre-hook allocations | Safe false positives | Naturally unmarked |
| Huge allocations | Supported with block size | Falls back to `DashMap` |
| Neighboring allocators | Requires fallback | Requires fallback |

The footer is the simpler extension-owned design. The chunk-map filter has the
better expected hot-path and memory cost, but it carries substantially more
compatibility risk unless PHP exposes the required metadata through a supported
API.
