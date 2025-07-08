if (POLICY CMP0135)
    cmake_policy(SET CMP0135 NEW)
endif()

include(FetchContent)

set(BOOST_CACHE_PREFIX "${CMAKE_BINARY_DIR}/boost_cache" CACHE PATH "Prefix directory for caching Boost builds")

set(BOOST_VERSION "1.88.0")
set(BOOST_SHA256 "3621533e820dcab1e8012afd583c0c73cf0f77694952b81352bf38c1488f9cb4")
set(BOOST_COMPONENTS
    coroutine
    context
    program_options
    system
    thread
    stacktrace
)

function(calculate_abi_hash OUT_HASH)
    set(ABI_COMPONENTS "")
    
    list(APPEND ABI_COMPONENTS "${CMAKE_CXX_COMPILER_ID}")
    
    list(APPEND ABI_COMPONENTS "${CMAKE_SYSTEM_PROCESSOR}")
    list(APPEND ABI_COMPONENTS "${CMAKE_SIZEOF_VOID_P}")
    
    if (CMAKE_BUILD_TYPE STREQUAL "Debug")
        list(APPEND ABI_COMPONENTS "debug")
    else()
        list(APPEND ABI_COMPONENTS "release")
    endif()
    
    # Relevant compiler flags that might affect the ABI
    string(REGEX MATCHALL "-m[a-zA-Z0-9_-]+" MARCH_FLAGS "${CMAKE_CXX_FLAGS}")
    string(REGEX MATCHALL "-f[a-zA-Z0-9_-]+" FEATURE_FLAGS "${CMAKE_CXX_FLAGS}")
    string(REGEX MATCHALL "-std=[a-zA-Z0-9_+-]+" STD_FLAGS "${CMAKE_CXX_FLAGS}")
    string(REGEX MATCHALL "-D[a-zA-Z0-9_=]+" DEFINE_FLAGS "${CMAKE_CXX_FLAGS}")
    string(REGEX MATCHALL "-stdlib=[a-zA-Z0-9_+-]+" STDLIB_FLAGS "${CMAKE_CXX_FLAGS}")
    
    list(APPEND ABI_COMPONENTS ${MARCH_FLAGS})
    list(APPEND ABI_COMPONENTS ${FEATURE_FLAGS})
    list(APPEND ABI_COMPONENTS ${STD_FLAGS})
    list(APPEND ABI_COMPONENTS ${DEFINE_FLAGS})
    list(APPEND ABI_COMPONENTS ${STDLIB_FLAGS})
    
    # Platform-specific ABI settings
    if(WIN32)
        list(APPEND ABI_COMPONENTS "WIN32")
        if(CMAKE_GENERATOR_PLATFORM)
            list(APPEND ABI_COMPONENTS "${CMAKE_GENERATOR_PLATFORM}")
        endif()
    elseif(APPLE)
        list(APPEND ABI_COMPONENTS "APPLE")
        if(CMAKE_OSX_ARCHITECTURES)
            list(APPEND ABI_COMPONENTS "${CMAKE_OSX_ARCHITECTURES}")
        endif()
        if(CMAKE_OSX_DEPLOYMENT_TARGET)
            list(APPEND ABI_COMPONENTS "${CMAKE_OSX_DEPLOYMENT_TARGET}")
        endif()
    else()
        list(APPEND ABI_COMPONENTS "UNIX")
    endif()
    
    list(APPEND ABI_COMPONENTS "${BOOST_VERSION}")
    
    string(JOIN ";" ABI_STRING ${ABI_COMPONENTS})
    string(SHA256 HASH_VALUE "${ABI_STRING}")
    string(SUBSTRING "${HASH_VALUE}" 0 12 SHORT_HASH)
    
    set(${OUT_HASH} "${SHORT_HASH}" PARENT_SCOPE)
endfunction()

calculate_abi_hash(ABI_HASH)

set(BOOST_BUILD_DIR "${BOOST_CACHE_PREFIX}/${ABI_HASH}")

