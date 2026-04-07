General/misc instructions
=========================

¶1 When running long commands with Bash tool, don't use the pattern `my-cmd ...
| tail -10`, as that limits visibility into what's happening. Regardless of
whether the tool is invoked with or without `run_in_background`, use the
pattern `my-command ... | tee /tmp/$(mktemp command_log_XXXXX).log`, or just
use the output file returned by the tool call. Then, use the Read/Glob/Grep
tools to inspect the output file.

¶2 When writing .md files, use a line length of 80 characters. You may exceed it
when necessary (e.g. for tables).

¶3 Run make commands with `-j$(nproc)`.

¶4 Before running builds or tests that depend on submodules, ensure
the relevant submodules are initialised. See
[ci/building-locally.md](ci/building-locally.md#submodule-initialisation)
for which submodules each build target needs. Quick reference:

```bash
git submodule update --init \
  appsec/third_party/libddwaf \
  appsec/third_party/msgpack-c \
  appsec/third_party/cpp-base64 \
  libdatadog
```

¶5 Never "fix" tests by disabling them.

¶6 Before attempting to fix a problem, you must get to the bottom of it, and
present the ultimate (not proximal) reason for the problem. Your conclusions
must be accompanied by evidence: both from analyzing the code and from running
experiments. These experiments can include adding log messages and checking
their content or running debuggers.

¶7 In particular, never conclude that a problem is pre-existing or that it's
unrelated to our changes without running tests on `git merge-base HEAD
origin/master` (create a new worktree for this purpose) and verifying that it
also happens there.

¶8 Fixes must be verified by running tests. These may either be existing tests
or new tests that fail before the fix and pass afterwards.

¶9 To enforece ¶6-8, when the user explicitly asks for an problem to be
investigated, you MUST use the following template:

```
The user presented this problem:

> (fill in user query here)

I inspected the source code, and I found the following:

> (fill in your findinds, with references to source files and lines)

The following commands validate my hypothesis:

> (include the relevant commands ran, debugging steps taken, and if they run
> before or after your tentative changes -- if any --, or both)

My conclusion is:

> (fill in your conclusion here)
```
