function(split_debug target destination)
    if(NOT (CMAKE_BUILD_TYPE STREQUAL RelWithDebInfo))
        return()
    endif()

    if (CMAKE_SYSTEM_NAME STREQUAL Darwin)
        # Ensure that dsymutil and strip is present
        find_program(DSYMUTIL dsymutil)
        if (DSYMUTIL STREQUAL "DSYMUTIL-NOTFOUND")
            message(FATAL_ERROR "dsymutil not found")
        endif()
        find_program(STRIP strip)
        if (STRIP STREQUAL "STRIP-NOTFOUND")
            message(FATAL_ERROR "strip not found")
        endif()

        set(SYMBOL_FILE $<TARGET_FILE:${target}>.dwarf)
        add_custom_command(TARGET ${target} POST_BUILD
            COMMAND ${CMAKE_COMMAND} -E copy $<TARGET_FILE:${target}> ${SYMBOL_FILE}
            COMMAND ${DSYMUTIL} --flat --minimize ${SYMBOL_FILE}
            COMMAND ${STRIP} -S -x $<TARGET_FILE:libddwaf_shared>
            COMMAND rm ${SYMBOL_FILE}
            COMMAND mv ${SYMBOL_FILE}.dwarf ${SYMBOL_FILE})
    elseif(NOT WIN32)
        set(SYMBOL_FILE $<TARGET_FILE:${target}>.debug)
        add_custom_command(TARGET ${target} POST_BUILD
            COMMAND ${CMAKE_COMMAND} -E copy $<TARGET_FILE:${target}> ${SYMBOL_FILE}
            COMMAND ${CMAKE_STRIP} --only-keep-debug ${SYMBOL_FILE}
            COMMAND ${CMAKE_STRIP} $<TARGET_FILE:${target}>)
    endif()

    install(FILES ${SYMBOL_FILE} DESTINATION ${destination} COMPONENT debug)
endfunction()

# vim: set et:
