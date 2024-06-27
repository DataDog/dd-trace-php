MUSL_VERSION := 1.2.2
LLVM_VERSION := 11.1.0
LLVM_SUFFIX := 11
SHELL := /bin/bash
RELTYPE := RelWithDebInfo

# need to be in sync with Toolchain*.cmake files
MUSL_SYSROOT := $(CURDIR)/muslsysroot

TARGET_ARCH := $(shell arch)
TARGET := $(TARGET_ARCH)-none-linux-musl


install: .libcxx-installed .libcxxabi-installed
clean:
	rm -rf src/ build/ *.tar.xz *.tar.gz \
		.compiler-rt-installed .gcc-toolchain-installed .libcxxabi-installed \
		.libcxx-installed .libunwind-installed .musl-installed
.PHONY: install clean

CC_TOOLCHAIN := /usr
GCC_TOOL_PREFIX := /usr/bin/
GCC_TOOLCHAIN_SYSROOT := /

.gcc-toolchain-installed:
	cp -v /lib/$(TARGET_ARCH)-linux-gnu/libgcc_s.so.1 /usr/lib/gcc/$(TARGET_ARCH)-linux-gnu/10/libgcc_s.so.1
	touch $@

musl-$(MUSL_VERSION).tar.gz:
	curl -o $@ -Lf https://musl.libc.org/releases/musl-1.2.2.tar.gz
	grep -F $@ CHECKSUMS | sha512sum --check

libcxx-$(LLVM_VERSION).src.tar.xz \
	libcxxabi-$(LLVM_VERSION).src.tar.xz \
	libunwind-$(LLVM_VERSION).src.tar.xz \
	llvm-$(LLVM_VERSION).src.tar.xz \
	compiler-rt-$(LLVM_VERSION).src.tar.xz:
	curl -o $@ -Lf https://github.com/llvm/llvm-project/releases/download/llvmorg-$(LLVM_VERSION)/$@
	grep -F $@ CHECKSUMS | sha512sum --check


src/musl/.finger: musl-$(MUSL_VERSION).tar.gz
	mkdir -p src/musl && \
		tar -xzf musl-$(MUSL_VERSION).tar.gz --strip-components=1 -C src/musl && \
		touch src/musl/.finger

src/%/.finger: %-$(LLVM_VERSION).src.tar.xz
	mkdir -p $(subst /.finger,,$@) && \
		tar -xJf $< --strip-components=1 -C $(subst /.finger,,$@) && \
		touch $@

.musl-installed: src/musl/.finger .gcc-toolchain-installed
	mkdir -p build/musl && \
		pushd build/musl && \
		CC=$(GCC_TOOL_PREFIX)gcc \
		AR=$(GCC_TOOL_PREFIX)ar \
		RANLIB=$(GCC_TOOL_PREFIX)ranlib \
			../../src/musl/configure --prefix=$(MUSL_SYSROOT) && \
		$(MAKE) -j $(shell nproc) && \
		$(MAKE) install && \
		popd && \
		touch $@

VERBOSE := 1
export VERBOSE
COMMON_CXX_FLAGS := -DCMAKE_CXX_FLAGS="-resource-dir $(MUSL_SYSROOT)"
COMMON_CMAKE_OPTIONS := -DCMAKE_BUILD_TYPE=$(RELTYPE) \
	-DCMAKE_INSTALL_PREFIX=$(MUSL_SYSROOT) \
	-DCMAKE_SYSROOT=$(MUSL_SYSROOT) \
	-DCMAKE_AR=/usr/bin/llvm-ar-${LLVM_SUFFIX} \
	-DCMAKE_ASM_COMPILER_TARGET=$(TARGET) \
	-DCMAKE_C_COMPILER=/usr/bin/clang-${LLVM_SUFFIX} \
	-DCMAKE_C_COMPILER_TARGET=$(TARGET) \
	-DCMAKE_C_COMPILER_EXTERNAL_TOOLCHAIN=$(GCC_TOOLCHAIN) \
	-DCMAKE_C_FLAGS="-resource-dir $(MUSL_SYSROOT)" \
	-DCMAKE_CXX_COMPILER=/usr/bin/clang++-${LLVM_SUFFIX} \
	-DCMAKE_CXX_COMPILER_TARGET=$(TARGET) \
	-DCMAKE_CXX_COMPILER_EXTERNAL_TOOLCHAIN=$(GCC_TOOLCHAIN) \
	-DCMAKE_EXE_LINKER_FLAGS="-fuse-ld=lld" \
	-DCMAKE_SHARED_LINKER_FLAGS="-v -fuse-ld=lld" \
	-DCMAKE_NM=/usr/bin/llvm-nm-${LLVM_SUFFIX} \
	-DCMAKE_RANLIB=/usr/bin/llvm-ranlib-${LLVM_SUFFIX}

