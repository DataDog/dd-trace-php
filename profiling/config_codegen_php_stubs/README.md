# Stub PHP headers for `config_codegen.rs`

These files are **intentionally empty**. Do not add real content to them, and do not
delete them as unused.

`components-rs/config_codegen.rs` runs the C preprocessor over `ext/configuration.h`
purely to expand the `CONFIG()`/`CALIAS()` X-macro list into a flat, textual
`type, name` record per configuration entry -- it never compiles the result, and the
macro expansion does not depend on the *content* of any PHP header, only on the
`#include` directives resolving to *some* file on disk (`ext/configuration.h`
transitively includes real PHP headers like `Zend/zend_closures.h` for declarations
that this codegen step never looks at).

This directory contains empty stand-ins for exactly the header paths that chain
needs, discovered by iteratively preprocessing with `clang -E` and creating an empty
stub for whatever `fatal error: '...' file not found` came back next, until the
whole file preprocessed cleanly. If a future change to `ext/configuration.h` (or
anything it includes) adds a new `#include` of a real PHP header, preprocessing will
fail the same way with a clear "file not found" error naming the missing path --
add an empty file at that path here and it will resolve.

This lets `config_codegen.rs` run without a real PHP installation, which matters for
build contexts that share one Rust artifact across many PHP versions/ABIs (see
`buildPortableLibdatadogPhp` in `appsec/tests/integration/build.gradle`) where there
is no one "correct" PHP install to point at.
