if(NOT TARGET PCRE2::pcre2)
    find_library(PCRE2_LIBRARY NAMES pcre2-8 pcre2
        HINTS ${PhpConfig_ROOT_DIR}/lib ${PhpConfig_LIBRARY_DIRS})
    find_path(PCRE2_INCLUDE_DIR NAMES pcre2.h
        HINTS ${PhpConfig_ROOT_DIR}/include ${PhpConfig_INCLUDE_DIRS})

    if(NOT PCRE2_LIBRARY)
        message(FATAL_ERROR "Could not find pcre2 library. Set PCRE2_ROOT to specify location.")
    endif()
    if(NOT PCRE2_INCLUDE_DIR)
        message(FATAL_ERROR "Could not find pcre2.h. Set PCRE2_ROOT to specify location.")
    endif()

    message(STATUS "Found PCRE2 library: ${PCRE2_LIBRARY}")
    message(STATUS "Found PCRE2 include dir: ${PCRE2_INCLUDE_DIR}")

    add_library(PCRE2::pcre2 UNKNOWN IMPORTED)
    set_target_properties(PCRE2::pcre2 PROPERTIES
        IMPORTED_LOCATION "${PCRE2_LIBRARY}"
        INTERFACE_INCLUDE_DIRECTORIES "${PCRE2_INCLUDE_DIR}")
endif()
