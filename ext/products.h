#ifndef DDTRACE_PRODUCTS_H
#define DDTRACE_PRODUCTS_H

#include <stdbool.h>

enum PRODUCTS {
    PRODUCT_APM = 0,
    PRODUCT_ASM
}

void ddtrace_products_init();
void ddtrace_products_set_asm_active();
bool ddtrace_products_is_active(PRODUCTS product);

#endif  // DDTRACE_PRODUCTS_H
