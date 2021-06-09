add_library(zai_config config.c config_decode.c config_ini.c)

target_include_directories(
  zai_config PUBLIC $<BUILD_INTERFACE:${PROJECT_SOURCE_DIR}>
                    $<INSTALL_INTERFACE:include>)

target_compile_features(zai_config PUBLIC c_std_99)

target_link_libraries(zai_config PUBLIC "${PHP_LIB}" Zai::Env)

set_target_properties(zai_config PROPERTIES EXPORT_NAME Config
                                            VERSION ${PROJECT_VERSION})

add_library(Zai::Config ALIAS zai_config)

if(${BUILD_ZAI_TESTING})
  add_subdirectory(tests)
endif()

install(
  FILES ${CMAKE_CURRENT_SOURCE_DIR}/config.h
        ${CMAKE_CURRENT_SOURCE_DIR}/config_decode.h
        ${CMAKE_CURRENT_SOURCE_DIR}/config_ini.h
  DESTINATION ${CMAKE_INSTALL_INCLUDEDIR}/config/)

target_link_libraries(zai_zend_abstract_interface INTERFACE zai_config)

install(TARGETS zai_config EXPORT ZendAbstractInterfaceTargets)
