// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#ifndef _GNU_SOURCE
#    define _GNU_SOURCE // dl_iterate_phdr, RTLD_NOLOAD, RTLD_DEFAULT
#endif

#include "curl_linkage_detect.h"

#include <stdarg.h>
#include <stdio.h>
#include <string.h>

#if defined(__linux__) && (defined(__x86_64__) || defined(__aarch64__))
#    define DD_CURL_LINKAGE_SUPPORTED 1
#endif

static void _set_reason(struct dd_curl_linkage *out, const char *fmt, ...)
    __attribute__((format(printf, 2, 3)));

#ifndef DD_CURL_LINKAGE_SUPPORTED

void dd_curl_linkage_detect(const void *anchor, struct dd_curl_linkage *out)
{
    (void)anchor;
    memset(out, 0, sizeof(*out));
    _set_reason(out, "unsupported platform");
}

#else

#    include <dlfcn.h>
#    include <elf.h>
#    include <link.h>
#    include <stdint.h>

// Procedure:
//  1. EXT = the loaded object whose PT_LOAD segments contain `anchor`.
//  2. EXT must import curl_global_init through a JUMP_SLOT/GLOB_DAT
//     relocation (no relocation: libcurl is linked in at link time).
//  3. curl_global_init's GOT slot is bound, because ext/curl's MINIT called
//     it, even under lazy binding. The object LCURL containing the slot's
//     target is the libcurl ext/curl is bound to. LCURL != EXT and LCURL's
//     DT_SONAME must be in EXT's DT_NEEDED. This is the only binding check:
//     a libcurl linked into EXT, or one preloaded in its place, binds the
//     slot to EXT itself or to an object EXT does not depend on. A preload
//     interposing only some other curl_* functions is not detected.
//  4. Resolve the functions with dlsym on LCURL's own handle; every pointer
//     must lie inside LCURL.

#    ifdef __x86_64__
#        define DD_R_JUMP_SLOT R_X86_64_JUMP_SLOT
#        define DD_R_GLOB_DAT R_X86_64_GLOB_DAT
#    else
#        define DD_R_JUMP_SLOT R_AARCH64_JUMP_SLOT
#        define DD_R_GLOB_DAT R_AARCH64_GLOB_DAT
#    endif

struct _object {
    ElfW(Addr) base;
    const ElfW(Phdr) * phdr;
    ElfW(Half) phnum;
    const char *name;
};

struct _dynamic {
    const ElfW(Dyn) * dyn;
    const char *strtab;
    const ElfW(Sym) * symtab;
    const ElfW(Rela) * jmprel;
    size_t jmprel_size;
    const ElfW(Rela) * rela;
    size_t rela_size;
    const char *soname;
};

// DD_CURL_FUNCTIONS in declaration order
#    define DD_CURL_FN_NAME(name, ret, params) #name,
static const char *const _names[] = {DD_CURL_FUNCTIONS(DD_CURL_FN_NAME)};
#    undef DD_CURL_FN_NAME
#    define DD_NAMES_COUNT (sizeof(_names) / sizeof(_names[0]))

static bool _find_object(const void *addr, struct _object *obj);
static const char *_dname(const struct _object *obj);
static bool _contains(const struct _object *obj, const void *addr);
static bool _parse_dynamic(const struct _object *obj, struct _dynamic *dyn);
static void **_find_global_init_slot(
    const struct _object *obj, const struct _dynamic *dyn);
static bool _has_needed(const struct _dynamic *dyn, const char *soname);
static bool _resolve(const struct _object *lcurl, struct dd_curl_linkage *out);

