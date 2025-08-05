find_program(READELF_PROGRAM readelf)
set(ENABLE_ASAN FALSE CACHE BOOL "Enable ASAN")
if(READELF_PROGRAM)
    execute_process(COMMAND ${READELF_PROGRAM} -d ${PhpConfig_PHP_BINARY}
                    COMMAND grep NEEDED
                    COMMAND grep asan
                    RESULT_VARIABLE result
                    OUTPUT_QUIET
                    ERROR_QUIET)
    if(result EQUAL 0)
        set(ENABLE_ASAN TRUE CACHE BOOL "Enable ASAN" FORCE)
    endif()
else()
    message(WARNING "readelf unavailable. Cannot detect ASAN")
endif()

if(ENABLE_ASAN)
    message(STATUS "Enabling ASAN")
    target_compile_options(PhpConfig INTERFACE -fsanitize=address)
    target_compile_definitions(PhpConfig INTERFACE ZEND_TRACK_ARENA_ALLOC)
    target_link_options(PhpConfig INTERFACE -fsanitize=address)
else()
    message(STATUS "ASAN is disabled")
endif()

