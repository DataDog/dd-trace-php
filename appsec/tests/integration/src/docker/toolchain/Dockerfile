FROM debian:latest AS toolchain

ARG LLVM_VERSION=16.0.6
ARG ARCH

COPY Toolchain.cmake /build/Toolchain.cmake
COPY CHECKSUMS /CHECKSUMS

RUN echo "Building LLVM ${LLVM_VERSION} on ${ARCH}"

RUN apt-get update && apt-get install -y \
  wget cmake binutils lld libncurses5-dev git patchelf xz-utils curl lsb-release wget software-properties-common gnupg

RUN wget https://apt.llvm.org/llvm.sh && \
  chmod +x llvm.sh && \
  ./llvm.sh 16 all

RUN wget https://github.com/llvm/llvm-project/releases/download/llvmorg-${LLVM_VERSION}/llvm-project-${LLVM_VERSION}.src.tar.xz && \
  grep -F llvm-project-${LLVM_VERSION}.src.tar.xz /CHECKSUMS | sha512sum --check && \
  tar -xvf llvm-project-${LLVM_VERSION}.src.tar.xz

COPY wchar.h.diff /wchar.h.diff
RUN patch /usr/include/wchar.h < /wchar.h.diff

RUN cd llvm-project-${LLVM_VERSION}.src && mkdir -p build && cd build && \
  cmake \
  -DCMAKE_BUILD_TYPE=RelWithDebInfo \
  -DCMAKE_INSTALL_PREFIX=/usr \
  -DCMAKE_C_COMPILER=clang-16 \
  -DCMAKE_C_FLAGS="-fno-omit-frame-pointer -D_LIBCPP_HAS_NO_C11_ALIGNED_ALLOC=1" \
  -DCMAKE_CXX_COMPILER=clang++-16 \
  -DCMAKE_CXX_FLAGS="-fno-omit-frame-pointer -D_LIBCPP_HAS_NO_C11_ALIGNED_ALLOC=1" \
  -DLIBUNWIND_ENABLE_SHARED=OFF \
  -DLIBUNWIND_ENABLE_STATIC=ON \
  -DLIBUNWIND_USE_COMPILER_RT=ON \
  -DLIBCXXABI_ENABLE_SHARED=ON \
  -DLIBCXXABI_USE_LLVM_UNWINDER=ON \
  -DLIBCXXABI_ENABLE_STATIC_UNWINDER=ON \
  -DLIBCXXABI_USE_COMPILER_RT=ON \
  -DLIBCXX_ENABLE_SHARED=OFF \
  -DLIBCXX_HAS_MUSL_LIBC=ON \
  -DLIBCXX_USE_COMPILER_RT=ON \
  -DLIBCXX_ENABLE_STATIC_ABI_LIBRARY=ON \
  -DLLVM_ENABLE_RUNTIMES="libcxx;libcxxabi;libunwind" \
  -DLLVM_EXTERNAL_LIT=/usr/bin/lit ../runtimes && \
  make -j$(nproc) install-unwind install

RUN cd /usr/lib && ln -s gcc/*/*/ resource_dir
RUN cd /usr/lib && ln -s clang/${LLVM_VERSION%%.*}/lib/linux/libclang_rt.builtins-*.a libclang_rt.builtins.a

RUN rm -rf /llvm-project-${LLVM_VERSION}.src
RUN rm -f llvm-project-${LLVM_VERSION}.src.tar.xz
