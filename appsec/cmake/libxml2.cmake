# Build libxml2 as a static library from vendored sources.
# This allows us to control the allocator and avoid conflicts with PHP's libxml2.

set(LIBXML2_SOURCE_DIR "${CMAKE_CURRENT_SOURCE_DIR}/third_party/libxml2/src")
set(LIBXML2_BINARY_DIR "${CMAKE_BINARY_DIR}/libxml2")
set(LIBXML2_INSTALL_DIR "${LIBXML2_BINARY_DIR}/install")

# Detect if PHP is built with ZTS (thread safety)
include(CheckSymbolExists)
set(LIBXML2_WITH_THREADS OFF)
if(PhpConfig_FOUND)
    set(_SAVED_CMAKE_REQUIRED_INCLUDES ${CMAKE_REQUIRED_INCLUDES})
    set(CMAKE_REQUIRED_INCLUDES ${PhpConfig_INCLUDE_DIRS})
    check_symbol_exists(ZTS "main/php_config.h" PHP_IS_ZTS)
    set(CMAKE_REQUIRED_INCLUDES ${_SAVED_CMAKE_REQUIRED_INCLUDES})
    if(PHP_IS_ZTS)
        set(LIBXML2_WITH_THREADS ON)
        message(STATUS "PHP ZTS detected, enabling libxml2 thread support")
    endif()
endif()

# Configure options for a minimal static build
# We only need the parser, no network, no output, no schemas, etc.
set(LIBXML2_CONFIGURE_OPTS
    -DBUILD_SHARED_LIBS=OFF
    -DLIBXML2_WITH_ICONV=OFF
    -DLIBXML2_WITH_ICU=OFF
    -DLIBXML2_WITH_LZMA=OFF
    -DLIBXML2_WITH_PYTHON=OFF
    -DLIBXML2_WITH_ZLIB=OFF
    -DLIBXML2_WITH_THREADS=${LIBXML2_WITH_THREADS}
    -DLIBXML2_WITH_THREAD_ALLOC=OFF
    -DLIBXML2_WITH_FTP=OFF
    -DLIBXML2_WITH_HTTP=OFF
    -DLIBXML2_WITH_C14N=OFF
    -DLIBXML2_WITH_CATALOG=OFF
    -DLIBXML2_WITH_DEBUG=OFF
    -DLIBXML2_WITH_HTML=OFF
    -DLIBXML2_WITH_LEGACY=OFF
    -DLIBXML2_WITH_MODULES=OFF
    -DLIBXML2_WITH_OUTPUT=OFF
    -DLIBXML2_WITH_PATTERN=OFF
    -DLIBXML2_WITH_PROGRAMS=OFF
    -DLIBXML2_WITH_PUSH=ON
    -DLIBXML2_WITH_READER=OFF
    -DLIBXML2_WITH_REGEXPS=OFF
    -DLIBXML2_WITH_SAX1=ON
    -DLIBXML2_WITH_SCHEMAS=OFF
    -DLIBXML2_WITH_SCHEMATRON=OFF
    -DLIBXML2_WITH_TESTS=OFF
    -DLIBXML2_WITH_TREE=ON
    -DLIBXML2_WITH_VALID=OFF
    -DLIBXML2_WITH_WRITER=OFF
    -DLIBXML2_WITH_XINCLUDE=OFF
    -DLIBXML2_WITH_XPATH=OFF
    -DLIBXML2_WITH_XPTR=OFF
    -DCMAKE_POSITION_INDEPENDENT_CODE=ON
    -DCMAKE_INSTALL_PREFIX=${LIBXML2_INSTALL_DIR}
    -DCMAKE_BUILD_TYPE=${CMAKE_BUILD_TYPE}
    -DCMAKE_C_COMPILER=${CMAKE_C_COMPILER}
    -DCMAKE_INSTALL_LIBDIR=lib
)

if(CMAKE_OSX_DEPLOYMENT_TARGET)
    list(APPEND LIBXML2_CONFIGURE_OPTS -DCMAKE_OSX_DEPLOYMENT_TARGET=${CMAKE_OSX_DEPLOYMENT_TARGET})
endif()

# Use ExternalProject to build from local vendored sources
include(ExternalProject)

ExternalProject_Add(libxml2_build
    SOURCE_DIR ${LIBXML2_SOURCE_DIR}
    BINARY_DIR ${LIBXML2_BINARY_DIR}
    CMAKE_ARGS ${LIBXML2_CONFIGURE_OPTS}
    BUILD_BYPRODUCTS
        ${LIBXML2_INSTALL_DIR}/lib/libxml2.a
    INSTALL_DIR ${LIBXML2_INSTALL_DIR}
    LOG_CONFIGURE ON
    LOG_BUILD ON
    LOG_INSTALL ON
)

# Create the include directory at configure time to satisfy CMake's validation
# The actual headers will be installed by ExternalProject during build
set(LIBXML2_INCLUDE_DIR "${LIBXML2_INSTALL_DIR}/include/libxml2")
file(MAKE_DIRECTORY "${LIBXML2_INCLUDE_DIR}")

# Create imported target for linking
add_library(libxml2_static STATIC IMPORTED GLOBAL)
add_dependencies(libxml2_static libxml2_build)

# Set library location
set(LIBXML2_LIB_DIR "${LIBXML2_INSTALL_DIR}/lib")

set_target_properties(libxml2_static PROPERTIES
    IMPORTED_LOCATION "${LIBXML2_LIB_DIR}/libxml2.a"
    INTERFACE_INCLUDE_DIRECTORIES "${LIBXML2_INCLUDE_DIR}"
)
