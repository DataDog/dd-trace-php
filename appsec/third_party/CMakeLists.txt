option(MPACK_DEBUG "Compile mpack with MPACK_DEBUG=1 and min buffer size" OFF)
file(GLOB_RECURSE MPACK_C_SOURCES ${CMAKE_CURRENT_SOURCE_DIR}/mpack/src/mpack/*.c)
add_library(mpack OBJECT ${MPACK_C_SOURCES})
set_target_properties(mpack PROPERTIES
    POSITION_INDEPENDENT_CODE 1
    C_VISIBILITY_PRESET hidden)

target_include_directories(mpack PUBLIC ${CMAKE_CURRENT_SOURCE_DIR}/mpack/src/mpack)
if(MPACK_DEBUG)
    target_compile_definitions(mpack PUBLIC MPACK_DEBUG=1 MPACK_BUFFER_SIZE=32)
else()
    target_compile_definitions(mpack PUBLIC MPACK_STDIO=0 MPACK_WRITE_TRACKING=1 MPACK_BUFFER_SIZE=131072)
endif()

