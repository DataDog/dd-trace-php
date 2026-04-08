# OPcache: Research Notes for Profiling Extension

Source code for PHP versions can be found at, you are free to read things there:

/usr/local/src/php/7.1
/usr/local/src/php/7.2
/usr/local/src/php/7.3
/usr/local/src/php/7.4
/usr/local/src/php/8.0
/usr/local/src/php/8.1
/usr/local/src/php/8.2
/usr/local/src/php/8.3
/usr/local/src/php/8.4
/usr/local/src/php/8.5

## INI Defaults

`opcache.file_cache` defaults to `NULL` (disabled) across all PHP versions 7.1–8.5.
File cache is an opt-in feature; the vast majority of deployments use SHM only.

| INI                                  | Default | Notes                                   |
|--------------------------------------|---------|-----------------------------------------|
| `opcache.file_cache`                 | NULL    | Path string; NULL = disabled            |
| `opcache.file_cache_only`            | 0       | 1 = no SHM at all                       |
| `opcache.file_cache_fallback`        | 1       | Fall back to file cache on SHM failure  |
| `opcache.file_cache_consistency_checks` | 1    | Checksum validation on load             |
| `opcache.file_cache_read_only`       | 0       | 8.5+ only; skip write-permission check  |

## Operating Modes

**SHM only (default):** `opcache.file_cache = NULL`. Scripts compiled on first use,
persisted to SHM. `op_array_persist` hooks called. No file cache involvement.

**SHM + file cache (hybrid):** `opcache.file_cache = <path>`, `opcache.file_cache_only = 0`.
SHM is primary. On SHM miss, OPcache checks file cache before compiling. If the script
is found in file cache it's loaded into SHM (`memcpy`). Persist hooks are NOT called for
the file-cache-to-SHM path.

**file_cache_only:** `opcache.file_cache = <path>`, `opcache.file_cache_only = 1`.
No SHM. Scripts loaded from file cache into per-process memory. Persist hooks are
never called (they are only called from `cache_script_in_shared_memory`, which is
never reached in this mode).

## When `op_array_persist` Is and Isn't Called

`op_array_persist` (and `op_array_persist_calc`) on `zend_extension` are invoked via
`zend_extensions_op_array_persist*()` during OPcache's persistence pass
(`zend_accel_script_persist*()` / `zend_persist_op_array()`). That pass is used when
OPcache persists a newly compiled script into SHM, when it serializes a newly compiled
script into the file cache, and during preload. It is not used when OPcache later loads
an already-cached script back from the file cache.

| Path                                    | Persist hooks called? |
|-----------------------------------------|-----------------------|
| Fresh compilation → SHM                 | Yes                   |
| Fresh compilation → file cache          | Yes                   |
| File cache → SHM (`zend_file_cache_script_load`) | No           |
| File cache → process memory (`file_cache_only`, SHM full fallback, etc.) | No  |
| Internal functions (never compiled)     | Never                 |

## File Cache Serialization / Unserialization

`zend_file_cache_script_store()` writes a serialized `zend_persistent_script` to disk
using the current persisted op_array state. For this profiler's current design, the
`reserved[]` slot contains a raw `FunctionIndex` written by `op_array_persist`.

`zend_file_cache_script_load()` reads the file and either:
- Copies the serialized blob into OPcache SHM via `zend_shared_alloc_aligned` + `memcpy`,
  then unserializes it there (`cache_it = true`), or
- Uses the CG(arena) process-memory buffer directly, then unserializes it there
  (`use_process_mem`, `cache_it = false`).

During that unserialization path, no persist hooks fire. In the refreshed local PHP
7.1–8.5 trees, I did not find any special `reserved[]` handling in
`ext/opcache/zend_file_cache.c`, so for this profiler's current raw-`FunctionIndex`
slot the working assumption is that the cached value is carried forward into the live
op_array on file-cache load.

## The Stale `reserved[]` Bug

Our profiling extension writes a `FunctionIndex` (index into profiling SHM) into
`op_array->reserved[slot]` from the `op_array_persist` hook. After a PHP restart:

1. Profiling SHM is recreated fresh (MAP_ANONYMOUS, not persistent).
2. OPcache SHM is also fresh.
3. A request loads `foo.php` from file cache → serialized blob is copied into new
   OPcache SHM and then unserialized there.
4. `reserved[slot]` contains `FunctionIndex(42)` from the old profiling SHM.
5. New profiling SHM: index 42 may be a completely different function (e.g., a built-in
   that `intern_all_functions` assigned index 42 to).
