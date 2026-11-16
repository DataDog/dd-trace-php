macro(target_linker_flag_conditional target) # flags as argv
    try_compile(LINKER_HAS_FLAG "${CMAKE_CURRENT_BINARY_DIR}" "${CMAKE_CURRENT_SOURCE_DIR}/cmake/check.c"
        LINK_OPTIONS ${ARGN}
        OUTPUT_VARIABLE LINKER_HAS_FLAG_ERROR_LOG)

    if(LINKER_HAS_FLAG)
        target_link_options(${target} PRIVATE ${ARGN})
        message(STATUS "Linker has flag ${ARGN}")
    else()
        #message(STATUS "Linker does not have flag: ${LINKER_HAS_FLAG_ERROR_LOG}")
    endif()
endmacro()


