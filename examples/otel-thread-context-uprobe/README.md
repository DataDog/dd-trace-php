# OTel Thread Context Uprobe

Small libbpf-bootstrap-style diagnostic program for reading `otel_thread_ctx_v1` when PHP enters
`zif_sleep`.

libdatadog currently provides the publisher API and the process-context publisher. I did not find an
external client API for reading another process' OTel process context or resolving its TLS slot, so
this sample implements the reader side directly in C++ and BPF.

## Build

The build expects a Linux host with BTF, clang, bpftool, libbpf, libelf, and zlib development files:

```sh
make
```

## Run

Pass only the target PHP process id:

```sh
sudo ./otel_span_uprobe <pid>
```

The target process must have published an `OTEL_CTX` mapping with:

- `threadlocal.tls_module_id`
- `threadlocal.tls_block_offset`
- `threadlocal.libc`

The loader finds the target process' executable `libphp` mapping from `/proc/<pid>/maps`, asks
libbpf to attach to the `zif_sleep` symbol in that shared library, and prints the current thread's
span id whenever `sleep()` enters the engine.

Supported TLS layouts:

- x86_64 glibc
- x86_64 musl
- arm64 glibc
- arm64 musl
