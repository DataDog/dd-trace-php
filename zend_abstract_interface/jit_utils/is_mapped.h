#ifndef ZAI_IS_MAPPED_H
#define ZAI_IS_MAPPED_H

#if defined(__linux__) && (defined(__x86_64__) || defined(__aarch64__))
/* Validate the full range in batches of up to 64 pages, using a bounded stack vector. */
#define ZAI_MINCORE_BATCH_PAGES 64
static inline bool zai_is_mapped(const void *addr, size_t size) {
    if (size == 0) {
        return true;
    }
    uintptr_t page_size = sysconf(_SC_PAGESIZE);
    uintptr_t first_page = ((uintptr_t)addr & ~(page_size - 1));
    uintptr_t last_page = (((uintptr_t)addr + size - 1) & ~(page_size - 1));

    unsigned char vec[ZAI_MINCORE_BATCH_PAGES];
#ifdef __x86_64__
#define SYS_mincore 0x1B
#else // aarch64
#define SYS_mincore 0xE8
#endif

    for (uintptr_t page = first_page;; page += (uintptr_t)ZAI_MINCORE_BATCH_PAGES * page_size) {
        size_t pages = ((last_page - page) / page_size) + 1;
        if (pages > ZAI_MINCORE_BATCH_PAGES) {
            pages = ZAI_MINCORE_BATCH_PAGES;
        }

        int retries = 5;
        for (;;) {
            if (syscall(SYS_mincore, page, pages * page_size, &vec) == 0) {
                break;
            } else if (errno == EFAULT || errno == ENOMEM) {
                return false;
            } else if (errno == EAGAIN) {
                if (retries-- > 0) {
                    continue;
                }
                return true;
            } else if (errno == ENOSYS) {
                /* The syscall is unavailable; proceed without validation, as on unsupported platforms. */
                return true;
            } else {
#ifdef ZEND_DEBUG
                abort();
#else
                return true;
#endif
            }
        }

        if (page + (uintptr_t)pages * page_size > last_page) {
            return true;
        }
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
