set(CMAKE_SYSTEM_NAME Linux)
execute_process(
    COMMAND arch
    OUTPUT_VARIABLE ARCHITECTURE
    OUTPUT_STRIP_TRAILING_WHITESPACE
)
if(ARCHITECTURE MATCHES "x86_64")
    set(ARCH x86_64)
else()
    set(ARCH aarch64)
endif()
set(CMAKE_AR /usr/bin/llvm-ar-16)
set(triple ${ARCH}-none-linux-musl)
set(CMAKE_ASM_COMPILER_TARGET ${triple})
set(CMAKE_C_COMPILER /usr/bin/clang-16)
set(CMAKE_C_COMPILER_TARGET ${triple})
set(c_cxx_flags "-Qunused-arguments -rtlib=compiler-rt -unwindlib=libunwind -static-libgcc -fno-omit-frame-pointer")
set(CMAKE_C_FLAGS_INIT ${c_cxx_flags})
set(CMAKE_CXX_COMPILER /usr/bin/clang++-16)
set(CMAKE_CXX_COMPILER_TARGET ${triple})
set(CMAKE_CXX_FLAGS_INIT "-stdlib=libc++ -isystem/usr/lib/clang/16.0.6/include/c++/v1 ${c_cxx_flags}")
set(CMAKE_EXE_LINKER_FLAGS_INIT "-v -fuse-ld=lld -static -nodefaultlibs -lc++ -lc++abi /usr/lib/clang/16.0.6/lib/linux/libclang_rt.builtins-${ARCH}.a -lunwind -lc /usr/lib/clang/16.0.6/lib/linux/libclang_rt.builtins-${ARCH}.a")
set(CMAKE_SHARED_LINKER_FLAGS_INIT "-v -fuse-ld=lld -nodefaultlibs -Wl,-Bstatic -lc++ -lc++abi /usr/lib/clang/16.0.6/lib/linux/libclang_rt.builtins-${ARCH}.a -lunwind -lglibc_compat -Wl,-Bdynamic /usr/lib/clang/16.0.6/lib/linux/libclang_rt.builtins-${ARCH}.a")
set(CMAKE_C_STANDARD_LIBRARIES "-Wl,-Bdynamic -lc")
set(CMAKE_CXX_STANDARD_LIBRARIES "-Wl,-Bdynamic -lc")

set(CMAKE_NM /usr/bin/llvm-nm-16)
set(CMAKE_RANLIB /usr/bin/llvm-ranlib-16)
set(CMAKE_STRIP /usr/bin/strip) # llvm-strip doesn't seem to work correctly
