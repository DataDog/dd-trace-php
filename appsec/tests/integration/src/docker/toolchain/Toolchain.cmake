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
set(CMAKE_SYSROOT /build/muslsysroot)
set(CMAKE_AR /usr/bin/llvm-ar-11)
set(triple ${ARCH}-none-linux-musl)
set(CMAKE_ASM_COMPILER_TARGET ${triple})
set(CMAKE_C_COMPILER /usr/bin/clang-11)
set(CMAKE_C_COMPILER_TARGET ${triple})
set(c_cxx_flags "-nostdinc -isystem${CMAKE_SYSROOT}/include -isystem/usr/lib/llvm-11/lib/clang/11.0.1/include -resource-dir ${CMAKE_SYSROOT} -Qunused-arguments -rtlib=compiler-rt -unwindlib=libunwind -static-libgcc")
set(CMAKE_C_FLAGS ${c_cxx_flags})
set(CMAKE_CXX_COMPILER /usr/bin/clang++-11)
set(CMAKE_CXX_COMPILER_TARGET ${triple})
set(CMAKE_CXX_FLAGS "-stdlib=libc++ -isystem${CMAKE_SYSROOT}/include/c++/v1 ${c_cxx_flags}")
set(CMAKE_EXE_LINKER_FLAGS_INIT "-v -fuse-ld=lld -static -nodefaultlibs -lc++ -lc++abi ${CMAKE_SYSROOT}/lib/linux/libclang_rt.builtins-${ARCH}.a -lunwind -lc ${CMAKE_SYSROOT}/lib/linux/libclang_rt.builtins-${ARCH}.a")
set(CMAKE_SHARED_LINKER_FLAGS_INIT "-v -fuse-ld=lld -nodefaultlibs -Wl,-Bstatic -lc++ -lc++abi ${CMAKE_SYSROOT}/lib/linux/libclang_rt.builtins-${ARCH}.a -lunwind -Wl,-Bdynamic -lc ${CMAKE_SYSROOT}/lib/linux/libclang_rt.builtins-${ARCH}.a")

set(CMAKE_NM /usr/bin/llvm-nm-11)
set(CMAKE_RANLIB /usr/bin/llvm-ranlib-11)
set(CMAKE_STRIP /usr/bin/strip) # llvm-strip doesn't seem to work correctly
