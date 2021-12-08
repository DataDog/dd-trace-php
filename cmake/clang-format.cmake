find_program(CLANG_FORMAT clang-format)
if(CLANG_FORMAT STREQUAL CLANG_FORMAT-NOTFOUND)
    message(STATUS "Cannot find clang-format, either set CLANG_FORMAT or make it discoverable")
    return()
endif()

set(FILE_LIST "")

if(DD_APPSEC_BUILD_HELPER)
    file(GLOB_RECURSE HELPER_FILES ${HELPER_SOURCE_DIR}/*.*pp tests/helper/*.*pp)
    list(APPEND FILE_LIST ${HELPER_FILES})
endif()

if(DD_APPSEC_BUILD_EXTENSION)
    file(GLOB_RECURSE EXTENSION_FILES ${EXT_SOURCE_DIR}/*.c tests/helper/*.h)
    list(APPEND FILE_LIST ${EXTENSION_FILES})
endif()

add_custom_target(format
    COMMAND ${CLANG_FORMAT} -n -Werror ${FILE_LIST}
    COMMAND ${CMAKE_SOURCE_DIR}/cmake/check_headers.rb
    WORKING_DIRECTORY ${CMAKE_BINARY_DIR})

add_custom_target(format_fix
    COMMAND ${CMAKE_SOURCE_DIR}/cmake/check_headers.rb --fix
    COMMAND ${CLANG_FORMAT} -i ${FILE_LIST}
    WORKING_DIRECTORY ${CMAKE_BINARY_DIR})

add_custom_target(format_fix_chg
    COMMAND bash -c "git status --porcelain=1 :/ | grep -E '\.(c|h|cpp|hpp)$' | awk '{ print \"${CMAKE_SOURCE_DIR}/\" $NF }' | xargs echo '${CLANG_FORMAT}' -i"
    WORKING_DIRECTORY ${CMAKE_BINARY_DIR})
