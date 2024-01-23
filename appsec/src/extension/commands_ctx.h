#pragma once

#include "attributes.h"
#include <php.h>

struct req_info {
    zend_object *nullable root_span;
    zend_string *nullable client_ip;
};
