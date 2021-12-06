add_library(datadog-php-time OBJECT time.c time.h)

target_include_directories(datadog-php-time
  PUBLIC $<BUILD_INTERFACE:${CMAKE_CURRENT_SOURCE_DIR}/../..>
)

include(CheckCSourceRuns)
include(CMakePushCheckState)

find_package(Threads)

#[[ Prior to glibc 2.17, librt was required for clock_gettime.
    Check for it without flags first.
    clock_gettime gets used with pthread_getcpuclockid if the platform has it.
]]
cmake_push_check_state(RESET)
set(CLOCK_GETTIME_PROGRAM "#include <time.h>
int main(void) {
  struct timespec ts;
  return clock_gettime(CLOCK_REALTIME, &ts) == 0 ? 0 : 1;
}
")
check_c_source_runs("${CLOCK_GETTIME_PROGRAM}" DATADOG_HAVE_CLOCK_GETTIME)
cmake_pop_check_state()

if (NOT DATADOG_HAVE_CLOCK_GETTIME)
  # Try again with -lrt; use a different cache variable name or it won't run.
  cmake_push_check_state(RESET)
  set(CMAKE_REQUIRED_LIBRARIES -lrt)
  check_c_source_runs("${CLOCK_GETTIME_PROGRAM}" DATADOG_HAVE_CLOCK_GETTIME_RT)

  if (DATADOG_HAVE_CLOCK_GETTIME_RT)
    target_link_libraries(datadog-php-time PRIVATE -lrt)
  endif ()
  cmake_pop_check_state()
endif ()

if (DATADOG_HAVE_CLOCK_GETTIME OR DATADOG_HAVE_CLOCK_GETTIME_RT)
  target_compile_definitions(datadog-php-time PRIVATE -DDATADOG_HAVE_CLOCK_GETTIME=1)

  cmake_push_check_state(RESET)
  set(CMAKE_REQUIRED_LIBRARIES Threads::Threads)
  check_c_source_runs("
  #include <pthread.h>
  #include <time.h>

  int main(void) {
    clockid_t clockid;
    // consider the fact the API exists good enough; it may still fail at runtime,
    // and that's okay; it just needs to exist.
    (void)pthread_getcpuclockid(pthread_self(), &clockid);
    return 0;
  }
  " DATADOG_HAVE_PTHREAD_GETCPUCLOCKID)
  if (DATADOG_HAVE_PTHREAD_GETCPUCLOCKID)
    target_compile_definitions(datadog-php-time PRIVATE -DDATADOG_HAVE_PTHREAD_GETCPUCLOCKID=1)
  endif ()
  cmake_pop_check_state()
endif ()

check_c_source_runs("#include <mach/mach_init.h>
#include <mach/thread_act.h>

int main(void) {
	mach_port_t thread = mach_thread_self();
	mach_msg_type_number_t count = THREAD_BASIC_INFO_COUNT;
	thread_basic_info_data_t info;
	(void)thread_info(thread, THREAD_BASIC_INFO, (thread_info_t) &info, &count);
	return 0;
}
" DATADOG_HAVE_THREAD_INFO)

if (DATADOG_HAVE_THREAD_INFO)
  target_compile_definitions(datadog-php-time PRIVATE -DDATADOG_HAVE_THREAD_INFO=1)
endif ()
