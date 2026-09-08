#ifdef _WIN32

#include "hang_watchdog_windows.h"

#include <windows.h>
// dbghelp.h must follow windows.h.
#include <dbghelp.h>
#include <psapi.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

static volatile LONG dd_watchdog_armed = 0;
static HANDLE dd_watchdog_main_thread = NULL;

// Write straight to the OS stdout handle: the main thread is suspended while we
// dump and may hold the CRT stdio lock, so fwrite/printf could deadlock.
static void dd_watchdog_write(const char *buf, size_t len) {
    HANDLE h = GetStdHandle(STD_OUTPUT_HANDLE);
    if (h == NULL || h == INVALID_HANDLE_VALUE) {
        return;
    }
    DWORD written;
    WriteFile(h, buf, (DWORD)len, &written, NULL);
}

static void dd_watchdog_puts(const char *s) { dd_watchdog_write(s, strlen(s)); }

static void dd_watchdog_dump_stack(void) {
    HANDLE process = GetCurrentProcess();
    HANDLE thread = dd_watchdog_main_thread;
    if (thread == NULL) {
        return;
    }

    if (SuspendThread(thread) == (DWORD)-1) {
        return;
    }

    CONTEXT ctx;
    memset(&ctx, 0, sizeof(ctx));
    ctx.ContextFlags = CONTEXT_FULL;
    if (!GetThreadContext(thread, &ctx)) {
        ResumeThread(thread);
        return;
    }

    // Guaranteed lock-free datum: the raw faulting PC. Even if the symbolized
    // walk below wedges (e.g. the suspended thread holds a heap/loader lock),
    // this address is already emitted for offline symbolization with the PDB.
    char pc[128];
    int pcn = snprintf(pc, sizeof(pc),
                       "hang watchdog: main-thread PC = 0x%llx\n",
                       (unsigned long long)ctx.Rip);
    if (pcn > 0) {
        dd_watchdog_write(pc, (size_t)pcn);
    }

    SymSetOptions(SYMOPT_UNDNAME | SYMOPT_DEFERRED_LOADS | SYMOPT_LOAD_LINES);
    SymInitialize(process, NULL, TRUE);

    STACKFRAME64 frame;
    memset(&frame, 0, sizeof(frame));
    frame.AddrPC.Offset = ctx.Rip;
    frame.AddrPC.Mode = AddrModeFlat;
    frame.AddrFrame.Offset = ctx.Rbp;
    frame.AddrFrame.Mode = AddrModeFlat;
    frame.AddrStack.Offset = ctx.Rsp;
    frame.AddrStack.Mode = AddrModeFlat;

    // Union guarantees SYMBOL_INFO's 8-byte alignment while reserving the
    // trailing space its variable-length Name field needs.
    union {
        SYMBOL_INFO info;
        char buf[sizeof(SYMBOL_INFO) + MAX_SYM_NAME * sizeof(char)];
    } symstore;
    char line[1200];
    for (int i = 0; i < 128; i++) {
        if (!StackWalk64(IMAGE_FILE_MACHINE_AMD64, process, thread, &frame, &ctx,
                         NULL, SymFunctionTableAccess64, SymGetModuleBase64,
                         NULL)) {
            break;
        }
        DWORD64 addr = frame.AddrPC.Offset;
        if (addr == 0) {
            break;
        }

        DWORD64 mod_base = SymGetModuleBase64(process, addr);
        char mod_name[MAX_PATH] = "?";
        if (mod_base) {
            GetModuleBaseNameA(process, (HMODULE)(uintptr_t)mod_base, mod_name,
                               sizeof(mod_name));
        }
        DWORD64 rva = mod_base ? addr - mod_base : 0;

        SYMBOL_INFO *sym = &symstore.info;
        memset(&symstore, 0, sizeof(symstore));
        sym->SizeOfStruct = sizeof(SYMBOL_INFO);
        sym->MaxNameLen = MAX_SYM_NAME;
        DWORD64 disp = 0;
        int n;
        if (SymFromAddr(process, addr, &disp, sym)) {
            IMAGEHLP_LINE64 src;
            memset(&src, 0, sizeof(src));
            src.SizeOfStruct = sizeof(src);
            DWORD line_disp = 0;
            if (SymGetLineFromAddr64(process, addr, &line_disp, &src)) {
                n = snprintf(line, sizeof(line),
                             "  #%02d %s!%s+0x%llx (%s:%lu)  [%s+0x%llx]\n", i,
                             mod_name, sym->Name, (unsigned long long)disp,
                             src.FileName, (unsigned long)src.LineNumber,
                             mod_name, (unsigned long long)rva);
            } else {
                n = snprintf(line, sizeof(line),
                             "  #%02d %s!%s+0x%llx  [%s+0x%llx]\n", i, mod_name,
                             sym->Name, (unsigned long long)disp, mod_name,
                             (unsigned long long)rva);
            }
        } else {
            n = snprintf(line, sizeof(line), "  #%02d %s+0x%llx  [0x%llx]\n", i,
                         mod_name, (unsigned long long)rva,
                         (unsigned long long)addr);
        }
        if (n > 0) {
            // snprintf returns the would-be length; clamp so an over-long
            // (e.g. mangled Rust) frame cannot over-read past `line`.
            size_t wlen = n < (int)sizeof(line) ? (size_t)n : sizeof(line) - 1;
            dd_watchdog_write(line, wlen);
        }
    }

    SymCleanup(process);
    ResumeThread(thread);
}

