#define BOOL(id)                                                                                                 \
    static inline bool get_##id(void) { return IS_TRUE == Z_TYPE_P(zai_config_get_value(DATADOG_CONFIG_##id)); } \
    static inline bool get_global_##id(void) {                                                                   \
        return IS_TRUE == Z_TYPE(zai_config_memoized_entries[DATADOG_CONFIG_##id].decoded_value);                \
    }
#define INT(id)                                                                                            \
    static inline zend_long get_##id(void) { return Z_LVAL_P(zai_config_get_value(DATADOG_CONFIG_##id)); } \
    static inline zend_long get_global_##id(void) {                                                        \
        return Z_LVAL(zai_config_memoized_entries[DATADOG_CONFIG_##id].decoded_value);                     \
    }
#define DOUBLE(id)                                                                                      \
    static inline double get_##id(void) { return Z_DVAL_P(zai_config_get_value(DATADOG_CONFIG_##id)); } \
    static inline double get_global_##id(void) {                                                        \
        return Z_DVAL(zai_config_memoized_entries[DATADOG_CONFIG_##id].decoded_value);                  \
    }
#define STRING(id)                                                                                           \
    static inline zend_string *get_##id(void) { return Z_STR_P(zai_config_get_value(DATADOG_CONFIG_##id)); } \
    static inline zend_string *get_global_##id(void) {                                                       \
        return Z_STR(zai_config_memoized_entries[DATADOG_CONFIG_##id].decoded_value);                        \
    }
#define SET MAP
#define SET_LOWERCASE MAP
#define SET_OR_MAP_LOWERCASE MAP
#define JSON MAP
#define MAP(id)                                                                                             \
    static inline zend_array *get_##id(void) { return Z_ARR_P(zai_config_get_value(DATADOG_CONFIG_##id)); } \
    static inline zend_array *get_global_##id(void) {                                                       \
        return Z_ARR(zai_config_memoized_entries[DATADOG_CONFIG_##id].decoded_value);                       \
    }
#define CUSTOM(type) type

#define CALIAS CONFIG
#define CONFIG(type, name, ...) type(name)
DD_CONFIGURATION
#undef CONFIG
#undef CALIAS

#undef STRING
#undef MAP
#undef SET
#undef SET_LOWERCASE
#undef SET_OR_MAP_LOWERCASE
#undef JSON
#undef BOOL
#undef INT
#undef DOUBLE

#undef CUSTOM

#undef DD_CONFIGURATION
