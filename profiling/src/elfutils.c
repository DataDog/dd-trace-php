#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include <dlfcn.h>
#include <elf.h>
#include <fcntl.h>
#include <link.h>
#include <sys/mman.h>

#include "php.h"
#include "php_myextension.h"

void override_got_entry(void *base_address, const char *symbol_name, void *new_func,
                        void **orig_func) {
    // Locate the ELF headers and program headers
    Elf64_Ehdr *ehdr = (Elf64_Ehdr *)base_address;
    Elf64_Phdr *phdr = (Elf64_Phdr *)((char *)base_address + ehdr->e_phoff);

    Elf64_Dyn *dyn = NULL;
    for (int i = 0; i < ehdr->e_phnum; i++) {
        if (phdr[i].p_type == PT_DYNAMIC) {
            dyn = (Elf64_Dyn *)((char *)base_address + phdr[i].p_vaddr);
            break;
        }
    }
    if (!dyn) {
        fprintf(stderr, "Failed to locate dynamic section\n");
        return;
    }

    // Locate the relocation table (`DT_JMPREL`) and string table (`DT_STRTAB`)
    Elf64_Rela *rel_plt = NULL;
    size_t rel_plt_size = 0;
    Elf64_Sym *symtab = NULL;
    const char *strtab = NULL;

    for (Elf64_Dyn *dyn_iter = dyn; dyn_iter->d_tag != DT_NULL; dyn_iter++) {
        switch (dyn_iter->d_tag) {
            case DT_JMPREL:
                // rel_plt = (Elf64_Rela *)((char *)base_address + dyn_iter->d_un.d_ptr);
                rel_plt = (Elf64_Rela *)dyn_iter->d_un.d_ptr;
                break;
            case DT_PLTRELSZ:
                rel_plt_size = dyn_iter->d_un.d_val;
                break;
            case DT_SYMTAB:
                // symtab = (Elf64_Sym *)((char *)base_address + dyn_iter->d_un.d_ptr);
                symtab = (Elf64_Sym *)dyn_iter->d_un.d_ptr;
                break;
            case DT_STRTAB:
                // strtab = (const char *)((char *)base_address + dyn_iter->d_un.d_ptr);
                strtab = (const char *)dyn_iter->d_un.d_ptr;
                break;
            default:
                break;
        }
    }

    if (!rel_plt || !rel_plt_size || !symtab || !strtab) {
        fprintf(stderr, "Failed to locate required ELF sections\n");
        return;
    }

    // Iterate over the relocation table to find `write`
    for (size_t i = 0; i < rel_plt_size / sizeof(Elf64_Rela); i++) {
        Elf64_Rela *rel = &rel_plt[i];
        unsigned int r_type = ELF64_R_TYPE(rel->r_info);
        if (r_type != R_AARCH64_JUMP_SLOT) continue;
        size_t sym_index = ELF64_R_SYM(rel->r_info);
        Elf64_Sym *sym = &symtab[sym_index];

        const char *name = &strtab[sym->st_name];
        //	printf("Checking symbol: %s\n", name);
        if (strcmp(name, symbol_name) == 0) {
            // Calculate the GOT entry address
            void **got_entry = (void **)((char *)base_address + rel->r_offset);

            // Change memory permissions to allow writing to the GOT entry
            long page_size = sysconf(_SC_PAGESIZE);
            void *aligned_addr = (void *)((uintptr_t)got_entry & ~(page_size - 1));
            if (mprotect(aligned_addr, page_size, PROT_READ | PROT_WRITE) != 0) {
                perror("mprotect failed");
                return;
            }

            // Update the GOT entry
            printf("Overriding GOT entry for %s at %p\n", symbol_name, got_entry);
            *(void **)got_entry = new_func;
            *orig_func = dlsym(RTLD_NEXT, symbol_name);

            printf("Successfully overridden GOT entry for %s\n", symbol_name);
            return;
        }
    }

    fprintf(stderr, "Failed to find symbol in relocation entries: %s\n", symbol_name);
}

void phpfunc() {
    zend_execute_data *execute_data = EG(current_execute_data);
    if (execute_data != NULL && execute_data->func != NULL &&
        execute_data->func->common.function_name != NULL) {
        printf("Function: %s\n", execute_data->func->common.function_name->val);
    } else {
        printf("Function unknown\n");
    }
}

ssize_t (*orig_write)(int, const void *, size_t);
ssize_t my_write(int fd, const void *buf, size_t count) {
    printf("Intercepted write to fd %d: %zu bytes\n", fd, count);
    phpfunc();
    return orig_write(fd, buf, count);
}

ssize_t (*orig_send)(int socket, const void *buffer, size_t length, int flags);
ssize_t my_send(int socket, const void *buffer, size_t length, int flags) {
    printf("Intercepted send of %d bytes\n", length);
    phpfunc();
    return orig_send(socket, buffer, length, flags);
}

ssize_t (*orig_recv)(int socket, void *buffer, size_t length, int flags);
ssize_t my_recv(int socket, void *buffer, size_t length, int flags) {
    printf("Intercepted recv of %d bytes\n", length);
    phpfunc();
    return orig_recv(socket, buffer, length, flags);
}

int callback(struct dl_phdr_info *info, size_t size, void *data) {
    const char *name = info->dlpi_name[0] ? info->dlpi_name : "[Executable]";

    // Skip "linux-vdso" and "ld-linux"
    if (strstr(name, "linux-vdso") || strstr(name, "ld-linux") || strstr(name, "myextension")) {
        return 0;  // Continue iteration
    }

    printf("Base Address: 0x%lx, Library: %s\n", (unsigned long)info->dlpi_addr, name);

    override_got_entry((void *)info->dlpi_addr, "write", (void *)&my_write, (void *)&orig_write);
    override_got_entry((void *)info->dlpi_addr, "send", (void *)&my_send, (void *)&orig_send);
    override_got_entry((void *)info->dlpi_addr, "recv", (void *)&my_recv, (void *)&orig_recv);

    return 0;  // Continue iteration
}

// Modul-Startfunktion (MINIT)
PHP_MINIT_FUNCTION(myextension) {
    dl_iterate_phdr(callback, NULL);
    php_printf("myextension loaded!\n");
    write(STDOUT_FILENO, "HELLO\n", 6);
    return SUCCESS;
}

// Modul-Definition
zend_module_entry myextension_module_entry = {STANDARD_MODULE_HEADER,
                                              "myextension",            // Name der Erweiterung
                                              NULL,                     // Keine Funktionen
                                              PHP_MINIT(myextension),   // MINIT
                                              NULL,                     // MSHUTDOWN
                                              NULL,                     // RINIT
                                              NULL,                     // RSHUTDOWN
                                              NULL,                     // MINFO
                                              PHP_MYEXTENSION_VERSION,  // Versionsnummer
                                              STANDARD_MODULE_PROPERTIES};

#ifdef COMPILE_DL_MYEXTENSION
ZEND_GET_MODULE(myextension)
#endif
