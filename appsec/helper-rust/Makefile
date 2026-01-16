MUSL_GCC_SYSROOT_AMD64 := $(realpath $(shell /opt/homebrew/bin/x86_64-linux-musl-gcc -v -E - < /dev/null 2>&1 | grep -o -- '-isysroot [^ ]*'| awk '{print $$2}'))
$(info MUSL_GCC_SYSROOT_AMD64 is '$(MUSL_GCC_SYSROOT_AMD64)')
bcross_linux_amd64:
	LIBDDWAF_PREFIX=/opt/libddwaf-v2-linux RUSTC_LOG=rustc_codegen_ssa::back::link=debug \
	BINDGEN_EXTRA_CLANG_ARGS="--sysroot=$(MUSL_GCC_SYSROOT_AMD64) --target=x86_64-linux-musl" \
	RUSTFLAGS='-C linker=/opt/homebrew/bin/x86_64-linux-musl-gcc -C target-feature=-crt-static -L $(MUSL_GCC_SYSROOT_AMD64)/lib' \
	cargo build --target x86_64-unknown-linux-musl
	/opt/homebrew/bin/patchelf --remove-needed libc.so target/x86_64-unknown-linux-musl/debug/libddappsec_helper_rust.so
.PHONY: bcross_linux_amd64

MUSL_GCC_SYSROOT := $(realpath $(shell /opt/homebrew/bin/aarch64-linux-musl-gcc -v -E - < /dev/null 2>&1 | grep -o -- '-isysroot [^ ]*'| awk '{print $$2}'))
$(info MUSL_GCC_SYSROOT is '$(MUSL_GCC_SYSROOT)')
bcross_linux_aarch64:
	LIBDDWAF_PREFIX=/opt/libddwaf-v2-linux RUSTC_LOG=rustc_codegen_ssa::back::link=debug \
        BINDGEN_EXTRA_CLANG_ARGS="--sysroot=$(MUSL_GCC_SYSROOT) --target=aarch64-linux-musl" \
	RUSTFLAGS='-C linker=/opt/homebrew/bin/aarch64-linux-musl-gcc -C target-feature=-crt-static -L $(MUSL_GCC_SYSROOT)/lib' \
        cargo build --target aarch64-unknown-linux-musl
	/opt/homebrew/bin/patchelf --remove-needed libc.so target/aarch64-unknown-linux-musl/debug/libddappsec_helper_rust.so
.PHONY: bcross_linux_aarch64

