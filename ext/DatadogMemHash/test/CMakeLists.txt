find_package(Catch2 2.4 REQUIRED)

include(Catch)

add_library(catch2main main.cc)
target_link_libraries(catch2main PUBLIC Catch2::Catch2)
set_target_properties(catch2main PROPERTIES
  CXX_STANDARD 11
)

add_executable(test_cantor cantor.cc)
target_link_libraries(test_cantor PRIVATE catch2main Datadog::CxxMemHash)

catch_discover_tests(test_cantor)
