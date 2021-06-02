add_library(zai_env env.c)

target_include_directories(
  zai_env PUBLIC $<BUILD_INTERFACE:${PROJECT_SOURCE_DIR}>
                 $<INSTALL_INTERFACE:include>)

target_compile_features(zai_env PUBLIC c_std_99)

target_link_libraries(zai_env PUBLIC "${PHP_LIB}" Zai::Sandbox)

set_target_properties(zai_env PROPERTIES EXPORT_NAME Env VERSION
                                                         ${PROJECT_VERSION})

add_library(Zai::Env ALIAS zai_env)

if(${BUILD_ZAI_TESTING})
  add_subdirectory(tests)
endif()

install(FILES ${CMAKE_CURRENT_SOURCE_DIR}/env.h
        DESTINATION ${CMAKE_INSTALL_INCLUDEDIR}/env/)

target_link_libraries(zai_zend_abstract_interface INTERFACE zai_env)

install(TARGETS zai_env EXPORT ZendAbstractInterfaceTargets)
