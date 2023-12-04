function(patch_away_libc target)
    if (NOT ${DD_APPSEC_ENABLE_PATCHELF_LIBC})
        return()
    endif()

    if (CMAKE_SYSTEM_NAME STREQUAL Darwin)
        return()
    endif()

    find_program(PATCHELF patchelf)
    if (PATCHELF STREQUAL "PATCHELF-NOTFOUND")
        message(WARNING "Patchelf not found. Can't build glibc + musl binaries")
    else()
        add_custom_command(TARGET ${target} POST_BUILD
            COMMAND patchelf --remove-needed libc.so $<TARGET_FILE:${target}> ${SYMBOL_FILE})
    endif()
endfunction()
