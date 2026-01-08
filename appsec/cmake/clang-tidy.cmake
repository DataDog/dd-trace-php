find_program(CLANG_TIDY run-clang-tidy)
if(CLANG_TIDY STREQUAL CLANG_TIDY-NOTFOUND)
    message(STATUS "Cannot find clang-tidy, either set CLANG_TIDY or make it discoverable")
    return()
endif()

set(FILE_LIST "")
macro(append_target_sources target)
    get_target_property(_srcs ${target} SOURCES)
    foreach(_src_file IN LISTS _srcs)
        list(APPEND FILE_LIST ${_src_file})
    endforeach()
endmacro()

if(DD_APPSEC_BUILD_HELPER)
    file(GLOB_RECURSE FILE_LIST ${HELPER_SOURCE_DIR}/*.*pp)
endif()

if(DD_APPSEC_BUILD_EXTENSION)
    append_target_sources(extension)
endif()

execute_process (
    COMMAND bash -c "${CLANG_TIDY} --help | grep -qs 'use-color'"
    RESULT_VARIABLE USE_COLOR
)

set(COLOR_OPT "")
if (USE_COLOR EQUAL 0)
    set(COLOR_OPT -use-color)
endif()

set(TIDY_DEPS "")
if(DD_APPSEC_BUILD_EXTENSION AND TARGET libxml2_build)
    list(APPEND TIDY_DEPS libxml2_build)
endif()

add_custom_target(tidy
    COMMAND ${CLANG_TIDY} ${COLOR_OPT} -p ${CMAKE_BINARY_DIR} ${FILE_LIST}
    WORKING_DIRECTORY ${CMAKE_BINARY_DIR}
    DEPENDS ${TIDY_DEPS})

add_custom_target(tidy_fix
    COMMAND ${CLANG_TIDY} ${COLOR_OPT} -fix -p ${CMAKE_BINARY_DIR} ${FILE_LIST}
    WORKING_DIRECTORY ${CMAKE_BINARY_DIR}
    DEPENDS ${TIDY_DEPS})
