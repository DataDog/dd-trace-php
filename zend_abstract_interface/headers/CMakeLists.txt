if(PHP_VERSION_DIRECTORY STREQUAL "php7" OR PHP_VERSION_DIRECTORY STREQUAL "php8")
    set(PHP_HEADERS_VERSION_DIRECTORY "php7-8")
else()
    set(PHP_FUNCTIONS_VERSION_DIRECTORY "${PHP_VERSION_DIRECTORY}")
endif()

add_library(zai_headers "${PHP_HEADERS_VERSION_DIRECTORY}/headers.c")

target_include_directories(zai_headers PUBLIC
                                       $<BUILD_INTERFACE:${PROJECT_SOURCE_DIR}>
                                       $<INSTALL_INTERFACE:include>)

target_compile_features(zai_headers PUBLIC c_std_99)

target_link_libraries(zai_headers PUBLIC "${PHP_LIB}")

set_target_properties(zai_headers PROPERTIES
                                  EXPORT_NAME headers
                                  VERSION ${PROJECT_VERSION})

add_library(Zai::Headers ALIAS zai_headers)

if (${BUILD_ZAI_TESTING})
  add_subdirectory(tests)
endif()

# This copies the include files when `install` is ran
install(FILES ${CMAKE_CURRENT_SOURCE_DIR}/headers.h
        DESTINATION ${CMAKE_INSTALL_INCLUDEDIR}/headers/)

target_link_libraries(zai_zend_abstract_interface INTERFACE zai_headers)

install(TARGETS zai_headers EXPORT ZendAbstractInterfaceTargets)