6. Stack walk reads `FunctionIndex(42)` → wrong function name in profiles.

This is a correctness bug (wrong names), not just a missing-names issue.

**When OPcache SHM restarts but PHP process does not** (OOM/hash overflow/`opcache_reset`):
the profiling SHM (MAP_ANONYMOUS inherited via fork) is NOT recreated. The stale
`reserved[]` values from the file cache still reference the same profiling SHM, so
they remain valid. The bug only manifests on PHP process restart with warm file cache.

## `ZEND_ACC_IMMUTABLE` Flag

Present in **PHP 7.3+**. Absent in 7.1–7.2. Its exact bit layout and surrounding
semantics changed significantly at 7.4; see per-version notes below.

Set on functions and classes during `zend_persist_op_array()` (SHM persistence path).
The flag is baked into the file cache when the op_array is serialized to disk.

### PHP 7.3 semantics (different from 7.4+)

In PHP 7.3 the flag lives at bit 25 (`1 << 25` in `Zend/zend_compile.h`), versus bit 7
in 7.4+. More importantly, the *runtime* behavior is different: `init_func_run_time_cache_i`
in `Zend/zend_execute.c` detects the flag, **copies the op_array into the per-process arena**,
and then **clears** the flag on the copy. By the time any user-mode code runs, the op_array
is already in per-process memory with the flag cleared. The flag therefore cannot be used as
a "this is in SHM" test in PHP 7.3.

In PHP 7.4 this copy was eliminated via `ZEND_MAP_PTR` indirection, which is why immutable
op_arrays can stay in SHM from 7.4 onward.

### Behavior when loading from file cache:

**PHP 7.4 / 8.0:** `zend_file_cache_unserialize_op_array` mostly follows the serialized
`fn_flags` value rather than explicitly re-deriving `ZEND_ACC_IMMUTABLE` from
SHM-vs-process-memory placement. In `file_cache_only` mode the flag can therefore be
set even though the op_array is in process memory, so it is not a reliable
"this is in SHM" test in those versions.

**PHP 8.1–8.5:** `zend_file_cache_unserialize_op_array` explicitly manages the flag for
non-main op_arrays based on whether the script was restored into SHM or process memory:
```c
if (!script->corrupted) {           // in SHM
    op_array->fn_flags |= ZEND_ACC_IMMUTABLE;
} else {                            // in process memory
    op_array->fn_flags &= ~ZEND_ACC_IMMUTABLE;
}
```
Important exception: `main_op_array` is special-cased and is **not** simply forced to
immutable on the SHM path, so this is only a reliable test for non-main op_arrays.

### Implications for writing to `reserved[]`

For a non-main op_array without `ZEND_ACC_IMMUTABLE` (on 8.1+), the op_array is in
per-process, per-request memory. Writing to its `reserved[]` is race-free (no other
workers see it). However:
- There is no `zend_extension` hook that fires when an op_array is loaded from file cache.
  `op_array_handler` is compilation-only.
- Any write to a process-memory op_array evaporates at request end; the next request
  gets a fresh copy from the file cache with the original stale value.
- Writing in the stack-walk signal handler requires acquiring the profiling SHM spinlock,
  which is undesirable.

The per-request limitation is a known tradeoff. Prior to the `reserved[]` + SHM approach
the profiler used the run-time cache (also per-request) and this was still considered
valuable — so a per-request fallback path for file-cache mode is viable in principle.

## `opcache.file_cache` INI Validation History

PHP 7.1–8.4: `OnUpdateFileCache` validates the path at INI registration time:
- Must be absolute (`IS_ABSOLUTE_PATH`)
- Must exist and be a directory (`zend_stat` + `S_ISDIR`)
- Must be accessible: `access(path, R_OK|W_OK|X_OK)`
- **Failure is silent**: value is set to NULL (file cache effectively disabled).

PHP 8.5: validation removed from `OnUpdateFileCache`. Validation moved to
`accel_post_startup` instead:
- Same `IS_ABSOLUTE_PATH` + `zend_stat` + `S_ISDIR` checks, with the `access` mode
  depending on `opcache.file_cache_read_only`.
- **Failure is fatal**: `ACCEL_LOG_FATAL` + `accel_startup_ok = false`.

Because 8.5 failures are fatal, if PHP is running and we reach our startup hook,
any non-empty `opcache.file_cache` value has already passed validation (or PHP died).

## Detecting File Cache at Startup (`cfg_get_string`)

