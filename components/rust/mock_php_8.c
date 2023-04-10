// Unless explicitly stated otherwise all files in this repository are licensed under the Apache
// License Version 2.0. This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021-Present Datadog, Inc.

#include <stdint.h>

int OnUpdateBool(void *, void *, void *, void *, void *, int) { return 0;}
void zend_ini_boolean_displayer_cb(void *, int) {}
int OnUpdateString(void *, void *, void *, void *, void *, int) {return 0; };
int sapi_module() { return 0; }
const void* zend_empty_array;
const void* empty_fcall_info_cache;
void **zend_known_strings;
void **zend_ce_exception;
void * zend_new_interned_string;
void* (*zend_post_startup_cb)(void);
void * core_globals;
void* executor_globals;
void zval_add_ref(void *){
}
void* file_globals;

void* zend_string_init_interned;
void zval_ptr_dtor(void *){
}
void zend_ce_generator(void *){
}
void zend_ce_error(void *){
}

void  *zend_one_char_string[256];
void * std_object_handlers;
void * compiler_globals;
void (*zend_error_cb)(int, void *, uint32_t, void *);
const void * empty_fcall_info;
void* sapi_globals;

void zval_internal_ptr_dtor(void *) {}
void zend_observer_fcall_op_array_extension(void *) {}
void *(*zend_compile_file)(void *, int );

double pow(double, double) { return 0.0; }
void  *zend_empty_string;
float powf(float, float) { return 0.0;}
void  *zend_extensions;
void  *zend_ce_closure;
void  *zend_ce_throwable;
void * module_registry;
void  *module_registsry;
