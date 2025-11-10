// Note: Not included on Windows
#include "crashtracking_frames.h"

#include "compatibility.h"
#include <php.h>
#include <components-rs/crashtracker.h>
#include <jit_utils/is_mapped.h>

#include "sidecar.h"

static ddog_CharSlice dd_validate_zstr(zend_string *str) {
    if (zai_is_mapped(str, XtOffsetOf(zend_string, val))) {
        if (!ZSTR_LEN(str) || zai_is_mapped(ZSTR_VAL(str), ZSTR_LEN(str))) {
            ddog_CharSlice slice = dd_zend_string_to_CharSlice(str);
            if (slice.len > 512) {
                slice.len = 512; // truncate it
            }
            return slice;
        }
        return DDOG_CHARSLICE_C("<corrupted string length / out of bounds>");
    }
    return DDOG_CHARSLICE_C("<corrupted string pointer>");
}

static void dd_frames_callback(void (*emit_frame)(const ddog_crasht_RuntimeStackFrame *)) {
    zend_execute_data *call;
#if PHP_VERSION_ID >= 80400
    zend_execute_data *last_call = NULL;
#endif
    int frameno = 0;

    ddog_crasht_RuntimeStackFrame frame = {0};
    ddog_crasht_RuntimeStackFrame override_frame = {0};

    call = EG(current_execute_data);
    if (!call) {
        return;
    }

#if PHP_VERSION_ID >= 80300
    // Apply the lineno override if we are not at that very frame anyway
    if (EG(filename_override)) {
        override_frame.file = dd_validate_zstr(EG(filename_override));
        override_frame.line = EG(lineno_override);
        override_frame.function = dd_zend_string_to_CharSlice(ZSTR_KNOWN(ZEND_STR_CONST_EXPR_PLACEHOLDER));
    }
#endif

#define EMIT(frame) do { if (override_frame.file.len && (override_frame.line != (frame)->line || override_frame.function.len != (frame)->function.len || !memcmp(override_frame.function.ptr, (frame)->function.ptr, override_frame.function.len))) { emit_frame(&override_frame); override_frame.file.len = 0; /* reset */ } emit_frame(frame); } while(0)

    while (++frameno < 100 /* limit it */) {

        if (!call) {
            break;
        }

        if (!zai_is_mapped(call, sizeof(zend_execute_data))) {
            frame.file = DDOG_CHARSLICE_C("Unknown");
            frame.function = DDOG_CHARSLICE_C("<corrupted frame>");
            EMIT(&frame);
            break;
        }

        zend_function *func = call->func;
        if (!call->func) {
            /* This is a generator placeholder frame. We are not interested in this here and just skip it. */
            call = call->prev_execute_data;
            continue;
        }

        if (!zai_is_mapped(func, sizeof(zend_internal_function))) {
            call = call->prev_execute_data;
            frame.file = DDOG_CHARSLICE_C("Unknown");
            frame.function = DDOG_CHARSLICE_C("<corrupted function>");
            EMIT(&frame);
            continue;
        }

        frame.function = func->common.function_name ? dd_validate_zstr(func->common.function_name) : DDOG_CHARSLICE_C("[top-level code]");
        frame.type_name = func->common.scope ? zai_is_mapped(&func->common.scope->name, sizeof(zend_string *)) ? dd_validate_zstr(func->common.scope->name) : DDOG_CHARSLICE_C("<corrupted scope>") : DDOG_CHARSLICE_C("");


        if (ZEND_USER_CODE(func->type)) {
            if (!zai_is_mapped(func, sizeof(zend_op_array))) {
                call = call->prev_execute_data;
                frame.file = DDOG_CHARSLICE_C("<corrupted user function>");
                EMIT(&frame);
                continue;
            }

            frame.file = dd_validate_zstr(func->op_array.filename);
            frame.line = func->op_array.line_start;

            const zend_op *opline = call->opline;
            if (!zai_is_mapped(opline, sizeof(zend_op))) {
                frame.column = -1;
                EMIT(&frame);
                continue;
            }

            if (opline->opcode == ZEND_HANDLE_EXCEPTION) {
                const zend_op *op = EG(opline_before_exception);
                if (op) {
                    if (zai_is_mapped(op, sizeof(zend_op))) {
                        frame.line = op->lineno;
                    } else {
                        frame.column = -2;
                    }
                } else {
                    frame.line = func->op_array.line_end;
                }
            } else {
                frame.line = call->opline->lineno;
            }

            if (opline->opcode == ZEND_INCLUDE_OR_EVAL) {
                ddog_crasht_RuntimeStackFrame inc_frame = (ddog_crasht_RuntimeStackFrame){0};
                uint32_t include_kind = opline->extended_value;

                switch (include_kind) {
                    case ZEND_EVAL:
                        inc_frame.function = dd_zend_string_to_CharSlice(ZSTR_KNOWN(ZEND_STR_EVAL));
                        break;
                    case ZEND_INCLUDE:
                        inc_frame.function = dd_zend_string_to_CharSlice(ZSTR_KNOWN(ZEND_STR_INCLUDE));
                        break;
                    case ZEND_REQUIRE:
                        inc_frame.function = dd_zend_string_to_CharSlice(ZSTR_KNOWN(ZEND_STR_REQUIRE));
                        break;
                    case ZEND_INCLUDE_ONCE:
                        inc_frame.function = dd_zend_string_to_CharSlice(ZSTR_KNOWN(ZEND_STR_INCLUDE_ONCE));
                        break;
                    case ZEND_REQUIRE_ONCE:
                        inc_frame.function = dd_zend_string_to_CharSlice(ZSTR_KNOWN(ZEND_STR_REQUIRE_ONCE));
                        break;
                    default:
                        inc_frame.function = dd_zend_string_to_CharSlice(ZSTR_KNOWN(ZEND_STR_UNKNOWN));
                        break;
                }

                EMIT(&inc_frame);
            }


#if PHP_VERSION_ID >= 80400
            /* For frameless calls we add an additional frame for the call itself. */
            if (!ZEND_OP_IS_FRAMELESS_ICALL(opline->opcode)) {
                goto not_frameless_call;
            }
            if (!zai_is_mapped(&ZEND_FLF_FUNC(opline), sizeof(zend_function *))) {
                goto not_frameless_call;
            }
            zend_internal_function *flf_func = &ZEND_FLF_FUNC(opline)->internal_function;
            if (!zai_is_mapped(flf_func, sizeof(zend_internal_function))) {
                goto not_frameless_call;
            }
            if (last_call && &last_call->func->internal_function == flf_func) {
                goto not_frameless_call;
            }
            ddog_crasht_RuntimeStackFrame inner_frame = (ddog_crasht_RuntimeStackFrame){0};
            inner_frame.function = dd_validate_zstr(flf_func->function_name);
            inner_frame.type_name = flf_func->scope ? zai_is_mapped(&flf_func->scope->name, sizeof(zend_string *)) ? dd_validate_zstr(flf_func->scope->name) : DDOG_CHARSLICE_C("<corrupted scope>") : DDOG_CHARSLICE_C("");
            inner_frame.file = DDOG_CHARSLICE_C("[internal function]");
            EMIT(&inner_frame);
            not_frameless_call: ;
#else
            UNUSED(last_call);
#endif
        } else {
            frame.file = DDOG_CHARSLICE_C("[internal function]");
        }

        EMIT(&frame);
#if PHP_VERSION_ID >= 80400
        last_call = call;
#endif
        call = call->prev_execute_data;
    }
}

void ddtrace_register_crashtracking_frames_collection() {
    ddog_crasht_register_runtime_frame_callback(dd_frames_callback);
}