void dd_curl_linkage_detect(const void *anchor, struct dd_curl_linkage *out)
{
    memset(out, 0, sizeof(*out));

    struct _object ext;
    if (!_find_object(anchor, &ext)) {
        _set_reason(out, "no loaded object contains ext/curl's code");
        return;
    }
    struct _dynamic dext;
    if (!_parse_dynamic(&ext, &dext)) {
        _set_reason(out,
            "object containing ext/curl ('%s') has no usable dynamic section",
            _dname(&ext));
        return;
    }

    void **slot = _find_global_init_slot(&ext, &dext);
    if (!slot) {
        // calls bound at link time (e.g. static-php-cli builds)
        _set_reason(out,
            "'%s' has no dynamic reference to curl_global_init (libcurl "
            "statically linked)",
            _dname(&ext));
        return;
    }
    // bound even under lazy binding: ext/curl's MINIT called it
    void *target = *slot;
    struct _object lcurl;
    if (!_find_object(target, &lcurl) || lcurl.base == ext.base) {
        _set_reason(out,
            "curl_global_init is not bound to another object (target %p)",
            target);
        return;
    }
    struct _dynamic dlcurl;
    if (!_parse_dynamic(&lcurl, &dlcurl) || !dlcurl.soname) {
        _set_reason(out, "'%s' (curl_global_init's object) has no DT_SONAME",
            _dname(&lcurl));
        return;
    }
    if (!_has_needed(&dext, dlcurl.soname)) {
        _set_reason(out, "'%s' is not in the DT_NEEDED of '%s'", dlcurl.soname,
            _dname(&ext));
        return;
    }

    if (!_resolve(&lcurl, out)) {
        return;
    }

    const dd_curl_version_info_data *vi =
        out->funcs.curl_version_info(DD_CURLVERSION_FIRST);
    if (!vi || !vi->version) {
        memset(&out->funcs, 0, sizeof(out->funcs));
        _set_reason(out, "curl_version_info() returned no version");
        return;
    }
    // Global lookup scope, as for libcurl's own calloc: an interposing
    // allocator (ASAN's interceptors in the executable) is found before
    // libc's. Not called directly: ddtrace.so is built against musl and also
    // loaded by glibc, and the function may be missing.
    out->malloc_usable_size =
        (size_t (*)(void *))dlsym(RTLD_DEFAULT, "malloc_usable_size");
    out->libcurl_version_num = vi->version_num;
    out->libcurl_features = vi->features;
    (void)snprintf(
        out->libcurl_version, sizeof(out->libcurl_version), "%s", vi->version);
    (void)snprintf(
        out->libcurl_path, sizeof(out->libcurl_path), "%s", lcurl.name);
    out->enabled = true;
    _set_reason(out, "ext/curl in '%s' is bound to dynamic libcurl %s",
        _dname(&ext), dlcurl.soname);
}

static bool _resolve(const struct _object *lcurl, struct dd_curl_linkage *out)
{
    if (!lcurl->name[0]) {
        _set_reason(out, "libcurl object has no path");
        return false;
    }
    // the exact path, not the SONAME: it must not open a different object
    void *handle = dlopen(lcurl->name, RTLD_NOLOAD | RTLD_LAZY);
    if (!handle) {
        // only runs at startup, before any thread is spawned
        const char *err = dlerror(); // NOLINT(concurrency-mt-unsafe)
        _set_reason(out, "dlopen(RTLD_NOLOAD) of '%s' failed: %s", lcurl->name,
            err ? err : "unknown error");
        return false;
    }

    void *ptrs[DD_NAMES_COUNT] = {0};
    bool ok = true;
    for (size_t i = 0; i < DD_NAMES_COUNT && ok; i++) {
        void *p = dlsym(handle, _names[i]);
        if (!p || !_contains(lcurl, p)) {
            _set_reason(out, "dlsym(%s) on '%s' gave %p, outside it", _names[i],
                lcurl->name, p);
            ok = false;
        }
        ptrs[i] = p;
    }
    // the object stays loaded: ext/curl depends on it
    dlclose(handle);
    if (!ok) {
        return false;
    }

    size_t idx = 0;
    // NOLINTBEGIN(bugprone-macro-parentheses)
#    define DD_CURL_FN_ASSIGN(name, ret, params)                               \
        out->funcs.name = (ret(*) params)ptrs[idx++];
    DD_CURL_FUNCTIONS(DD_CURL_FN_ASSIGN)
#    undef DD_CURL_FN_ASSIGN
    // NOLINTEND(bugprone-macro-parentheses)
    return true;
}

struct _find_ctx {
    uintptr_t addr;
    struct _object *obj;
    bool found;
};

static int _find_object_cb(struct dl_phdr_info *info, size_t size, void *data)
{
    (void)size;
    struct _find_ctx *ctx = data;
    struct _object cand = {
        .base = info->dlpi_addr,
        .phdr = info->dlpi_phdr,
        .phnum = info->dlpi_phnum,
        .name = info->dlpi_name ? info->dlpi_name : "",
    };
    if (_contains(&cand, (const void *)ctx->addr)) {
        *ctx->obj = cand;
        ctx->found = true;
        return 1;
    }
    return 0;
}

// Matches on load address, never on name: glibc reports "" for the main
// program, and names are not unique.
static bool _find_object(const void *addr, struct _object *obj)
{
    struct _find_ctx ctx = {.addr = (uintptr_t)addr, .obj = obj};
    if (!addr) {
        return false;
    }
    dl_iterate_phdr(_find_object_cb, &ctx);
    return ctx.found;
}

static const char *_dname(const struct _object *obj)
{
    return obj->name[0] ? obj->name : "(main program)";
}

static bool _contains(const struct _object *obj, const void *addr)
{
    uintptr_t a = (uintptr_t)addr;
    for (ElfW(Half) i = 0; i < obj->phnum; i++) {
        const ElfW(Phdr) *ph = &obj->phdr[i];
        if (ph->p_type != PT_LOAD) {
            continue;
        }
        uintptr_t start = obj->base + ph->p_vaddr;
        if (a >= start && a - start < ph->p_memsz) {
            return true;
        }
    }
    return false;
}

