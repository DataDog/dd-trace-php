#ifndef ZAI_INTERCEPTOR_H
#define ZAI_INTERCEPTOR_H

#if PHP_VERSION_ID < 80200
extern int zai_registered_observers;
#endif

void zai_interceptor_minit(void);
void zai_interceptor_startup(void);
void zai_interceptor_activate(void);
void zai_interceptor_deactivate(void);
void zai_interceptor_shutdown(void);

#endif  // ZAI_INTERCEPTOR_H