`cfg_get_string` reads from `configuration_hash`, populated when php.ini is parsed,
before normal module startup/MINIT runs. It returns the configured string regardless
of OPcache's load order. The signature changes from `int` (7.1–8.4) to `zend_result`
(8.5), but both use the same `SUCCESS` / `FAILURE` convention.

To replicate OPcache's 7.1–8.4 validation and avoid false positives:

```c
bool ddog_php_prof_opcache_file_cache_enabled(void) {
    char *file_cache = NULL;
    if (cfg_get_string("opcache.file_cache", &file_cache) != SUCCESS
        || file_cache == NULL || *file_cache == '\0') {
        return false;
    }
    if (!IS_ABSOLUTE_PATH(file_cache, strlen(file_cache))) {
        return false;
    }
    zend_stat_t buf = {0};
    if (zend_stat(file_cache, &buf) != 0 || !S_ISDIR(buf.st_mode)) {
        return false;
    }
#ifndef ZEND_WIN32
    if (access(file_cache, R_OK | X_OK) != 0) {
        return false;
    }
#else
    if (_access(file_cache, 05) != 0) {
        return false;
    }
#endif
    return true;
}
```

Using `R_OK|X_OK` (not `R_OK|W_OK|X_OK`) intentionally matches the PHP 8.5
`opcache.file_cache_read_only=1` behavior and correctly includes read-only cache
directories on that version. On 7.1–8.4, and on 8.5 when
`opcache.file_cache_read_only=0`, this may yield a minor false positive for
read-only cache dirs that OPcache would reject, but those are uncommon and the
consequence is only an unnecessary warning.

## Runtime Write Policy for `reserved[]`

Assumptions for the table below:

- Only user `zend_op_array`s are in scope.
- Trampolines / synthetic reused engine frames are excluded.
- If file cache is enabled in any form, we never write `zend_op_array.reserved`.
- Our persist hook writes a non-zero `FunctionIndex` into the reserved slot for
  SHM-persisted op_arrays.

Under those assumptions, the practical policy is:

| PHP versions | OPcache state | Safe to write when `reserved[slot] == 0 && !(fn_flags & ZEND_ACC_IMMUTABLE)`? | Why |
|--------------|---------------|---------------------------------------------------------------------------------|-----|
| 7.1–7.4, 8.0 | OPcache disabled | Yes | No OPcache SHM-backed op_arrays to worry about. The write is local. |
| 7.1–7.4, 8.0 | OPcache enabled, file cache disabled | No | `!ZEND_ACC_IMMUTABLE` is not a reliable "not in SHM" test in this band, and in 7.1/7.2 the flag is not even available. |
| 7.1–7.4, 8.0 | File cache enabled in any form | No | By policy, skip writes entirely and avoid stale file-cache replay issues. |
| 8.1–8.5 | OPcache disabled | Yes | No OPcache SHM-backed op_arrays to worry about. The write is local. |
| 8.1–8.5 | OPcache enabled, file cache disabled | Yes, under the assumptions above | SHM-persisted op_arrays should already have a non-zero slot from the persist hook, so `reserved == 0 && !IMMUTABLE` is a workable signal for a local/non-SHM case. |
| 8.1–8.5 | File cache enabled in any form | No | By policy, skip writes entirely and avoid stale file-cache replay issues. |

Brief context behind the split:

- `ext/opcache/zend_persist.c::zend_persist_op_array_ex()` is where OPcache calls
  `zend_extensions_op_array_persist()`, so freshly SHM-persisted op_arrays do go
  through the zend-extension persist hook.
- `ext/opcache/zend_file_cache.c::zend_file_cache_script_load()` restores cached
  scripts from file cache without rerunning the persist hook, which is why file cache
  must be treated as a separate unsafe case.
- `ext/opcache/zend_accelerator_util_funcs.c::zend_accel_load_script()` allocates a
  fresh `main_op_array` copy for execution, so the executing main op_array is local.
- `ext/opcache/zend_persist.c::zend_persist_class_method()` can place class-method
  op_arrays into OPcache SHM even when `ZEND_ACC_IMMUTABLE` is not set, which is why
  `!ZEND_ACC_IMMUTABLE` by itself is not a safe test in 7.4/8.0 and earlier versions.
- In PHP 8.1+, `ext/opcache/zend_file_cache.c::zend_file_cache_unserialize_op_array()`
  explicitly manages `ZEND_ACC_IMMUTABLE` based on SHM-vs-process-memory restore, but
  the table above still assumes file cache is entirely opted out.
