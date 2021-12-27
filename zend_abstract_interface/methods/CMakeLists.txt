if(PHP_VERSION_DIRECTORY STREQUAL "php7" OR PHP_VERSION_DIRECTORY STREQUAL "php8")
  set(PHP_METHODS_VERSION_DIRECTORY "php7-8")
else()
  set(PHP_METHODS_VERSION_DIRECTORY "${PHP_VERSION_DIRECTORY}")
endif()

add_library(zai_methods "${PHP_METHODS_VERSION_DIRECTORY}/methods.c")

target_include_directories(zai_methods PUBLIC
                                       $<BUILD_INTERFACE:${PROJECT_SOURCE_DIR}>
                                       $<INSTALL_INTERFACE:include>)

target_compile_features(zai_methods PUBLIC c_std_99)

target_link_libraries(zai_methods PUBLIC "${PHP_LIB}" Zai::Sandbox)

set_target_properties(zai_methods PROPERTIES
                                  EXPORT_NAME Methods
                                  VERSION ${PROJECT_VERSION})

add_library(Zai::Methods ALIAS zai_methods)

if (${BUILD_ZAI_TESTING})
  add_subdirectory(tests)
endif()

# This copies the include files when `install` is ran
# TODO: How to make this zai/methods.h?
install(FILES ${CMAKE_CURRENT_SOURCE_DIR}/methods.h
        DESTINATION ${CMAKE_INSTALL_INCLUDEDIR}/methods/)

target_link_libraries(zai_zend_abstract_interface INTERFACE zai_methods)

install(TARGETS zai_methods EXPORT ZendAbstractInterfaceTargets)
