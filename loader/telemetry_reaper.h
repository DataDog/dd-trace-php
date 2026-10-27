#ifndef DDLOADER_TELEMETRY_REAPER_H
#define DDLOADER_TELEMETRY_REAPER_H

#include <sys/types.h>

// Start a detached reaper that can outlive the loader and frees its own code.
// Returns 0 on success, or an error number; on failure the caller must reap pid.
int ddloader_reaper_start(pid_t pid);

#endif
