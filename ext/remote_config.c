#include "remote_config.h"
#include "ddtrace.h"
#include "sidecar.h"
#include "hook/uhook.h"
#include "span.h"
#include <zai_string/string.h>
#include <components-rs/sidecar.h>
#include <components/log/log.h>
#include <hook/hook.h>

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

zend_always_inline zai_str dd_from_ddog_char_slice(ddog_CharSlice slice) {
	return (zai_str){ .ptr = slice.ptr, .len = slice.len };
}

typedef struct {
	ddtrace_span_data *span;
} dd_span_probe_dynamic;

typedef struct {
	zend_string *function;
	zend_string *scope;
	zend_string *file;
} dd_span_probe_def;

static bool dd_span_probe_begin(zend_ulong invocation, zend_execute_data *execute_data, void *auxiliary, void *dynamic) {
	dd_span_probe_def *def = auxiliary;
	dd_span_probe_dynamic *dyn = dynamic;

	if (def->file && (!execute_data->func->op_array.filename || !ddtrace_uhook_match_filepath(execute_data->func->op_array.filename, def->file))) {
		dyn->span = NULL;
		return true;
	}

	dyn->span = ddtrace_alloc_execute_data_span(invocation, execute_data);
	return true;
}

static void dd_span_probe_end(zend_ulong invocation, zend_execute_data *execute_data, zval *retval, void *auxiliary, void *dynamic) {
	dd_span_probe_dynamic *dyn = dynamic;

	UNUSED(execute_data, retval, auxiliary);

	if (dyn->span) {
		ddtrace_clear_execute_data_span(invocation, true);
	}
}

static void dd_span_probe_dtor(void *data) {
	dd_span_probe_def *def = data;
	if (def->file) {
		zend_string_release(def->file);
	}
	if (def->scope) {
		zend_string_release(def->scope);
	}
	if (def->function) {
		zend_string_release(def->function);
	}
	efree(def);
}

int64_t dd_set_span_probe(const struct ddog_ProbeTarget *target) {
	dd_span_probe_def *def = emalloc(sizeof(*def));
	def->file = NULL;
	def->function = NULL;
	def->scope = NULL;

	if (target->type_name.tag == DDOG_OPTION_CHAR_SLICE_SOME_CHAR_SLICE) {
		def->scope = dd_CharSlice_to_zend_string(target->type_name.some);
	}
	if (target->method_name.tag == DDOG_OPTION_CHAR_SLICE_SOME_CHAR_SLICE) {
		def->function = dd_CharSlice_to_zend_string(target->method_name.some);
	} else if (target->source_file.tag == DDOG_OPTION_CHAR_SLICE_SOME_CHAR_SLICE) {
		def->file = dd_CharSlice_to_zend_string(target->source_file.some);
	} else {
		dd_span_probe_dtor(def);
		return -1;
	}

	zend_long id = zai_hook_install(
			def->scope ? (zai_str)ZAI_STR_FROM_ZSTR(def->scope) : (zai_str)ZAI_STR_EMPTY,
			def->function ? (zai_str)ZAI_STR_FROM_ZSTR(def->function) : (zai_str)ZAI_STR_EMPTY,
			dd_span_probe_begin,
			dd_span_probe_end,
			ZAI_HOOK_AUX(def, dd_span_probe_dtor),
			sizeof(dd_span_probe_dynamic));

	if (id < 0) {
		dd_span_probe_dtor(def);
	}

	return id;
}

void dd_remove_span_probe(int64_t id) {
	dd_span_probe_def *def;
	if ((def = zend_hash_index_find_ptr(&DDTRACE_G(active_rc_hooks), (zend_ulong)id))) {
		zai_hook_remove(
				def->scope ? (zai_str)ZAI_STR_FROM_ZSTR(def->scope) : (zai_str)ZAI_STR_EMPTY,
				def->function ? (zai_str)ZAI_STR_FROM_ZSTR(def->function) : (zai_str)ZAI_STR_EMPTY,
				id);
	}
}

const ddog_Evaluator dd_evaluator = {

};

static struct ddog_Vec_CChar *dd_dynamic_instrumentation_update(ddog_CharSlice config, ddog_CharSlice value, bool return_old) {
	zend_string *name = dd_CharSlice_to_zend_string(config);
	zend_string *old;
	struct ddog_Vec_CChar *ret = NULL;
	if (return_old) {
		old = zend_string_copy(zend_ini_get_value(name));
	}
	if (zend_alter_ini_entry_chars(name, value.ptr, value.len, ZEND_INI_USER, ZEND_INI_STAGE_RUNTIME) == SUCCESS) {
		if (return_old) {
			ret = ddog_CharSlice_to_owned(dd_zend_string_to_CharSlice(old));
		}
	}
	if (return_old) {
		zend_string_release(old);
	}
	zend_string_release(name);
	return ret;
}

void ddtrace_minit_remote_config(void) {
	ddog_setup_dynamic_configuration(dd_dynamic_instrumentation_update, &(ddog_LiveDebuggerSetup) {
			.callbacks = (ddog_LiveDebuggerCallbacks) {
					.set_span_probe = dd_set_span_probe,
					.remove_span_probe = dd_remove_span_probe,
			},
			.evaluator = &dd_evaluator,
	});
}

void ddtrace_rinit_remote_config(void) {
	zend_hash_init(&DDTRACE_G(active_rc_hooks), 8, NULL, NULL, 0);
	ddog_rinit_remote_config(DDTRACE_G(remote_config_state));
}

void ddtrace_rshutdown_remote_config(void) {
	ddog_rshutdown_remote_config(DDTRACE_G(remote_config_state));
	zend_hash_destroy(&DDTRACE_G(active_rc_hooks));
}

