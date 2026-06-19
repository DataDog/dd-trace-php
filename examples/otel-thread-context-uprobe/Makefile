CLANG ?= clang
CXX ?= c++
BPFTOOL ?= bpftool
PKG_CONFIG ?= pkg-config

UNAME_M := $(shell uname -m)
ifeq ($(UNAME_M),x86_64)
BPF_ARCH := x86
else ifeq ($(UNAME_M),aarch64)
BPF_ARCH := arm64
else ifeq ($(UNAME_M),arm64)
BPF_ARCH := arm64
else
$(error unsupported architecture $(UNAME_M); expected x86_64 or aarch64)
endif

LIBBPF_CFLAGS := $(shell $(PKG_CONFIG) --cflags libbpf 2>/dev/null)
LIBBPF_LIBS := $(shell $(PKG_CONFIG) --libs libbpf 2>/dev/null || echo -lbpf)

BPF_CFLAGS ?= -g -O2 -Wall -Werror -Wno-missing-declarations -target bpf -D__TARGET_ARCH_$(BPF_ARCH)
CXXFLAGS ?= -g -O2 -std=c++17 -Wall -Wextra
LDLIBS ?= $(LIBBPF_LIBS) -lelf -lz

.PHONY: all clean

all: otel_span_uprobe

vmlinux.h:
	$(BPFTOOL) btf dump file /sys/kernel/btf/vmlinux format c > $@

otel_span_uprobe.bpf.o: otel_span_uprobe.bpf.c otel_span_uprobe.h vmlinux.h
	$(CLANG) $(BPF_CFLAGS) $(LIBBPF_CFLAGS) -I. -c $< -o $@

otel_span_uprobe.skel.h: otel_span_uprobe.bpf.o
	$(BPFTOOL) gen skeleton $< > $@

otel_span_uprobe: otel_span_uprobe.cpp otel_span_uprobe.h otel_span_uprobe.skel.h
	$(CXX) $(CXXFLAGS) $(LIBBPF_CFLAGS) -I. $< -o $@ $(LDLIBS)

clean:
	rm -f otel_span_uprobe otel_span_uprobe.bpf.o otel_span_uprobe.skel.h vmlinux.h
