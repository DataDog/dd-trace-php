include(FetchContent)

# Enable MPACK_DEBUG by default is this is a Debug build or we're building
# against a debug php release
set(MPACK_DEBUG_DEFAULT OFF)
if(DD_APPSEC_BUILD_EXTENSION)
    if(CMAKE_BUILD_TYPE STREQUAL "Debug")
        set(MPACK_DEBUG_DEFAULT ON)
    endif()
    if(PhpConfig_FOUND AND PhpConfig_EXECUTABLE)
        execute_process(
            COMMAND ${PhpConfig_EXECUTABLE} --configure-options
            OUTPUT_VARIABLE PHP_CONFIGURE_OPTIONS
            OUTPUT_STRIP_TRAILING_WHITESPACE
            ERROR_QUIET)
        if(PHP_CONFIGURE_OPTIONS MATCHES "--enable-debug")
            set(MPACK_DEBUG_DEFAULT ON)
        endif()
    endif()
endif()

option(MPACK_DEBUG "Compile mpack with MPACK_DEBUG=1 and min buffer size" ${MPACK_DEBUG_DEFAULT})

if(DD_APPSEC_BUILD_EXTENSION)
    file(GLOB_RECURSE MPACK_C_SOURCES ${CMAKE_CURRENT_SOURCE_DIR}/../../tracer/vendor/mpack/*.c)
    add_library(mpack OBJECT ${MPACK_C_SOURCES})
    set_target_properties(mpack PROPERTIES
        POSITION_INDEPENDENT_CODE 1
        C_VISIBILITY_PRESET hidden)

    target_include_directories(mpack
        PUBLIC
        ${CMAKE_CURRENT_SOURCE_DIR}/../../tracer/vendor/mpack/
        ${CMAKE_CURRENT_SOURCE_DIR}/../src/extension/mpack/)
    target_link_libraries(mpack PUBLIC PhpConfig) # for the allocation functions
    target_compile_definitions(mpack PUBLIC MPACK_HAS_CONFIG=1)
    if(MPACK_DEBUG)
        target_compile_definitions(mpack PUBLIC MPACK_DEBUG=1 MPACK_BUFFER_SIZE=32)
    else()
        target_compile_definitions(mpack PUBLIC MPACK_STDIO=0 MPACK_WRITE_TRACKING=1 MPACK_BUFFER_SIZE=131064)
    endif()

    if(DD_APPSEC_TESTING)
        add_library(mpack_malloc OBJECT ${MPACK_C_SOURCES})
        target_include_directories(mpack_malloc PUBLIC ${CMAKE_CURRENT_SOURCE_DIR}/../../tracer/vendor/mpack/)
        target_compile_definitions(mpack_malloc PUBLIC MPACK_STDIO=0 MPACK_WRITE_TRACKING=1 MPACK_BUFFER_SIZE=131064)
    endif()
endif()

if(DD_APPSEC_TESTING)
    set(SPDLOG_COMMIT 79524ddd08a4ec981b7fea76afd08ee05f83755d)
    if(DEFINED ENV{GITLAB_CI})
        set(SPDLOG_URL
            "https://depot-read-api-bzl.us1.ddbuild.io/github.com/gabime/spdlog/archive/${SPDLOG_COMMIT}.tar.gz")
    else()
        set(SPDLOG_URL
            "https://github.com/gabime/spdlog/archive/${SPDLOG_COMMIT}.tar.gz")
    endif()
    FetchContent_Declare(
        spdlog
        URL "${SPDLOG_URL}"
        URL_HASH SHA256=c6dee844e35a54cc80e3ab76aae2310763e0b49c7397549dfa4d4657d9737138)
    FetchContent_MakeAvailable(spdlog)
    set_target_properties(spdlog PROPERTIES POSITION_INDEPENDENT_CODE 1)
endif()

set(RAPIDJSON_COMMIT 24b5e7a8b27f42fa16b96fc70aade9106cf7102f)
if(DEFINED ENV{GITLAB_CI})
    set(RAPIDJSON_URL
        "https://depot-read-api-bzl.us1.ddbuild.io/github.com/Tencent/rapidjson/archive/${RAPIDJSON_COMMIT}.tar.gz")
else()
    set(RAPIDJSON_URL
        "https://github.com/Tencent/rapidjson/archive/${RAPIDJSON_COMMIT}.tar.gz")
endif()
FetchContent_Declare(
    rapidjson
    URL "${RAPIDJSON_URL}"
    URL_HASH SHA256=2d2601a82d2d3b7e143a3c8d43ef616671391034bc46891a9816b79cf2d3e7a8)
FetchContent_Populate(rapidjson) # don't read its CMakeLists.txt
add_library(rapidjson_appsec INTERFACE)
target_include_directories(rapidjson_appsec INTERFACE ${rapidjson_SOURCE_DIR}/include)
target_compile_definitions(rapidjson_appsec INTERFACE RAPIDJSON_HAS_STDSTRING=1)
