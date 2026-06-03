#include <php.h>
#include <zend_abstract_interface/tsrmls_cache.h>
#ifndef _WIN32
#include <sys/mman.h>
#endif
#include <components/log/log.h>

// On PHP 8.0.0-8.0.16 and 8.1.0-8.1.2 call_attribute_constructor would stack allocate a dummy frame, which could have become inaccessible upon access.
// Thus, we implement the fix which was applied to PHP itself as well: we move the stack allocated data to the VM stack.
// See also https://github.com/php/php-src/commit/f7c3f6e7e25471da9cfb2ba082a77cc3c85bc6ed
static void dd_patched_zend_call_known_function(
    zend_function *fn, zend_object *object, zend_class_entry *called_scope, zval *retval_ptr,
    uint32_t param_count, zval *params, HashTable *named_params)
{
    zval retval;
    zend_fcall_info fci;
    zend_fcall_info_cache fcic;

    // If current_execute_data is on the stack, move it to the VM stack
    zend_execute_data *execute_data = EG(current_execute_data);
    if (execute_data) {
        bool is_stack_ex = (uintptr_t)&retval + 0xfffff > (uintptr_t)execute_data && (uintptr_t)&retval - 0xfffff < (uintptr_t)execute_data;
        bool is_stack_func = (uintptr_t)&retval + 0xfffff > (uintptr_t)EX(func) && (uintptr_t)&retval - 0xfffff < (uintptr_t)EX(func);
        if (is_stack_ex || is_stack_func) {
            zend_execute_data *call = zend_vm_stack_push_call_frame_ex(
                    ZEND_MM_ALIGNED_SIZE_EX(sizeof(zend_execute_data), sizeof(zval)) +
                    (is_stack_func ? ZEND_MM_ALIGNED_SIZE_EX(sizeof(zend_op), sizeof(zval)) + ZEND_MM_ALIGNED_SIZE_EX(sizeof(zend_function), sizeof(zval)) : 0),
                    0, EX(func), 0, NULL);

            memcpy(call, execute_data, sizeof(zend_execute_data));
            if (is_stack_func) {
                zend_op *opline = (zend_op *)(call + 1);
                memcpy(opline, EX(opline), sizeof(zend_op));
                zend_function *func = (zend_function *)(opline + 1);
                memcpy(func, EX(func), sizeof(zend_function));
                func->common.fn_flags |= ZEND_ACC_CALL_VIA_TRAMPOLINE; // See https://github.com/php/php-src/commit/2f6a06ccb0ef78e6122bb9e67f9b8b1ad07776e1

                call->opline = opline;
                call->func = func;
            } else {
                call->opline = EX(opline);
            }

            EG(current_execute_data) = call;
        }
    }

    // here follows the original implementation of zend_call_known_function

    fci.size = sizeof(fci);
    fci.object = object;
    fci.retval = retval_ptr ? retval_ptr : &retval;
    fci.param_count = param_count;
    fci.params = params;
    fci.named_params = named_params;
    ZVAL_UNDEF(&fci.function_name); /* Unused */

    fcic.function_handler = fn;
    fcic.object = object;
    fcic.called_scope = called_scope;

    zend_result result = zend_call_function(&fci, &fcic);
    if (UNEXPECTED(result == FAILURE)) {
        if (!EG(exception)) {
            zend_error_noreturn(E_CORE_ERROR, "Couldn't execute method %s%s%s",
                fn->common.scope ? ZSTR_VAL(fn->common.scope->name) : "",
                fn->common.scope ? "::" : "", ZSTR_VAL(fn->common.function_name));
        }
    }

    if (!retval_ptr) {
        zval_ptr_dtor(&retval);
    }
}

// We need to hijack zend_call_known_function as that's what's being called by call_attribute_constructor, and call_attribute_constructor itself is not exported.
void datadog_patch_zend_call_known_function(void) {
#ifdef _WIN32
    SYSTEM_INFO si;
    GetSystemInfo(&si);
    size_t page_size = (size_t)si.dwPageSize;
#else
    size_t page_size = sysconf(_SC_PAGESIZE);
#endif
    void *page = (void *)(~(page_size - 1) & (uintptr_t)zend_call_known_function);
    // 20 is the largest size of a trampoline we have to inject
    if ((((uintptr_t)zend_call_known_function + 20) & page_size) < 20) {
        page_size <<= 1; // if overlapping pages, use two
    }

#ifdef _WIN32
    DWORD old_protection;
    if (!VirtualProtect(page, page_size, PAGE_READWRITE, &old_protection))
#else
    if (mprotect(page, page_size, PROT_READ | PROT_WRITE) != 0)
#endif
    { // Some architectures enforce W^X (either write _or_ execute, but not both).
        LOG(ERROR, "Could not alter the memory protection for zend_call_known_function. Tracer execution continues, but may crash when encountering attributes.");
        return; // Make absolutely sure we can write
    }

#ifdef __aarch64__
    // x13 is a scratch register
    uint32_t absolute_jump_instrs[] = {
        0x1000006D, // adr x13, 12 (load address from memory after this)
        0xF94001AD, // ldr x13, [x13]
        0xD61F01A0, // br x13
    };
    // The magical 12 is sizeof(absolute_jump_instrs) and hardcoded in the assembly above.
    memcpy(zend_call_known_function, absolute_jump_instrs, 12);
    *(void **)(12 + (uintptr_t)zend_call_known_function) = dd_patched_zend_call_known_function;
#else
    // $r10 doesn't really have special meaning
    uint8_t absolute_jump_instrs[] = {
        0x49, 0xBA, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, // mov $r10, imm_addr
        0x41, 0xFF, 0xE2 // jmp $r10
    };
    *(void **)&absolute_jump_instrs[2] = dd_patched_zend_call_known_function;
    memcpy(zend_call_known_function, absolute_jump_instrs, sizeof(absolute_jump_instrs));
#endif

#ifdef _WIN32
    VirtualProtect(page, page_size, old_protection, &old_protection /* dummy, but must be valid */);
#else
    mprotect(page, page_size, PROT_READ | PROT_EXEC);
#endif
}