// The d_ptr entries of PT_DYNAMIC (DT_STRTAB etc.) of ext/curl's and
// libcurl's objects hold addresses relative to the load base in the file.
// glibc relocates them in place when it loads an object, unless its dynamic
// section is read-only (e.g. linked with lld's -z rodynamic, glibc >= 2.35);
// musl never does. Which of the two loaded the object cannot be known at
// compile time: ddtrace.so is built against musl and also loaded by glibc. So
// a value is taken as an address if it lies
// inside the object, and as an offset from the load base if base + value
// does. Anything else, or both (only possible for an object mapped at an
// address below its own size), is rejected (0).
static uintptr_t _dptr(const struct _object *obj, ElfW(Addr) value)
{
    bool as_addr = _contains(obj, (const void *)value);
    if (obj->base == 0) {
        return as_addr ? value : 0;
    }
    bool as_off = _contains(obj, (const void *)(obj->base + value));
    if (as_addr == as_off) {
        return 0;
    }
    return as_addr ? value : obj->base + value;
}

static bool _parse_dynamic(const struct _object *obj, struct _dynamic *dyn)
{
    memset(dyn, 0, sizeof(*dyn));
    for (ElfW(Half) i = 0; i < obj->phnum; i++) {
        if (obj->phdr[i].p_type == PT_DYNAMIC) {
            dyn->dyn = (const ElfW(Dyn) *)(obj->base + obj->phdr[i].p_vaddr);
            break;
        }
    }
    if (!dyn->dyn) {
        return false;
    }

    ElfW(Xword) soname_off = 0;
    bool has_soname = false;
    for (const ElfW(Dyn) *e = dyn->dyn; e->d_tag != DT_NULL; e++) {
        switch (e->d_tag) {
        case DT_STRTAB:
            dyn->strtab = (const char *)_dptr(obj, e->d_un.d_ptr);
            if (!dyn->strtab) {
                return false;
            }
            break;
        case DT_SYMTAB:
            dyn->symtab = (const ElfW(Sym) *)_dptr(obj, e->d_un.d_ptr);
            if (!dyn->symtab) {
                return false;
            }
            break;
        case DT_JMPREL:
            dyn->jmprel = (const ElfW(Rela) *)_dptr(obj, e->d_un.d_ptr);
            if (!dyn->jmprel) {
                return false;
            }
            break;
        case DT_PLTRELSZ:
            dyn->jmprel_size = e->d_un.d_val;
            break;
        case DT_PLTREL:
            // x86_64 and aarch64 only use RELA
            if (e->d_un.d_val != DT_RELA) {
                return false;
            }
            break;
        case DT_RELA:
            dyn->rela = (const ElfW(Rela) *)_dptr(obj, e->d_un.d_ptr);
            if (!dyn->rela) {
                return false;
            }
            break;
        case DT_RELASZ:
            dyn->rela_size = e->d_un.d_val;
            break;
        case DT_SONAME:
            soname_off = e->d_un.d_val;
            has_soname = true;
            break;
        default:
            break;
        }
    }
    if (!dyn->strtab || !dyn->symtab) {
        return false;
    }
    if (has_soname) {
        dyn->soname = dyn->strtab + soname_off;
    }
    return true;
}

static void **_find_global_init_slot(
    const struct _object *obj, const struct _dynamic *dyn)
{
    const ElfW(Rela) * tables[] = {dyn->jmprel, dyn->rela};
    size_t sizes[] = {dyn->jmprel_size, dyn->rela_size};
    for (size_t t = 0; t < sizeof(tables) / sizeof(tables[0]); t++) {
        if (!tables[t]) {
            continue;
        }
        size_t count = sizes[t] / sizeof(ElfW(Rela));
        for (size_t i = 0; i < count; i++) {
            const ElfW(Rela) *r = &tables[t][i];
            uint32_t type = ELF64_R_TYPE(r->r_info);
            uint32_t sym_idx = ELF64_R_SYM(r->r_info);
            if ((type != DD_R_JUMP_SLOT && type != DD_R_GLOB_DAT) ||
                sym_idx == 0) {
                continue;
            }
            const ElfW(Sym) *sym = &dyn->symtab[sym_idx];
            if (strcmp(dyn->strtab + sym->st_name, "curl_global_init") == 0) {
                return (void **)(obj->base + r->r_offset);
            }
        }
    }
    return NULL;
}

static bool _has_needed(const struct _dynamic *dyn, const char *soname)
{
    for (const ElfW(Dyn) *e = dyn->dyn; e->d_tag != DT_NULL; e++) {
        if (e->d_tag == DT_NEEDED &&
            strcmp(dyn->strtab + e->d_un.d_val, soname) == 0) {
            return true;
        }
    }
    return false;
}

#endif // DD_CURL_LINKAGE_SUPPORTED

static void _set_reason(struct dd_curl_linkage *out, const char *fmt, ...)
{
    va_list ap;
    va_start(ap, fmt);
    (void)vsnprintf(out->reason, sizeof(out->reason), fmt, ap);
    va_end(ap);
}
