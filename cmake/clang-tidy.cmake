find_program(CLANG_TIDY clang-tidy)
if(CLANG_TIDY STREQUAL CLANG_TIDY-NOTFOUND)
    message(FATAL_ERROR "Cannot find clang-tidy, either set CLANG_TIDY or make it discoverable")
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

add_custom_target(tidy
    COMMAND ${CLANG_TIDY} -p ${CMAKE_BINARY_DIR} ${FILE_LIST}
    WORKING_DIRECTORY ${CMAKE_BINARY_DIR})

add_custom_target(tidy_fix
    COMMAND ${CLANG_TIDY} --fix -p ${CMAKE_BINARY_DIR} ${FILE_LIST}
    WORKING_DIRECTORY ${CMAKE_BINARY_DIR})
