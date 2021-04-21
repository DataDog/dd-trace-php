#include "../sandbox.h"

extern inline void zai_sandbox_open_ex(zai_sandbox *sandbox TSRMLS_DC);
extern inline void zai_sandbox_close_ex(zai_sandbox *sandbox TSRMLS_DC);

extern inline void zai_sandbox_error_state_backup_ex(zai_error_state *es TSRMLS_DC);
extern inline void zai_sandbox_error_state_restore_ex(zai_error_state *es TSRMLS_DC);

extern inline void zai_sandbox_exception_state_backup_ex(zai_exception_state *es TSRMLS_DC);
extern inline void zai_sandbox_exception_state_restore_ex(zai_exception_state *es TSRMLS_DC);
