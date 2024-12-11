#include "string.h"

char *ZAI_STRING_EMPTY_PTR = "";

extern inline zai_str zai_str_from_cstr(const char *cstr);

extern inline zai_str zai_str_from_zstr(zend_string *zstr);

extern inline zai_string zai_string_concat3(zai_str first, zai_str second, zai_str third);
