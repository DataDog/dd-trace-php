if(PHP_VERSION_DIRECTORY STREQUAL "php7" OR PHP_VERSION_DIRECTORY STREQUAL "php8")
    set(PHP_PROPERTIES_VERSION_DIRECTORY "php7-8")
else()
    set(PHP_PROPERTIES_VERSION_DIRECTORY "${PHP_VERSION_DIRECTORY}")
endif()

add_library(zai_properties "${PHP_PROPERTIES_VERSION_DIRECTORY}/properties.c")

target_include_directories(zai_properties PUBLIC
                                          $<BUILD_INTERFACE:${PROJECT_SOURCE_DIR}>
                                          $<INSTALL_INTERFACE:include>)

target_compile_features(zai_properties PUBLIC c_std_99)

target_link_libraries(zai_properties PUBLIC "${PHP_LIB}")

set_target_properties(zai_properties PROPERTIES
                                     EXPORT_NAME properties
                                     VERSION ${PROJECT_VERSION})

add_library(Zai::Properties ALIAS zai_properties)

if (${BUILD_ZAI_TESTING})
  add_subdirectory(tests)
endif()

# This copies the include files when `install` is ran
install(FILES ${CMAKE_CURRENT_SOURCE_DIR}/properties.h
        DESTINATION ${CMAKE_INSTALL_INCLUDEDIR}/properties/)

target_link_libraries(zai_zend_abstract_interface INTERFACE zai_properties)

install(TARGETS zai_properties EXPORT ZendAbstractInterfaceTargets)
