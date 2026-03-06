# Prefer a locally installed LLVM 17 run-clang-tidy (e.g. via brew install llvm@17)
# over the Docker-based wrapper, since native execution avoids SDK incompatibilities.
set(_LLVM17_BIN /opt/homebrew/opt/llvm@17/bin)
set(_LLVM17_TIDY ${_LLVM17_BIN}/run-clang-tidy)
set(CLANG_TIDY_BINARY_OPT "")
if(EXISTS ${_LLVM17_TIDY})
    set(CLANG_TIDY ${_LLVM17_TIDY})
    set(CLANG_TIDY_BINARY_OPT -clang-tidy-binary ${_LLVM17_BIN}/clang-tidy)
    message(STATUS "Using Homebrew LLVM 17 run-clang-tidy: ${CLANG_TIDY}")
else()
    find_program(_RCT_VERSIONED run-clang-tidy-17)
    if(NOT _RCT_VERSIONED STREQUAL _RCT_VERSIONED-NOTFOUND)
        set(CLANG_TIDY ${_RCT_VERSIONED})
        find_program(_CT_VERSIONED clang-tidy-17)
        if(NOT _CT_VERSIONED STREQUAL _CT_VERSIONED-NOTFOUND)
            set(CLANG_TIDY_BINARY_OPT -clang-tidy-binary ${_CT_VERSIONED})
        endif()
    else()
        find_program(_RCT_UNVERSIONED run-clang-tidy)
        if(NOT _RCT_UNVERSIONED STREQUAL _RCT_UNVERSIONED-NOTFOUND)
            # Verify version via co-located clang-tidy
            get_filename_component(_RCT_DIR ${_RCT_UNVERSIONED} DIRECTORY)
            find_program(_CT_COLOCATED clang-tidy HINTS ${_RCT_DIR} NO_DEFAULT_PATH)
            if(NOT _CT_COLOCATED STREQUAL _CT_COLOCATED-NOTFOUND)
                execute_process(
                    COMMAND ${_CT_COLOCATED} --version
                    OUTPUT_VARIABLE _CT_VERSION
                    OUTPUT_STRIP_TRAILING_WHITESPACE
                    ERROR_QUIET)
                if(_CT_VERSION MATCHES " 17\\.")
                    set(CLANG_TIDY ${_RCT_UNVERSIONED})
                    set(CLANG_TIDY_BINARY_OPT -clang-tidy-binary ${_CT_COLOCATED})
                endif()
            endif()
        endif()
    endif()
    if(NOT CLANG_TIDY)
        set(CLANG_TIDY ${CMAKE_CURRENT_LIST_DIR}/clang-tools/run-clang-tidy)
        if(NOT EXISTS ${CLANG_TIDY})
            message(STATUS "Cannot find clang-tidy version 17, either set CLANG_TIDY or make it discoverable")
            return()
        endif()
        message(STATUS "Using Docker-based run-clang-tidy wrapper: ${CLANG_TIDY}")
    endif()
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

set(TIDY_DEPS "")
if(DD_APPSEC_BUILD_EXTENSION AND TARGET libxml2_build)
    list(APPEND TIDY_DEPS libxml2_build)
endif()
if(TARGET boost_build)
    list(APPEND TIDY_DEPS boost_build)
endif()

add_custom_target(tidy
    COMMAND ${CLANG_TIDY} ${CLANG_TIDY_BINARY_OPT} -use-color -p ${CMAKE_BINARY_DIR} ${FILE_LIST}
    WORKING_DIRECTORY ${CMAKE_BINARY_DIR}
    DEPENDS ${TIDY_DEPS})

add_custom_target(tidy_fix
    COMMAND ${CLANG_TIDY} ${CLANG_TIDY_BINARY_OPT} -use-color -fix -p ${CMAKE_BINARY_DIR} ${FILE_LIST}
    WORKING_DIRECTORY ${CMAKE_BINARY_DIR}
    DEPENDS ${TIDY_DEPS})
