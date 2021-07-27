#ifndef DD_CONFIGURATION_RENDER_H
#define DD_CONFIGURATION_RENDER_H
#include <pthread.h>
#include <string.h>

#include "env_config.h"

// this file uses X-Macro concept to render all helpful APIs for automatic setup and cleanup

#define CFG(type, name, ...) name,
typedef enum {
DD_CONFIGURATION
} ddtrace_config_id;
#undef CFG

#define BOOL(id) static inline bool get_##id(void) { return IS_TRUE == Z_TYPE_P(zai_config_get_value(id)); }
#define INT(id) static inline zend_long get_##id(void) { return Z_LVAL_P(zai_config_get_value(id)); }
#define DOUBLE(id) static inline double get_##id(void) { return Z_DVAL_P(zai_config_get_value(id)); }
#define CHAR(id) static inline zend_string *get_##id(void) { return Z_STR_P(zai_config_get_value(id)); }
#define MAP(id) static inline zend_array *get_##id(void) { return Z_ARR_P(zai_config_get_value(id)); }

#define CFG(type, name, ...) type(name)
DD_CONFIGURATION
#undef CFG

// cleanup configuration getter macros
#undef CHAR
#undef MAP
#undef BOOL
#undef INT
#undef DOUBLE

#endif  // DD_CONFIGURATION_RENDER_H
