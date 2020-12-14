find_package(Catch2 2.4 REQUIRED)

include(Catch)

add_library(catch2main main.cc)
target_link_libraries(catch2main PUBLIC Catch2::Catch2)
set_target_properties(catch2main PROPERTIES
  CXX_STANDARD 11
)

add_executable(test_string_table string_table.cc)
target_link_libraries(test_string_table PRIVATE catch2main Datadog::StringTable Datadog::CxxMemHash)

catch_discover_tests(test_string_table)
