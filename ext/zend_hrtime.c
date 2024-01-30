#include "zend_hrtime.h"

#if ZEND_HRTIME_PLATFORM_POSIX
# include <unistd.h>
# include <time.h>
# include <string.h>
#elif ZEND_HRTIME_PLATFORM_WINDOWS
# define WIN32_LEAN_AND_MEAN
ZEND_API double zend_hrtime_timer_scale = .0;
#elif ZEND_HRTIME_PLATFORM_APPLE
# include <mach/mach_time.h>
# include <string.h>
mach_timebase_info_data_t zend_hrtime_timerlib_info = {
	.numer = 0,
	.denom = 1,
};
#endif

void ddtrace_startup_hrtime(void){
#if ZEND_HRTIME_PLATFORM_WINDOWS
    LARGE_INTEGER tf = {0};
    if (QueryPerformanceFrequency(&tf) || 0 != tf.QuadPart) {
        zend_hrtime_timer_scale = (double)ZEND_NANO_IN_SEC / (zend_hrtime_t)tf.QuadPart;
    }
#elif ZEND_HRTIME_PLATFORM_APPLE
    mach_timebase_info(&zend_hrtime_timerlib_info);
#endif
}