.compiler-rt-installed: src/compiler-rt/.finger .musl-installed
	mkdir -p build/compiler-rt && \
		pushd build/compiler-rt && \
		cmake $(COMMON_CMAKE_OPTIONS) $(COMMON_CXX_FLAGS) \
			-DCOMPILER_RT_BUILD_BUILTINS=ON \
			-DCOMPILER_RT_BUILD_LIBFUZZER=OFF \
			-DCOMPILER_RT_BUILD_MEMPROF=OFF \
			-DCOMPILER_RT_BUILD_PROFILE=OFF \
			-DCOMPILER_RT_BUILD_SANITIZERS=OFF \
			-DCOMPILER_RT_BUILD_XRAY=OFF \
			-DCOMPILER_RT_DEFAULT_TARGET_ONLY=ON \
			-DCOMPILER_RT_BUILD_CRT=ON \
			../../src/compiler-rt && \
		$(MAKE) -j $(shell nproc) && $(MAKE) install && \
		popd && \
		touch $@

.libunwind-installed: src/libunwind/.finger src/libcxx/.finger .compiler-rt-installed
	mkdir -p build/libunwind && \
		pushd build/libunwind && \
		cmake $(COMMON_CMAKE_OPTIONS) \
			-DCMAKE_CXX_FLAGS="-resource-dir $(MUSL_SYSROOT) -nostdinc++ -isystem $(realpath .)/src/libcxx/include/" \
			-DLLVM_PATH=../../src/llvm \
			-DLIBUNWIND_USE_COMPILER_RT=ON \
			../../src/libunwind && \
		$(MAKE) -j $(shell nproc) && $(MAKE) install && \
		popd && \
		touch $@

.libcxxabi-installed: src/libcxxabi/.finger src/libcxx/.finger src/llvm/.finger src/libunwind/.finger .compiler-rt-installed .libunwind-installed
	mkdir -p build/libcxxabi && \
		pushd build/libcxxabi && \
		cmake $(COMMON_CMAKE_OPTIONS) $(COMMON_CXX_FLAGS) \
			-DLIBCXXABI_USE_LLVM_UNWINDER=ON \
			-DLIBCXXABI_INCLUDE_TESTS=OFF \
			-DLIBCXXABI_USE_COMPILER_RT=ON \
			-DLLVM_PATH=../../src/llvm \
			-DLIBCXXABI_LIBUNWIND_PATH=../../src/libunwind \
			-DLIBCXXABI_LIBCXX_INCLUDES=../../src/libcxx/include \
			../../src/libcxxabi && \
		$(MAKE) -j $(shell nproc) && $(MAKE) install && \
		popd && \
		touch $@

.orig-sysroot-copied: .musl-installed
	for dir in $(GCC_TOOLCHAIN_SYSROOT)usr/include/{linux,asm,asm-generic,$$($(GCC_TOOL_PREFIX)gcc -print-multiarch)/asm}; do \
		test ! -d "$$dir" || cp -av "$$dir" $(MUSL_SYSROOT)/include/; done && \
		cp $$(dirname $$($(GCC_TOOL_PREFIX)gcc -print-libgcc-file-name))/crtbegin{,S,T}.o $(MUSL_SYSROOT)/lib && \
		cp $$(dirname $$($(GCC_TOOL_PREFIX)gcc -print-libgcc-file-name))/crtend{,S}.o $(MUSL_SYSROOT)/lib && \
		cp $(GCC_TOOLCHAIN_SYSROOT)usr/include/$$($(GCC_TOOL_PREFIX)gcc -print-multiarch)/fpu_control.h $(MUSL_SYSROOT)/include/ && \
	touch $@


.libcxx-installed: src/libcxx/.finger src/llvm/.finger src/libunwind/.finger .orig-sysroot-copied .compiler-rt-installed .libunwind-installed .libcxxabi-installed
	mkdir -p build/libcxx && \
		pushd build/libcxx && \
		cmake $(COMMON_CMAKE_OPTIONS) $(COMMON_CXX_FLAGS) \
			-DLIBCXX_HAS_MUSL_LIBC=ON \
			-DLIBCXX_CXX_ABI=libcxxabi \
			-DLIBCXX_CXX_ABI_INCLUDE_PATHS=../../src/libcxxabi/include \
			-DLIBCXX_USE_COMPILER_RT=ON \
			-DLIBCXX_ENABLE_FILESYSTEM=ON \
			-DLIBCXX_ENABLE_EXPERIMENTAL_LIBRARY=0 \
			../../src/libcxx && \
		$(MAKE) -j $(shell nproc) && $(MAKE) install && \
		popd && \
		touch $@

libddwaf:
	mkdir -p build/libddwaf && \
		pushd build/libddwaf && \
		cmake -DCMAKE_BUILD_TYPE=$(RELTYPE) -DCMAKE_TOOLCHAIN_FILE="$(realpath .)/Toolchain.cmake" \
			-DCMAKE_GTEST_DISCOVER_TESTS_DISCOVERY_MODE=PRE_TEST \
			-DLIBDDWAF_TEST_COVERAGE=OFF \
			../../src/libddwaf && \
		make -j && make -j testPowerWAF && patchelf --remove-needed libc.so libddwaf.so && make package

.PHONY: libddwaf
