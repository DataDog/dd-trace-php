#include "meat.h"

int find_function(HashTable *table, zend_string *name, zend_function **function) {
	zend_string *key = zend_string_tolower(name);
	zend_function *ptr = zend_hash_find_ptr(table, key);

	zend_string_release(key);

	if (!ptr) {
		return FAILURE;
	}

	if (function) {
		*function = ptr;
	}

	return SUCCESS;
}

int find_method(zend_class_entry *ce, zend_string *name, zend_function **function) { 
	return find_function(&ce->function_table, name, function);
}

#if PHP_VERSION_ID >= 70100
#	define RETURN_VALUE_USED(opline) ((opline)->result_type != IS_UNUSED)
#else
#	define RETURN_VALUE_USED(opline) (!((opline)->result_type & EXT_TYPE_UNUSED))
#endif

void dispatch_free(zval *zv) {
	ddtrace_dispatch_t *ureturn = Z_PTR_P(zv);
	
	zend_string_release(ureturn->function);
	efree(ureturn);
} 


void execute_fcall(ddtrace_dispatch_t *ureturn, zend_execute_data *execute_data, zval *return_value) { 
	zend_fcall_info fci;
	zend_fcall_info_cache fcc;
	char *error = NULL;
	zval closure, rv;
    zval *result = return_value ? return_value : &rv;

	ZVAL_UNDEF(&rv); 

	zend_create_closure(&closure, (zend_function*) zend_get_closure_method_def(&ureturn->callable), 
		ureturn->clazz, ureturn->clazz, Z_OBJ(EX(This)) ? &EX(This) : NULL);

	if (zend_fcall_info_init(&closure, 0, &fci, &fcc, NULL, &error) != SUCCESS) {
		if (EX(func)->common.scope) {
			zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "cannot use return value set for %s::%s as function: %s",
				ZSTR_VAL(EX(func)->common.scope->name), 
				ZSTR_VAL(EX(func)->common.function_name), error);
		} else {
			zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "cannot use return value set for %s as function: %s",
				ZSTR_VAL(EX(func)->common.function_name), error);
		}
		if (error) {
			efree(error);
		}
		goto _exit_cleanup;
	}

	fci.param_count = ZEND_CALL_NUM_ARGS(execute_data);
	fci.params = ZEND_CALL_ARG(execute_data, 1);
	fci.retval= result;
	
	if (zend_call_function(&fci, &fcc) == SUCCESS) {
		if (!return_value) {
			if (!Z_ISUNDEF(rv)) {
				zval_ptr_dtor(&rv);
			}
		}
	}

_exit_cleanup:
	zval_ptr_dtor(&closure);
}

static inline int update_opcode_leave(zend_execute_data *execute_data) {
	zend_execute_data *call = EX(call);

	EX(call) = call->prev_execute_data;
	EX(opline) = EX(opline) + 1;

	return ZEND_USER_OPCODE_LEAVE;
}

ddtrace_dispatch_t* find_dispatch(zend_function *function) {
	zend_string *key;
	ddtrace_dispatch_t *dispatch;
	HashTable *dispatch_lookup;

	if (!function->common.function_name) {
		return NULL;
	}

	if (function->common.scope) {
		dispatch_lookup = zend_hash_find_ptr(&DDTRACE(dispatch_lookup), function->common.scope->name);
	} else {
		dispatch_lookup = zend_hash_index_find_ptr(&DDTRACE(dispatch_lookup), 0);
	}

	if (!dispatch_lookup) {
		return NULL;
	}

	key = zend_string_tolower(function->common.function_name);
	dispatch = zend_hash_find_ptr(returns, key);
	zend_string_release(key);

	return dispatch;
}

int ddtrace_wrap_fcall(zend_execute_data *execute_data) {
	zend_execute_data *call = EX(call);

	if (call) {
	    ddtrace_dispatch_t *dispatch;
		dispatch = find_dispatch(call->func);

		if (dispatch && (dispatch->flags ^ BUSY_FLAG)) {
			const zend_op *opline = EX(opline);
			zval rv, *return_value = RETURN_VALUE_USED(opline) ? EX_VAR(EX(opline)->result.var) : &rv;

            dispatch->flags ^= BUSY_FLAG; // guard against recursion, catching only topmost execution

            execute_fcall(dispatch, call, return_value);

            dispatch->flags ^= BUSY_FLAG;

            if (!RETURN_VALUE_USED(opline)) {
                zval_ptr_dtor(&rv);
            }

            return update_opcode_leave(execute_data);
		}
	}

	if (ddtrace_old_fcall_handler) {
		return ddtrace_old_fcall_handler(execute_data);
	}

	return ZEND_USER_OPCODE_DISPATCH;
} 

zend_bool ddtrace_trace(zend_class_entry *clazz, zend_string *raw_name, zval *callable) {
	HashTable *dispatch_lookup;
	ddtrace_dispatch_t dispatch;
	zend_string *name = zend_string_tolower(raw_name); // method/function names are case insensitive in PHP
	zend_function *function;

	if (clazz) {
		if (find_method(clazz, name, &function) != SUCCESS) {
			zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
				"failed to set return for %s::%s, the method does not exist",
				ZSTR_VAL(clazz->name),
				ZSTR_VAL(name));
			zend_string_release(name);
			return 0;
		}

		if (function->common.scope != clazz) {
			zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
				"failed to set return for %s::%s, the method is defined in %s",
				ZSTR_VAL(clazz->name),
				ZSTR_VAL(name),
				ZSTR_VAL(function->common.scope->name));
			zend_string_release(name);
			return 0;
		}
	}

	if (clazz) {
		dispatch_lookup = zend_hash_find_ptr(&DDTRACE(dispatch_lookup), clazz->name);
	} else {
        dispatch_lookup = zend_hash_index_find_ptr(&DDTRACE(dispatch_lookup), 0);
    }
	
	if (!dispatch_lookup) {
		ALLOC_HASHTABLE(dispatch_lookup);
		zend_hash_init(dispatch_lookup, 8, NULL, dispatch_free, 0);
		if (clazz) {
			zend_hash_update_ptr(&DDTRACE(dispatch_lookup), clazz->name, dispatch_lookup);
		} else {
            zend_hash_index_update_ptr(&DDTRACE(dispatch_lookup), 0, dispatch_lookup);
        }
	}

	memset(&dispatch, 0, sizeof(ddtrace_dispatch_t));
	
	dispatch.clazz = clazz;
	dispatch.function = zend_string_copy(name);
	ZVAL_COPY(&dispatch.callable, callable);

	if (!zend_hash_update_mem(dispatch_lookup, name, &dispatch, sizeof(ddtrace_dispatch_t))) {
		zend_string_release(dispatch.function);
		zend_string_release(name);
		return 0;
	}

	return 1;
}