# We don't link against PCRE2; we only need the include path for pcre2.h
# If PHP has bundled PCRE, the header is already in PHP's include path
if(NOT TARGET PCRE2::pcre2)
    include(CheckSymbolExists)
    set(_PCRE2_SAVED_CMAKE_REQUIRED_INCLUDES ${CMAKE_REQUIRED_INCLUDES})
    set(CMAKE_REQUIRED_INCLUDES ${PhpConfig_INCLUDE_DIRS})
    check_symbol_exists(HAVE_BUNDLED_PCRE "main/php_config.h" PHP_HAS_BUNDLED_PCRE)
    set(CMAKE_REQUIRED_INCLUDES ${_PCRE2_SAVED_CMAKE_REQUIRED_INCLUDES})
    unset(_PCRE2_SAVED_CMAKE_REQUIRED_INCLUDES)

    add_library(PCRE2::pcre2 INTERFACE IMPORTED)

    if(NOT PHP_HAS_BUNDLED_PCRE)
        find_path(PCRE2_INCLUDE_DIR NAMES pcre2.h
            HINTS ${PhpConfig_ROOT_DIR}/include ${PhpConfig_INCLUDE_DIRS})

        if(NOT PCRE2_INCLUDE_DIR)
            message(FATAL_ERROR "Could not find pcre2.h. Set PCRE2_ROOT to specify location.")
        endif()

        message(STATUS "Found PCRE2 include dir: ${PCRE2_INCLUDE_DIR}")
        set_target_properties(PCRE2::pcre2 PROPERTIES
            INTERFACE_INCLUDE_DIRECTORIES "${PCRE2_INCLUDE_DIR}")
    else()
        message(STATUS "Using PHP's bundled PCRE2")
    endif()
endif()
