set(_LLVM17_FORMAT /opt/homebrew/opt/llvm@17/bin/clang-format)
if(EXISTS ${_LLVM17_FORMAT})
    set(CLANG_FORMAT ${_LLVM17_FORMAT})
    message(STATUS "Using Homebrew LLVM 17 clang-format: ${CLANG_FORMAT}")
else()
    find_program(_CF_VERSIONED clang-format-17)
    if(NOT _CF_VERSIONED STREQUAL _CF_VERSIONED-NOTFOUND)
        set(CLANG_FORMAT ${_CF_VERSIONED})
    else()
        find_program(_CF_UNVERSIONED clang-format)
        if(NOT _CF_UNVERSIONED STREQUAL _CF_UNVERSIONED-NOTFOUND)
            execute_process(
                COMMAND ${_CF_UNVERSIONED} --version
                OUTPUT_VARIABLE _CF_VERSION
                OUTPUT_STRIP_TRAILING_WHITESPACE
                ERROR_QUIET)
            if(_CF_VERSION MATCHES " 17\\.")
                set(CLANG_FORMAT ${_CF_UNVERSIONED})
            endif()
        endif()
    endif()
    if(NOT CLANG_FORMAT)
        set(CLANG_FORMAT ${CMAKE_CURRENT_LIST_DIR}/clang-tools/clang-format)
        if(NOT EXISTS ${CLANG_FORMAT})
            message(STATUS "Cannot find clang-format version 17, either set CLANG_FORMAT or make it discoverable")
            return()
        endif()
        message(STATUS "Using Docker-based clang-format wrapper: ${CLANG_FORMAT}")
    endif()
endif()

set(FILE_LIST "")

if(DD_APPSEC_BUILD_HELPER)
    file(GLOB_RECURSE HELPER_FILES ${HELPER_SOURCE_DIR}/*.*pp tests/helper/**.cpp tests/helper/**.hpp)
    list(APPEND FILE_LIST ${HELPER_FILES})
endif()

if(DD_APPSEC_BUILD_EXTENSION)
    file(GLOB_RECURSE EXTENSION_FILES ${EXT_SOURCE_DIR}/*.c ${EXT_SOURCE_DIR}/*.cpp tests/helper/*.h tests/bench_helper/*.cc)
    list(APPEND FILE_LIST ${EXTENSION_FILES})
endif()

add_custom_target(format
    COMMAND ${CLANG_FORMAT} -n -Werror ${FILE_LIST}
    WORKING_DIRECTORY ${CMAKE_BINARY_DIR})

add_custom_target(format_fix
    COMMAND ${CLANG_FORMAT} -i ${FILE_LIST}
    WORKING_DIRECTORY ${CMAKE_BINARY_DIR})

add_custom_target(headers
    COMMAND ${CMAKE_SOURCE_DIR}/cmake/check_headers.rb
    WORKING_DIRECTORY ${CMAKE_BINARY_DIR})

add_custom_target(headers_fix
    COMMAND ${CMAKE_SOURCE_DIR}/cmake/check_headers.rb --fix
    WORKING_DIRECTORY ${CMAKE_BINARY_DIR})

add_custom_target(format_fix_chg
    COMMAND bash -c "git status --porcelain=1 :/appsec/ | grep -E '\.(c|h|cpp|hpp)$$' | awk '{ print \"${CMAKE_SOURCE_DIR}/../\" $NF }' | xargs '${CLANG_FORMAT}' --dry-run"
    WORKING_DIRECTORY ${CMAKE_SOURCE_DIR}
    VERBATIM)
