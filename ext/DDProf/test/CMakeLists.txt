# The Catch2::Catch2 target has been available since 2.1.2
# dd-trace-cpp was using Catch2 2.4 when I last looked, so seems a reasonable default
find_package(Catch2 2.4 REQUIRED)

include(Catch)

set(CMAKE_CXX_FLAGS "${CMAKE_CXX_FLAGS} -fsanitize=address -fno-omit-frame-pointer -g")

add_library(catch2main main.cc)
target_link_libraries(catch2main PUBLIC Catch2::Catch2)
set_target_properties(catch2main PROPERTIES CXX_STANDARD 17)

add_executable(test_ddprof exporter.cc periodic_service.cc scheduler.cc string_table.cc)
target_link_libraries(test_ddprof PUBLIC catch2main DDProf::DDProf)

catch_discover_tests(test_ddprof)

