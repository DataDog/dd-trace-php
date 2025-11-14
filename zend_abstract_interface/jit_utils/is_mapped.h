#ifndef ZAI_IS_MAPPED_H
#define ZAI_IS_MAPPED_H

#if defined(__linux__) && (defined(__x86_64__) || defined(__aarch64__))
static inline bool zai_is_mapped(const void *addr, size_t size) {
    uintptr_t page_size = sysconf(_SC_PAGESIZE);
    assert(size <= page_size);
    uintptr_t page_addr = ((uintptr_t)addr & ~(page_size - 1));
    uintptr_t last_page_addr = ((uintptr_t)(addr + size - 1) & ~(page_size - 1));

    unsigned char vec[2];
#ifdef __x86_64__
#define SYS_mincore 0x1B
#else // aarch64
#define SYS_mincore 0xE8
#endif

    int retries = 5;
    again:
        if (syscall(SYS_mincore, page_addr, (1 + (page_addr != last_page_addr)) * page_size, &vec) == 0) {
            return true;
        } else if (errno == EFAULT || errno == ENOMEM) {
            return false;
        } else if (errno == EAGAIN) {
            if (retries-- > 0) {
                goto again;
            }
            return true;
        } else {
            // we don't know... assume true
#ifdef ZEND_DEBUG
            abort();
#else
            return true;
#endif
        }
}
#elif defined(__APPLE__)
#include <mach/mach.h>
static inline bool zai_is_mapped(const void *addr, size_t size) {
    mach_port_t task = mach_task_self();
    vm_address_t address = (vm_address_t)addr;

    while (address < (vm_address_t)addr + size) {
        __auto_type a = address;
        vm_size_t region_size;
        vm_region_basic_info_data_64_t info;
        kern_return_t kr = vm_region_64(task, &address, &region_size, VM_REGION_BASIC_INFO, (vm_region_info_t)&info,
                                        &(mach_msg_type_number_t){VM_REGION_BASIC_INFO_COUNT_64}, &(memory_object_name_t){0});

        if (kr != KERN_SUCCESS || !(info.protection & VM_PROT_READ)) {
            return false;
        }

        address += region_size;
    }

    return true;
}
#else
static inline bool zai_is_mapped(const void *addr, size_t size) {
    (void)addr;
    (void)size;
    return true;
}
#endif

#endif // ZAI_IS_MAPPED_H
