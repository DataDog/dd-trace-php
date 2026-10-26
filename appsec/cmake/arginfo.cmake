# Generate arginfo headers from .stub.php files using gen_stub.php
# (make generate_arginfo).
#
# gen_stub.php is deliberately not pinned: the committed headers are generated
# with php-src master's, which knows the newest PHP versions, so its output
# changes occasionally. The one of the PHP found here may be older and drop
# declarations for newer versions; pass -DGEN_STUB_PHP=<php-src>/build/gen_stub.php
# to use master's.
find_file(GEN_STUB_PHP gen_stub.php
    PATHS "${PhpConfig_ROOT_DIR}/lib/php/build"
    DOC "Path to PHP's gen_stub.php"
    NO_DEFAULT_PATH
)

if(GEN_STUB_PHP)
    foreach(_stub_name tags entity_body ddappsec)
        set(_stub ${CMAKE_CURRENT_SOURCE_DIR}/src/extension/${_stub_name}.stub.php)
        set(_arginfo "${CMAKE_CURRENT_SOURCE_DIR}/src/extension/${_stub_name}_arginfo.h")
        add_custom_command(
            OUTPUT ${_arginfo}
            COMMAND ${PhpConfig_PHP_BINARY} ${GEN_STUB_PHP} ${_stub}
            DEPENDS ${_stub}
            WORKING_DIRECTORY ${CMAKE_CURRENT_SOURCE_DIR}/src/extension
            COMMENT "Generating ${_stub_name}_arginfo.h from ${_stub_name}.stub.php"
        )
        list(APPEND STUB_ARGINFO_HEADERS ${_arginfo})
    endforeach()
    add_custom_target(generate_arginfo DEPENDS ${STUB_ARGINFO_HEADERS})
else()
    message(WARNING "gen_stub.php not found; arginfo headers will not be auto-regenerated from stub files")
endif()
