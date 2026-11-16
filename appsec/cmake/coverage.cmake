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

# Only instruments the given sources of a target, e.g. AppSec's in ddtrace.so.
# The target linking them still needs maybe_enable_coverage for the runtime.
macro(maybe_enable_coverage_for_sources)
    if(DD_APPSEC_ENABLE_COVERAGE)
        if(CMAKE_CXX_COMPILER_ID MATCHES "Clang")
            set_property(SOURCE ${ARGN} APPEND PROPERTY COMPILE_OPTIONS
                -fprofile-instr-generate -fcoverage-mapping -mllvm -runtime-counter-relocation=true)
        else()
            set_property(SOURCE ${ARGN} APPEND PROPERTY COMPILE_OPTIONS --coverage)
        endif()
    endif()
endmacro()
