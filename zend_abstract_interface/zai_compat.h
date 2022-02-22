#ifndef ZAI_COMPAT_H
#define ZAI_COMPAT_H

#if PHP_VERSION_ID >= 70000
#define ZAI_TSRMLS_C
#define ZAI_TSRMLS_CC
#define ZAI_TSRMLS_D
#define ZAI_TSRMLS_DC
#define ZAI_TSRMLS_FETCH()

#define smart_str_length(str) (str)->s->len
#define smart_str_value(str) ((char *)(str)->s->val)
#else
#define ZAI_TSRMLS_C TSRMLS_C
#define ZAI_TSRMLS_CC TSRMLS_CC
#define ZAI_TSRMLS_D TSRMLS_D
#define ZAI_TSRMLS_DC TSRMLS_DC
#define ZAI_TSRMLS_FETCH() TSRMLS_FETCH()

#define smart_str_length(str) (str)->len
#define smart_str_value(str) ((char *)(str)->c)
#endif

#endif  // ZAI_COMPAT_H
