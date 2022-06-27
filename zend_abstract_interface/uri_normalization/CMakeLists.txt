add_library(zai_uri_normalization uri_normalization.c)

target_include_directories(zai_uri_normalization PUBLIC
                                          $<BUILD_INTERFACE:${PROJECT_SOURCE_DIR}>
                                          $<INSTALL_INTERFACE:include>)

target_compile_features(zai_uri_normalization PUBLIC c_std_99)

target_link_libraries(zai_uri_normalization PUBLIC "${PHP_LIB}")

set_target_properties(zai_uri_normalization PROPERTIES
                                     EXPORT_NAME uri_normalization
                                     VERSION ${PROJECT_VERSION})

add_library(Zai::UriNormalization ALIAS zai_uri_normalization)

if (${BUILD_ZAI_TESTING})
  add_subdirectory(tests)
endif()

# This copies the include files when `install` is ran
install(FILES ${CMAKE_CURRENT_SOURCE_DIR}/uri_normalization.h
        DESTINATION ${CMAKE_INSTALL_INCLUDEDIR}/uri_normalization/)

target_link_libraries(zai_zend_abstract_interface INTERFACE zai_uri_normalization)

install(TARGETS zai_uri_normalization EXPORT ZendAbstractInterfaceTargets)