set(BOOST_ALREADY_BUILT FALSE)
if(EXISTS "${BOOST_BUILD_DIR}/lib" AND EXISTS "${BOOST_BUILD_DIR}/include")
    # Check if all required libraries exist
    set(ALL_LIBS_EXIST TRUE)
    foreach(component IN LISTS BOOST_COMPONENTS)
        set(LIB_PATTERN "${BOOST_BUILD_DIR}/lib/libboost_${component}*.a")
        file(GLOB LIB_FILES ${LIB_PATTERN})
        if(NOT LIB_FILES)
            message(STATUS "Missing library: ${LIB_PATTERN}")
            set(ALL_LIBS_EXIST FALSE)
            break()
        endif()
    endforeach()
    
    if(ALL_LIBS_EXIST)
        set(BOOST_ALREADY_BUILT TRUE)
        message(STATUS "Found cached Boost build, skipping compilation")
    else()
        message(STATUS "Cached Boost build incomplete, will rebuild")
    endif()
else()
    message(STATUS "No cached Boost build found, will build from source")
endif()

if (NOT BOOST_ALREADY_BUILT)
    # Download and extract Boost
    string(REPLACE "." "_" BOOST_VERSION_UNDERSCORE ${BOOST_VERSION})
    FetchContent_Declare(
        boost
        URL https://archives.boost.io/release/${BOOST_VERSION}/source/boost_${BOOST_VERSION_UNDERSCORE}.tar.gz
        URL_HASH SHA256=${BOOST_SHA256}
    )

    FetchContent_MakeAvailable(boost)

    FetchContent_GetProperties(boost)
    if(NOT boost_POPULATED)
        FetchContent_Populate(boost)
    endif()

    file(MAKE_DIRECTORY ${BOOST_BUILD_DIR})

    # Determine the toolset for b2
    if(CMAKE_CXX_COMPILER_ID MATCHES "GNU")
        set(BOOST_TOOLSET "gcc")
    elseif(CMAKE_CXX_COMPILER_ID MATCHES "Clang")
        set(BOOST_TOOLSET "clang")
    elseif(CMAKE_CXX_COMPILER_ID MATCHES "MSVC")
        set(BOOST_TOOLSET "msvc")
    else()
        set(BOOST_TOOLSET "gcc")  # Default fallback
    endif()

    # Prepare compiler flags
    set(BOOST_CXXFLAGS "")
    set(BOOST_LINKFLAGS "")
    if(CMAKE_CXX_FLAGS)
        set(BOOST_CXXFLAGS "${CMAKE_CXX_FLAGS}")
    endif()

    if(CMAKE_SIZEOF_VOID_P EQUAL 8)
        set(BOOST_ADDRESS_MODEL "64")
    else()
        set(BOOST_ADDRESS_MODEL "32")
    endif()

    if(CMAKE_BUILD_TYPE STREQUAL "Debug")
        set(BOOST_VARIANT "debug")
    else()
        set(BOOST_VARIANT "release")
    endif()

    set(USER_CONFIG_JAM "${BOOST_BUILD_DIR}/user-config.jam")
    file(WRITE ${USER_CONFIG_JAM}
        "using ${BOOST_TOOLSET} : : ${CMAKE_CXX_COMPILER}"
    )
    if(BOOST_CXXFLAGS)
        file(APPEND ${USER_CONFIG_JAM} " : <cxxflags>\"${BOOST_CXXFLAGS}\"")
    endif()
    file(APPEND ${USER_CONFIG_JAM} " ;\n")

    set(BOOST_LIBRARIES_TO_BUILD "")
    foreach(component IN LISTS BOOST_COMPONENTS)
        list(APPEND BOOST_LIBRARIES_TO_BUILD "--with-${component}")
    endforeach()

    # Custom target to build boost
    add_custom_command(
        COMMAND ${CMAKE_COMMAND} -E chdir ${boost_SOURCE_DIR}
                ./bootstrap.sh
                --prefix=${BOOST_BUILD_DIR}
                --with-toolset=${BOOST_TOOLSET}
        COMMAND ${CMAKE_COMMAND} -E chdir ${boost_SOURCE_DIR}
                ./b2
                --user-config=${USER_CONFIG_JAM}
                --prefix=${BOOST_BUILD_DIR}
                --build-dir=${CMAKE_BINARY_DIR}/boost_build
                toolset=${BOOST_TOOLSET}
                address-model=${BOOST_ADDRESS_MODEL}
                variant=${BOOST_VARIANT}
                link=static
                threading=multi
                runtime-link=static
                ${BOOST_LIBRARIES_TO_BUILD}
                install
        COMMAND ${CMAKE_COMMAND} -E touch ${BOOST_BUILD_DIR}/boost.stamp
        OUTPUT ${BOOST_BUILD_DIR}/boost.stamp
        WORKING_DIRECTORY ${boost_SOURCE_DIR}
        COMMENT "Building Boost libraries with ABI hash ${ABI_HASH}"
    )
    add_custom_target(boost_build
        DEPENDS ${BOOST_BUILD_DIR}/boost.stamp
    )
