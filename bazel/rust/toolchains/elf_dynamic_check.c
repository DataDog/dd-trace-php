#include <elf.h>
#include <errno.h>
#include <fcntl.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>
#include <unistd.h>

static int range_ok(size_t size, uint64_t offset, uint64_t length) {
    return offset <= size && length <= size - offset;
}

static void fail(const char *message) {
    fprintf(stderr, "ELF dynamic check: %s\n", message);
    exit(1);
}

int main(int argc, char **argv) {
    if (argc != 4) fail("expected INPUT OUTPUT INTERPRETER");
    int input_fd = open(argv[1], O_RDONLY | O_CLOEXEC);
    if (input_fd < 0) fail(strerror(errno));
    struct stat status;
    if (fstat(input_fd, &status) != 0 || status.st_size < 0) fail("cannot stat input");
    size_t size = (size_t)status.st_size;
    unsigned char *data = malloc(size ? size : 1);
    if (!data) fail("out of memory");
    size_t used = 0;
    while (used < size) {
        ssize_t count = read(input_fd, data + used, size - used);
        if (count > 0) used += (size_t)count;
        else if (count < 0 && errno == EINTR) continue;
        else fail("short read");
    }
    close(input_fd);

    if (size < sizeof(Elf64_Ehdr)) fail("truncated ELF header");
    Elf64_Ehdr header;
    memcpy(&header, data, sizeof(header));
    if (memcmp(header.e_ident, ELFMAG, SELFMAG) ||
        header.e_ident[EI_CLASS] != ELFCLASS64 ||
        header.e_ident[EI_DATA] != ELFDATA2LSB) {
        fail("expected a little-endian ELF64 file");
    }
    if (header.e_type != ET_DYN) fail("target executable is not ET_DYN PIE");
    if (header.e_phentsize != sizeof(Elf64_Phdr) ||
        !range_ok(size, header.e_phoff, (uint64_t)header.e_phnum * header.e_phentsize)) {
        fail("invalid program-header table");
    }

    int interpreter = 0;
    int dynamic = 0;
    int needed = 0;
    for (uint16_t index = 0; index < header.e_phnum; ++index) {
        Elf64_Phdr program;
        memcpy(&program, data + header.e_phoff + (uint64_t)index * header.e_phentsize,
               sizeof(program));
        if (!range_ok(size, program.p_offset, program.p_filesz)) {
            fail("invalid program-header file range");
        }
        if (program.p_type == PT_INTERP) {
            size_t expected = strlen(argv[3]) + 1;
            if (program.p_filesz != expected ||
                memcmp(data + program.p_offset, argv[3], expected)) {
                fail("unexpected PT_INTERP value");
            }
            interpreter = 1;
        } else if (program.p_type == PT_DYNAMIC) {
            dynamic = 1;
            if (program.p_filesz % sizeof(Elf64_Dyn)) fail("invalid PT_DYNAMIC size");
            size_t count = (size_t)(program.p_filesz / sizeof(Elf64_Dyn));
            for (size_t entry = 0; entry < count; ++entry) {
                Elf64_Dyn item;
                memcpy(&item, data + program.p_offset + entry * sizeof(item), sizeof(item));
                if (item.d_tag == DT_NEEDED) ++needed;
                if (item.d_tag == DT_NULL) break;
            }
        }
    }
    if (!interpreter) fail("PT_INTERP is missing");
    if (!dynamic || !needed) fail("PT_DYNAMIC has no DT_NEEDED entries");

    int output_fd = open(argv[2], O_WRONLY | O_CREAT | O_TRUNC | O_CLOEXEC, 0644);
    if (output_fd < 0) fail(strerror(errno));
    static const char marker[] = "dynamic-target-with-proc-macro\n";
    if (write(output_fd, marker, sizeof(marker) - 1) != (ssize_t)(sizeof(marker) - 1) ||
        close(output_fd) != 0) {
        fail("cannot write marker");
    }
    free(data);
    return 0;
}
