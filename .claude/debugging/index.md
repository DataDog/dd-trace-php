# Debugging guides

How to debug this project's components locally. Each file covers one scenario.

| Guide | Covers |
|---|---|
| [gdb.md](gdb.md) | gdb-via-tmux fundamentals: attaching, scripting, watchpoints, reading optimized-out vars, C-vs-Rust language mode, attaching to the sidecar. Start here for any native (C/Rust) debugging. |
| [appsec-integration.md](appsec-integration.md) | Debugging the appsec Gradle integration tests: driving containers with `jdb` (`--debug-jvm`) + gdb, breakpoint strategy, keeping the container alive, the sidecar watchdog. |
| [system-tests.md](system-tests.md) | Debugging system tests locally (arm64): pytest `--pdb` + gdb inside the weblog container. |

Related: for *building* the binaries you debug, see
[../ci/building-locally.md](../ci/building-locally.md); for reproducing a
specific CI job, see [../ci/index.md](../ci/index.md).