else()
    # Create dummy target if already built
    add_custom_target(boost_build
        COMMAND ${CMAKE_COMMAND} -E echo "Using cached Boost build"
        COMMENT "Using cached Boost libraries"
    )
endif()

function(create_boost_target component_name)
    set(LIB_PREFIX "lib")
    set(LIB_SUFFIX ".a")
    
    # Construct the expected library path
    set(EXPECTED_LIB_PATH "${BOOST_BUILD_DIR}/lib/${LIB_PREFIX}boost_${component_name}${LIB_SUFFIX}")
    
    # Don't use an imported library, as it's not available at configuration time
    add_library(boost_${component_name} INTERFACE)
    message(STATUS "boost_${component_name} will be imported from ${EXPECTED_LIB_PATH}")
    target_link_libraries(boost_${component_name} INTERFACE
        $<BUILD_INTERFACE:${EXPECTED_LIB_PATH}>
    )
    target_include_directories(boost_${component_name} INTERFACE
        $<BUILD_INTERFACE:${BOOST_BUILD_DIR}/include>
    )
    
    add_dependencies(boost_${component_name} boost_build)
    
    # Set up component-specific dependencies
    if(component_name STREQUAL "coroutine")
        target_link_libraries(boost_${component_name} INTERFACE boost_context boost_system)
    elseif(component_name MATCHES "stacktrace")
        if(UNIX AND NOT APPLE)
            target_link_libraries(boost_${component_name} INTERFACE dl)
        endif()
        if(component_name STREQUAL "stacktrace_backtrace")
            target_link_libraries(boost_${component_name} INTERFACE backtrace)
        endif()
    elseif(component_name STREQUAL "thread")
        find_package(Threads REQUIRED)
        target_link_libraries(boost_${component_name} INTERFACE Threads::Threads)
        if(UNIX AND NOT APPLE)
            target_link_libraries(boost_${component_name} INTERFACE rt)
        endif()
    endif()
endfunction()

foreach(component IN LISTS BOOST_COMPONENTS)
    if (component STREQUAL "stacktrace")
        add_library(boost_stacktrace INTERFACE)

        create_boost_target("stacktrace_addr2line")
        create_boost_target("stacktrace_basic")

        if (UNIX AND NOT APPLE AND BOOST_TOOLSET STREQUAL "gcc")
            create_boost_target("stacktrace_backtrace")
            target_link_libraries(boost_stacktrace INTERFACE boost_stacktrace_backtrace)
        else()
            target_link_libraries(boost_stacktrace INTERFACE boost_stacktrace_basic)
        endif()
    else()
        create_boost_target(${component})
    endif()
endforeach()

message(STATUS "Boost components will be available as:")
foreach(component IN LISTS BOOST_COMPONENTS)
    message(STATUS "  - boost_${component}")
endforeach()
message(STATUS "Cache configuration:")
message(STATUS "  BOOST_CACHE_PREFIX: ${BOOST_CACHE_PREFIX}")
message(STATUS "  ABI Hash: ${ABI_HASH}")
message(STATUS "  Cache Hit: ${BOOST_ALREADY_BUILT}")
message(STATUS "")
