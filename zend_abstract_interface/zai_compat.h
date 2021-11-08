#ifndef ZAI_COMPAT_H
#define ZAI_COMPAT_H

// macros to be used only within ZAI headers or tests to avoid duplication of code lines
#if PHP_VERSION_ID >= 70000
#define ZAI_TSRMLS_C
#define ZAI_TSRMLS_CC
#define ZAI_TSRMLS_D
#define ZAI_TSRMLS_DC
#define ZAI_TSRMLS_FETCH()
#else
#define ZAI_TSRMLS_C TSRMLS_C
#define ZAI_TSRMLS_CC TSRMLS_CC
#define ZAI_TSRMLS_D TSRMLS_D
#define ZAI_TSRMLS_DC TSRMLS_DC
#define ZAI_TSRMLS_FETCH TSRMLS_FETCH
#endif

#endif  // ZAI_COMPAT_H
