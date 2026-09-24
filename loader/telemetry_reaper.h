#ifndef DDLOADER_TELEMETRY_REAPER_H
#define DDLOADER_TELEMETRY_REAPER_H

#include <pthread.h>
#include <stddef.h>
#include <sys/types.h>

typedef struct {
    void *mapping;
    size_t mapping_size;
    pthread_attr_t attributes;
} ddloader_reaper;

// Prepare before fork so a failure does not leave a child needing a reaper.
// Returns 0 on success or an error number on failure. Failure cleans up all
// resources; do not call ddloader_reaper_discard() after a failed prepare.
int ddloader_reaper_prepare(ddloader_reaper *reaper);

// Called in the parent after fork to launch a detached thread that reaps pid.
// The thread need not be joined: it releases its own code page when finished
// and can safely outlive the loader library.
//
// Returns 0 on success or an error number if thread creation fails.
// Consumes reaper in either case; do not call ddloader_reaper_discard()
// afterwards. On failure, the caller must reap pid.
int ddloader_reaper_start(ddloader_reaper *reaper, pid_t pid);

// Release a successfully prepared reaper without starting a thread. Call this:
// - in the child after a successful fork, before exec or exit;
// - in the parent if fork fails, or if it abandons the reaper without calling
//   ddloader_reaper_start().
// Do not call after prepare fails or after start returns, whether successfully
// or with an error: those paths already perform the necessary cleanup.
void ddloader_reaper_discard(ddloader_reaper *reaper);

#endif
