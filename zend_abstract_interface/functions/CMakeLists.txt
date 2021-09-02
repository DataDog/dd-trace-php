if(PHP_VERSION_DIRECTORY STREQUAL "php7" OR PHP_VERSION_DIRECTORY STREQUAL "php8")
  set(PHP_FUNCTIONS_VERSION_DIRECTORY "php7-8")
else()
  set(PHP_FUNCTIONS_VERSION_DIRECTORY "${PHP_VERSION_DIRECTORY}")
endif()

add_library(zai_functions "${PHP_FUNCTIONS_VERSION_DIRECTORY}/functions.c")

target_include_directories(zai_functions PUBLIC
                                         $<BUILD_INTERFACE:${PROJECT_SOURCE_DIR}>
                                         $<INSTALL_INTERFACE:include>)

target_compile_features(zai_functions PUBLIC c_std_99)

target_link_libraries(zai_functions PUBLIC "${PHP_LIB}" Zai::Sandbox)

set_target_properties(zai_functions PROPERTIES
                                    EXPORT_NAME Functions
                                    VERSION ${PROJECT_VERSION})

add_library(Zai::Functions ALIAS zai_functions)

if (${BUILD_ZAI_TESTING})
  add_subdirectory(tests)
endif()

# This copies the include files when `install` is ran
# TODO: How to make this zai/functions.h?
install(FILES ${CMAKE_CURRENT_SOURCE_DIR}/functions.h
        DESTINATION ${CMAKE_INSTALL_INCLUDEDIR}/functions/)

target_link_libraries(zai_zend_abstract_interface INTERFACE zai_functions)

install(TARGETS zai_functions EXPORT ZendAbstractInterfaceTargets)