static DWORD WINAPI dd_watchdog_thread(LPVOID param) {
    DWORD timeout_ms = (DWORD)(uintptr_t)param;
    Sleep(timeout_ms);

    // Wording deliberately avoids run-tests.php's flaky-retry keywords
    // ("timed out", "deadlock", ...) so this is reported as a plain failure.
    dd_watchdog_puts(
        "\n===== ddtrace hang watchdog fired =====\n"
        "Request teardown did not finish within budget; dumping the main-thread\n"
        "stack, then aborting so the test suite keeps running.\n");
    dd_watchdog_dump_stack();
    dd_watchdog_puts("===== ddtrace hang watchdog: end =====\n");
    FlushFileBuffers(GetStdHandle(STD_OUTPUT_HANDLE));

    // Abort via TerminateProcess (not exit()): the CRT/atexit path may be the
    // very thing wedged. The clean process kill also releases the file handles
    // that would otherwise make run-tests.php's retry-write fail on Windows.
    TerminateProcess(GetCurrentProcess(), 3);
    return 0;
}

void ddtrace_arm_teardown_hang_watchdog(void) {
    const char *env = getenv("_DD_TEST_HANG_WATCHDOG_SEC");
    if (env == NULL || env[0] == '\0') {
        return;
    }
    long secs = strtol(env, NULL, 10);
    if (secs <= 0) {
        return;
    }
    if (secs > 3600) {
        secs = 3600;  // guard the ms cast against overflow
    }
    if (InterlockedCompareExchange(&dd_watchdog_armed, 1, 0) != 0) {
        return;  // arm at most once per process
    }

    // GetCurrentThread() is a pseudo-handle only valid on the calling thread, so
    // hand the watchdog a real, duplicated handle to the main thread.
    if (!DuplicateHandle(GetCurrentProcess(), GetCurrentThread(),
                         GetCurrentProcess(), &dd_watchdog_main_thread, 0, FALSE,
                         DUPLICATE_SAME_ACCESS)) {
        dd_watchdog_main_thread = NULL;
        return;
    }

    HANDLE t = CreateThread(NULL, 0, dd_watchdog_thread,
                            (LPVOID)(uintptr_t)(DWORD)(secs * 1000), 0, NULL);
    if (t != NULL) {
        CloseHandle(t);
    }
}

#endif  // _WIN32
