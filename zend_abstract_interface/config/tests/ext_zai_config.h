#ifndef EXT_ZAI_CONFIG_H
#define EXT_ZAI_CONFIG_H

#include "config/config.h"
#include "zai_sapi/zai_sapi.h"

#define EXT_CFG_ENTRY(name, type, default) ZAI_CONFIG_ENTRY(EXT_CFG_##name, name, type, default)
#define EXT_CFG_ALIASED_ENTRY(name, type, default, aliases) \
    ZAI_CONFIG_ALIASED_ENTRY(EXT_CFG_##name, name, type, default, aliases)

#if PHP_VERSION_ID < 80000
typedef int (*ext_zai_config_minit_fn)(INIT_FUNC_ARGS);
#else
typedef zend_result (*ext_zai_config_minit_fn)(INIT_FUNC_ARGS);
#endif

void ext_zai_config_ctor(zend_module_entry *module, ext_zai_config_minit_fn orig_minit);

#endif  // EXT_ZAI_CONFIG_H
