add_executable(properties "${PHP_PROPERTIES_VERSION_DIRECTORY}/properties.cc")

target_link_libraries(properties PUBLIC catch2_main Zai::Sapi Zai::Properties Zai::Functions)

file(COPY ${CMAKE_CURRENT_SOURCE_DIR}/stubs
     DESTINATION ${CMAKE_CURRENT_BINARY_DIR})

catch_discover_tests(properties)
