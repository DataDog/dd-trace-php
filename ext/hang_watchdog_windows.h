#ifndef DATADOG_HANG_WATCHDOG_WINDOWS_H
#define DATADOG_HANG_WATCHDOG_WINDOWS_H

// Windows-only CI diagnostic. Arms a watchdog thread at request teardown; if
// teardown does not finish within _DD_TEST_HANG_WATCHDOG_SEC seconds it dumps
// the main thread's stack to stdout and terminates the process. This converts a
// silent >60s run-tests.php per-test timeout (which on Windows cascades into a
// suite-killing locked-file retry) into an early exit with an actionable stack.
// No-op unless _DD_TEST_HANG_WATCHDOG_SEC holds a positive integer.
#ifdef _WIN32
void ddtrace_arm_teardown_hang_watchdog(void);
#else
static inline void ddtrace_arm_teardown_hang_watchdog(void) {}
#endif

#endif  // DATADOG_HANG_WATCHDOG_WINDOWS_H
