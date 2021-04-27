enable_language(CXX)

# The Catch2::Catch2 target has been available since 2.1.2
# dd-trace-cpp uses Catch2 2.4 at this time, so we can require at least 2.4
find_package(Catch2 2.4 REQUIRED)

include(Catch)

add_library(catch2main main.cc)
target_link_libraries(catch2main PUBLIC Catch2::Catch2)
set_target_properties(catch2main PROPERTIES
  CXX_STANDARD 11
)

add_executable(client client.cc)
target_link_libraries(client PUBLIC catch2main DogstatsdClient::DogstatsdClient)

catch_discover_tests(client)
