#include "integrations.h"

#include "elasticsearch.h"
#include "test_integration.h"

void dd_integrations_initialize(TSRMLS_D) {
    _dd_es_initialize_defered_integration(TSRMLS_C);
    _dd_load_test_integrations();
}
