include(FetchContent)

option(MPACK_DEBUG "Compile mpack with MPACK_DEBUG=1 and min buffer size" OFF)

if(DD_APPSEC_BUILD_HELPER)
    set(LIBDDWAF_TESTING OFF)
    add_subdirectory(libddwaf EXCLUDE_FROM_ALL)

    FetchContent_Declare(
        googletest
        GIT_REPOSITORY https://github.com/google/googletest.git
        GIT_TAG 52eb8108c5bdec04579160ae17225d66034bd723)

    FetchContent_MakeAvailable(googletest)

    file(GLOB_RECURSE MSGPACK_C_SOURCES ${CMAKE_CURRENT_SOURCE_DIR}/msgpack-c/src/*.c)
    add_library(msgpack_c STATIC ${MSGPACK_C_SOURCES})
    set_target_properties(msgpack_c PROPERTIES POSITION_INDEPENDENT_CODE 1)
    target_include_directories(msgpack_c PUBLIC ${CMAKE_CURRENT_SOURCE_DIR}/msgpack-c/include/)
    target_compile_definitions(msgpack_c INTERFACE MSGPACK_CXX17=ON)

    file(GLOB_RECURSE CPPBASE64_C_SOURCES ${CMAKE_CURRENT_SOURCE_DIR}/cpp-base64/base64.cpp)
    add_library(cpp-base64 STATIC ${CPPBASE64_C_SOURCES})
    target_include_directories(cpp-base64 PUBLIC ${CMAKE_CURRENT_SOURCE_DIR}/cpp-base64)
    set_target_properties(cpp-base64 PROPERTIES
        POSITION_INDEPENDENT_CODE 1
        C_VISIBILITY_PRESET hidden)
endif()

if(DD_APPSEC_BUILD_EXTENSION)
    file(GLOB_RECURSE MPACK_C_SOURCES ${CMAKE_CURRENT_SOURCE_DIR}/../../ext/vendor/mpack/*.c)
    add_library(mpack OBJECT ${MPACK_C_SOURCES})
    set_target_properties(mpack PROPERTIES
        POSITION_INDEPENDENT_CODE 1
        C_VISIBILITY_PRESET hidden)

    target_include_directories(mpack PUBLIC ${CMAKE_CURRENT_SOURCE_DIR}/../../ext/vendor/mpack/)
    if(MPACK_DEBUG)
        target_compile_definitions(mpack PUBLIC MPACK_DEBUG=1 MPACK_BUFFER_SIZE=32)
    else()
        target_compile_definitions(mpack PUBLIC MPACK_STDIO=0 MPACK_WRITE_TRACKING=1 MPACK_BUFFER_SIZE=131072)
    endif()
endif()

# spdlog
FetchContent_Declare(
    spdlog
    GIT_REPOSITORY https://github.com/gabime/spdlog.git
    GIT_TAG 5fd32e1a70871e2f6a52734e36bc33cb7ac022a5)
FetchContent_MakeAvailable(spdlog)
set_target_properties(spdlog PROPERTIES POSITION_INDEPENDENT_CODE 1)

# zlib
set(ZLIB_VERSION v1.3)
FetchContent_Declare(
        zlib
        GIT_REPOSITORY https://github.com/madler/zlib.git
        GIT_TAG        ${ZLIB_VERSION}
)
FetchContent_MakeAvailable(zlib)
if(NOT(MSVC))
    set_property(TARGET zlibstatic PROPERTY POSITION_INDEPENDENT_CODE ON)
endif()

target_compile_definitions(zlibstatic PUBLIC ZLIB_CONST=1)
target_include_directories(zlibstatic INTERFACE ${zlib_SOURCE_DIR} ${zlib_BINARY_DIR})

# rapidJson
FetchContent_Declare(
    rapidjson
    GIT_REPOSITORY https://github.com/Tencent/rapidjson.git
    GIT_TAG 24b5e7a8b27f42fa16b96fc70aade9106cf7102f
    GIT_SHALLOW TRUE
)
FetchContent_Populate(rapidjson) # don't read its CMakeLists.txt
add_library(rapidjson_appsec INTERFACE)
target_include_directories(rapidjson_appsec INTERFACE ${rapidjson_SOURCE_DIR}/include)
target_compile_definitions(rapidjson_appsec INTERFACE RAPIDJSON_HAS_STDSTRING=1)
