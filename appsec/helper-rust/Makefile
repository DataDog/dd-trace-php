MUSL_GCC_SYSROOT_AMD64 := $(realpath $(shell /opt/homebrew/bin/x86_64-linux-musl-gcc -v -E - < /dev/null 2>&1 | grep -o -- '-isysroot [^ ]*'| awk '{print $$2}'))
$(info MUSL_GCC_SYSROOT_AMD64 is '$(MUSL_GCC_SYSROOT_AMD64)')

# Non-phony targets for libunwind downloads
target/libunwind-x86_64/libunwind.a target/libunwind-x86_64/liblzma.a:
	mkdir -p target/libunwind-x86_64
	docker run --rm --platform linux/amd64 alpine:3.21 sh -c \
		"apk add --no-cache llvm-libunwind-static xz-static > /dev/null 2>&1 && cat /usr/lib/libunwind.a" \
		> target/libunwind-x86_64/libunwind.a
	docker run --rm --platform linux/amd64 alpine:3.21 sh -c \
		"apk add --no-cache llvm-libunwind-static xz-static > /dev/null 2>&1 && cat /usr/lib/liblzma.a" \
		> target/libunwind-x86_64/liblzma.a

bcross_linux_amd64: target/libunwind-x86_64/libunwind.a target/libunwind-x86_64/liblzma.a
	RUSTC_LOG=rustc_codegen_ssa::back::link=debug \
	BINDGEN_EXTRA_CLANG_ARGS="--sysroot=$(MUSL_GCC_SYSROOT_AMD64) --target=x86_64-linux-musl" \
	AR=/opt/homebrew/opt/binutils/bin/ar \
	CARGO_TARGET_X86_64_UNKNOWN_LINUX_MUSL_RUSTFLAGS='-C linker=/opt/homebrew/bin/x86_64-linux-musl-gcc -C target-feature=-crt-static -L $(MUSL_GCC_SYSROOT_AMD64)/lib -L target/libunwind-x86_64' \
	cargo +nightly build \
		--target x86_64-unknown-linux-musl
	/opt/homebrew/bin/patchelf --remove-needed libc.so target/x86_64-unknown-linux-musl/debug/libddappsec_helper_rust.so
.PHONY: bcross_linux_amd64

MUSL_GCC_SYSROOT := $(realpath $(shell /opt/homebrew/bin/aarch64-linux-musl-gcc -v -E - < /dev/null 2>&1 | grep -o -- '-isysroot [^ ]*'| awk '{print $$2}'))
$(info MUSL_GCC_SYSROOT is '$(MUSL_GCC_SYSROOT)')

# Non-phony targets for libunwind downloads
target/libunwind-aarch64/libunwind.a target/libunwind-aarch64/liblzma.a:
	mkdir -p target/libunwind-aarch64
	docker run --rm --platform linux/arm64 alpine:3.21 sh -c \
		"apk add --no-cache llvm-libunwind-static xz-static > /dev/null 2>&1 && cat /usr/lib/libunwind.a" \
		> target/libunwind-aarch64/libunwind.a
	docker run --rm --platform linux/arm64 alpine:3.21 sh -c \
		"apk add --no-cache llvm-libunwind-static xz-static > /dev/null 2>&1 && cat /usr/lib/liblzma.a" \
		> target/libunwind-aarch64/liblzma.a

bcross_linux_aarch64: target/libunwind-aarch64/libunwind.a target/libunwind-aarch64/liblzma.a
	RUSTC_LOG=rustc_codegen_ssa::back::link=debug \
        BINDGEN_EXTRA_CLANG_ARGS="--sysroot=$(MUSL_GCC_SYSROOT) --target=aarch64-linux-musl" \
	AR=/opt/homebrew/opt/binutils/bin/ar \
	CARGO_TARGET_AARCH64_UNKNOWN_LINUX_MUSL_RUSTFLAGS='-C linker=/opt/homebrew/bin/aarch64-linux-musl-gcc -L $(MUSL_GCC_SYSROOT)/lib -L target/libunwind-aarch64' \
        cargo +nightly build --target aarch64-unknown-linux-musl
	/opt/homebrew/bin/patchelf --remove-needed libc.so target/aarch64-unknown-linux-musl/debug/libddappsec_helper_rust.so
.PHONY: bcross_linux_aarch64

