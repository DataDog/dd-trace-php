#pragma once

void dd_rasp_startup(void);
void dd_rasp_shutdown(void);
void dd_rasp_account_duration_us(double duration_us);
void dd_rasp_reset_globals(void); // call on rinit/user req begin
void dd_rasp_req_finish(void); // call on rshutdown/user req shutdown
