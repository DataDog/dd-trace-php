add_executable(symbols 
    lookup/class.cc
    lookup/function.cc 
    lookup/constant.cc 
    lookup/property.cc 
    lookup/local/static.cc 
    lookup/local/frame.cc 
    call/internal.cc 
    call/user.cc
    call/closure.cc
    api/class.cc
    api/constant.cc
    api/function.cc
    api/property.cc
    api/call.cc)

target_link_libraries(symbols PUBLIC catch2_main Tea::Tea Zai::Value Zai::Symbols)

file(COPY ${CMAKE_CURRENT_SOURCE_DIR}/stubs
     DESTINATION ${CMAKE_CURRENT_BINARY_DIR})

catch_discover_tests(symbols)
