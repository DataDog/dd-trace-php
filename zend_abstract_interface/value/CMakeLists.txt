add_library(zai_value INTERFACE)

target_include_directories(zai_value INTERFACE
                                      $<BUILD_INTERFACE:${PROJECT_SOURCE_DIR}>
                                      $<INSTALL_INTERFACE:include>)

target_compile_features(zai_value INTERFACE c_std_99)

target_link_libraries(zai_value INTERFACE "${PHP_LIB}")

add_library(Zai::Value ALIAS zai_value)

if (${BUILD_ZAI_TESTING})
  add_subdirectory(tests)
endif()

# This copies the include files when `install` is ran
install(FILES ${CMAKE_CURRENT_SOURCE_DIR}/value.h
        DESTINATION ${CMAKE_INSTALL_INCLUDEDIR}/value/)

target_link_libraries(zai_zend_abstract_interface INTERFACE zai_value)

install(TARGETS zai_value EXPORT ZendAbstractInterfaceTargets)
