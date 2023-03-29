#ifndef ZAI_INTERCEPTOR_H
#define ZAI_INTERCEPTOR_H

#if PHP_VERSION_ID < 80200
extern int zai_registered_observers;

void zai_interceptor_execute_internal_with_handler(INTERNAL_FUNCTION_PARAMETERS, zif_handler handler);
#endif

void zai_interceptor_minit(void);
void zai_interceptor_startup(void);
void zai_interceptor_activate(void);
void zai_interceptor_deactivate(void);
void zai_interceptor_shutdown(void);

#endif  // ZAI_INTERCEPTOR_H
