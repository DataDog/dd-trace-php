find_package(Catch2 2.4 REQUIRED)

include(Catch)

add_library(catch2main main.cc)
target_link_libraries(catch2main PUBLIC Catch2::Catch2)
set_target_properties(catch2main PROPERTIES
  CXX_STANDARD 11
)

add_executable(test_ddprof arena.cc cantor.cc memhash.cc string_table.cc)
target_link_libraries(test_ddprof PRIVATE catch2main DDProf::DDProf)

catch_discover_tests(test_ddprof)
