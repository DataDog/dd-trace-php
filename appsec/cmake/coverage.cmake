macro(maybe_enable_coverage target)
    if(DD_APPSEC_ENABLE_COVERAGE)
        if(CMAKE_CXX_COMPILER_ID MATCHES "Clang")
            target_compile_options(${target} PRIVATE
                -fprofile-instr-generate -fcoverage-mapping -mllvm -runtime-counter-relocation=true)
            target_link_options(${target} PUBLIC
                -fprofile-instr-generate -fcoverage-mapping)
        else()
            target_compile_options(${target} PRIVATE --coverage)
            target_link_options(${target} PRIVATE --coverage)
        endif()
    endif()
endmacro()

