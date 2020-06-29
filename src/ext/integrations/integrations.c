#include "integrations.h"

#include "elasticsearch.h"
#include "test_integration.h"

void dd_initialize_defered_integrations() {
    _dd_es_initialize_defered_integration();
    _dd_test_initialize_defered_integration();
}
