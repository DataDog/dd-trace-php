#include "integrations.h"

#include "elasticsearch.h"
#include "test_integration.h"

void dd_initialize_defered_integrations(TSRMLS_D) {
    _dd_es_initialize_defered_integration(TSRMLS_C);
    _dd_test_initialize_defered_integration();
}
