#include "../sandbox.h"

extern inline void zai_sandbox_open(zai_sandbox *sandbox);
extern inline void zai_sandbox_close(zai_sandbox *sandbox);
extern inline void zai_sandbox_bailout(zai_sandbox *sandbox);
extern inline bool zai_sandbox_timed_out(void);

extern inline void zai_sandbox_error_state_backup(zai_error_state *es);
extern inline void zai_sandbox_error_state_restore(zai_error_state *es);

extern inline void zai_sandbox_exception_state_backup(zai_exception_state *es);
extern inline void zai_sandbox_exception_state_restore(zai_exception_state *es);

extern inline void zai_sandbox_engine_state_backup(zai_engine_state *es);
extern inline void zai_sandbox_engine_state_restore(zai_engine_state *es);
