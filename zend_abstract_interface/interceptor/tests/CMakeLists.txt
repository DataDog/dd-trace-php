add_executable(interceptor interceptor.cc resolver.cc)

set(THREADS_PREFER_PTHREAD_FLAG ON)
find_package(Threads REQUIRED)

target_link_libraries(interceptor PUBLIC catch2_main Threads::Threads Tea::Tea Zai::Symbols Zai::Hook Zai::Interceptor)

file(COPY ${CMAKE_CURRENT_SOURCE_DIR}/stubs
     DESTINATION ${CMAKE_CURRENT_BINARY_DIR})

catch_discover_tests(interceptor)
