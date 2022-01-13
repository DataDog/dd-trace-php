#ifndef HAVE_ZAI_SAPI_TEST
#define HAVE_ZAI_SAPI_TEST
/**
* ZAI SAPI Test Harness in Pre Processor:
*
*  ZAI SAPI is tested with catch2 and this header provides a convenience
*  and consistency API over catch2
*
*  Test cases will all have meaningful naming (screen vs code), and sensible
*  form in test files.
*
*  For convenience:
*    ZAI_SAPI_TEST_CASE[_WITH_TAGS]_BARE(char *suite, char *description, [char *tags, ] block_t code)
*
*    Will produce a case with tagging consistent with other tests but not decorate the code
*
*  The decorator API provided is essentially:
*    ZAI_SAPI_TEST_[BAILING_]CASE[_WITH_TAGS][_WITH_STUB][_WITH_PROLOGUE]
*       (char *suite, char *description, [char *tags,] [char *stub,] [block_t prologue,] block_t code)
*
*    Delete [] as appropriate
*
* This completes the master class in naming things, you are ready ...
*
* Notes:
*  Tests not declared to bail that bail will fail
*
*  Tests declared to bail that do not bail will fail
*
*  Stubs (as a pre-requisite of test code) are required to execute without error
*    Errors in stubs will cause the test to fail
*/
extern "C" {
#include "../zai_sapi.h"
}

#include <catch2/catch.hpp>
// clang-format off
#define ZAI_SAPI_TEST_TAGS_NONE     ""
#define ZAI_SAPI_TEST_STUB_NONE     NULL
#define ZAI_SAPI_TEST_PROLOGUE_NONE {}

/* {{{ private void ZAI_SAPI_TEST_CASE_TAG(char *suite, char *description, char *tags) */
#define ZAI_SAPI_TEST_CASE_TAG(\
        __ZAI_SAPI_TEST_CASE_SUITE,                                       \
        __ZAI_SAPI_TEST_CASE_DESCRIPTION,                                 \
        __ZAI_SAPI_TEST_CASE_TAGS)                                        \
    __ZAI_SAPI_TEST_CASE_SUITE " [" __ZAI_SAPI_TEST_CASE_DESCRIPTION "]", \
    "[" __ZAI_SAPI_TEST_CASE_SUITE "]" __ZAI_SAPI_TEST_CASE_TAGS /* }}} */

/* {{{ public void ZAI_SAPI_TEST_CASE_BARE(char *suite, char *description, block_t code) */
#define ZAI_SAPI_TEST_CASE_BARE(suite, description, ...)                     \
    TEST_CASE(ZAI_SAPI_TEST_CASE_TAG(                                        \
        suite, description, ZAI_SAPI_TEST_TAGS_NONE)) {                      \
        { __VA_ARGS__ }                                                      \
    } /* }}} */

/* {{{ public void ZAI_SAPI_TEST_CASE_WITH_TAGS_BARE(char *suite, char *description, char *tags, block_t code) */
#define ZAI_SAPI_TEST_CASE_WITH_TAGS_BARE(suite, description, tags, ...)     \
    TEST_CASE(ZAI_SAPI_TEST_CASE_TAG(suite, description, tags)) {            \
        { __VA_ARGS__ }                                                      \
    } /* }}} */

/* {{{ private bailout handling */
#define __ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_BEGIN()  \
    bool zai_sapi_test_case_without_bailout = true;   \
    zend_first_try {

#define __ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_END()    \
    } zend_catch {                                    \
        zai_sapi_test_case_without_bailout = false;   \
    } zend_end_try();                                 \
    REQUIRE(zai_sapi_test_case_without_bailout);

#define __ZAI_SAPI_TEST_CASE_WITH_BAILOUT_BEGIN()     \
    bool zai_sapi_test_case_with_bailout = false;     \
    zend_first_try {

#define __ZAI_SAPI_TEST_CASE_WITH_BAILOUT_END()       \
    } zend_catch {                                    \
        zai_sapi_test_case_with_bailout = true;       \
    } zend_end_try();                                 \
    REQUIRE(zai_sapi_test_case_with_bailout);
/* }}} */

/* {{{ private void ZAI_SAPI_TEST_CASE_IMPL(
                        char *suite,
                        char *description,
                        char *tags,
                        char *stub,
                        block_t prologue,
                        callable_t begin,
                        callable_t end,
                        block_t code) */
#define ZAI_SAPI_TEST_CASE_IMPL(                                         \
    __ZAI_SAPI_TEST_CASE_SUITE,                                          \
    __ZAI_SAPI_TEST_CASE_DESCRIPTION,                                    \
    __ZAI_SAPI_TEST_CASE_TAGS,                                           \
    __ZAI_SAPI_TEST_CASE_STUB,                                           \
    __ZAI_SAPI_TEST_CASE_PROLOGUE,                                       \
    __ZAI_SAPI_TEST_CASE_BEGIN,                                          \
    __ZAI_SAPI_TEST_CASE_END,                                            \
    ...)                                                                 \
    TEST_CASE(ZAI_SAPI_TEST_CASE_TAG(                                    \
        __ZAI_SAPI_TEST_CASE_SUITE,                                      \
        __ZAI_SAPI_TEST_CASE_DESCRIPTION,                                \
        __ZAI_SAPI_TEST_CASE_TAGS)) {                                    \
        REQUIRE(zai_sapi_sinit());                                       \
        __ZAI_SAPI_TEST_CASE_PROLOGUE                                    \
        REQUIRE(zai_sapi_minit());                                       \
        REQUIRE(zai_sapi_rinit());                                       \
        ZAI_SAPI_TSRMLS_FETCH();                                         \
        __ZAI_SAPI_TEST_CASE_BEGIN()                                     \
        if (__ZAI_SAPI_TEST_CASE_STUB) {                                 \
            bool zai_sapi_test_case_stub_included = true;                \
            zend_try {                                                   \
                zai_sapi_test_case_stub_included =                       \
                    zai_sapi_execute_script(                             \
                        __ZAI_SAPI_TEST_CASE_STUB);                      \
            } zend_catch {                                               \
                zai_sapi_test_case_stub_included = false;                \
            } zend_end_try();                                            \
            REQUIRE(zai_sapi_test_case_stub_included);                   \
        }                                                                \
        { __VA_ARGS__ }                                                  \
        __ZAI_SAPI_TEST_CASE_END()                                       \
        zai_sapi_spindown();                                             \
    } /* }}} */

/* {{{ public void ZAI_SAPI_TEST_CASE(char *suite, char *description, block_t code) */
#define ZAI_SAPI_TEST_CASE(suite, description, ...)                      \
            ZAI_SAPI_TEST_CASE_IMPL(suite, description,                  \
                ZAI_SAPI_TEST_TAGS_NONE,                                 \
                ZAI_SAPI_TEST_STUB_NONE,                                 \
                ZAI_SAPI_TEST_PROLOGUE_NONE,                             \
            __ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_BEGIN,                  \
            __ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_END,                    \
            __VA_ARGS__) /* }}} */

/* {{{ public void ZAI_SAPI_TEST_CASE_WITH_TAGS(char *suite, char *description, char *tags, block_t code) */
#define ZAI_SAPI_TEST_CASE_WITH_TAGS(suite, description, tags, ...)      \
            ZAI_SAPI_TEST_CASE_IMPL(suite, description,                  \
                tags,                                                    \
                ZAI_SAPI_TEST_STUB_NONE,                                 \
                ZAI_SAPI_TEST_PROLOGUE_NONE,                             \
            __ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_BEGIN,                  \
            __ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_END,                    \
            __VA_ARGS__) /* }}} */

/* {{{ public void ZAI_SAPI_TEST_CASE_WITH_STUB(char *suite, char *description, char *stub, block_t code) */
#define ZAI_SAPI_TEST_CASE_WITH_STUB(suite, description, stub, ...)      \
            ZAI_SAPI_TEST_CASE_IMPL(suite, description,                  \
                ZAI_SAPI_TEST_TAGS_NONE,                                 \
                stub,                                                    \
                ZAI_SAPI_TEST_PROLOGUE_NONE,                             \
            __ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_BEGIN,                  \
            __ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_END,                    \
            __VA_ARGS__) /* }}} */

/* {{{ public void ZAI_SAPI_TEST_CASE_WITH_TAGS_WITH_STUB(char *suite, char *description, char *tags, char *stub, block_t code) */
#define ZAI_SAPI_TEST_CASE_WITH_TAGS_WITH_STUB(suite, description, tags, stub, ...) \
            ZAI_SAPI_TEST_CASE_IMPL(suite, description,                  \
                tags,                                                    \
                stub,                                                    \
                ZAI_SAPI_TEST_PROLOGUE_NONE,                             \
            __ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_BEGIN,                  \
            __ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_END,                    \
            __VA_ARGS__) /* }}} */

/* {{{ public void ZAI_SAPI_TEST_BAILING_CASE(char *suite, char *description, block_t code) */
#define ZAI_SAPI_TEST_BAILING_CASE(suite, description, ...)              \
            ZAI_SAPI_TEST_CASE_IMPL(suite, description,                  \
                ZAI_SAPI_TEST_TAGS_NONE,                                 \
                ZAI_SAPI_TEST_STUB_NONE,                                 \
                ZAI_SAPI_TEST_PROLOGUE_NONE,                             \
            __ZAI_SAPI_TEST_CASE_WITH_BAILOUT_BEGIN,                     \
            __ZAI_SAPI_TEST_CASE_WITH_BAILOUT_END,                       \
            __VA_ARGS__) /* }}} */

/* {{{ public void ZAI_SAPI_TEST_BAILING_CASE_WITH_TAGS(char *suite, char *description, char *tags, block_t code) */
#define ZAI_SAPI_TEST_BAILING_CASE_WITH_TAGS(suite, description, tags, ...) \
            ZAI_SAPI_TEST_CASE_IMPL(suite, description,                  \
                tags,                                                    \
                ZAI_SAPI_TEST_STUB_NONE,                                 \
                ZAI_SAPI_TEST_PROLOGUE_NONE,                             \
            __ZAI_SAPI_TEST_CASE_WITH_BAILOUT_BEGIN,                     \
            __ZAI_SAPI_TEST_CASE_WITH_BAILOUT_END,                       \
            __VA_ARGS__) /* }}} */

/* {{{ public void ZAI_SAPI_TEST_BAILING_CASE_WITH_STUB(char *suite, char *description, char *stub, block_t code) */
#define ZAI_SAPI_TEST_BAILING_CASE_WITH_STUB(suite, description, stub, ...) \
            ZAI_SAPI_TEST_CASE_IMPL(suite, description,                  \
                ZAI_SAPI_TEST_TAGS_NONE,                                 \
                stub,                                                    \
                ZAI_SAPI_TEST_PROLOGUE_NONE,                             \
            __ZAI_SAPI_TEST_CASE_WITH_BAILOUT_BEGIN,                     \
            __ZAI_SAPI_TEST_CASE_WITH_BAILOUT_END,                       \
            __VA_ARGS__) /* }}} */

/* {{{ public void ZAI_SAPI_TEST_BAILING_CASE_WITH_TAGS_WITH_STUB(char *suite, char *description, char *tags, char *stub, block_t code) */
#define ZAI_SAPI_TEST_BAILING_CASE_WITH_TAGS_WITH_STUB(suite, description, tags, stub, ...) \
            ZAI_SAPI_TEST_CASE_IMPL(suite, description,                  \
                tags,                                                    \
                stub,                                                    \
                ZAI_SAPI_TEST_PROLOGUE_NONE,                             \
            __ZAI_SAPI_TEST_CASE_WITH_BAILOUT_BEGIN,                     \
            __ZAI_SAPI_TEST_CASE_WITH_BAILOUT_END,                       \
            __VA_ARGS__) /* }}} */

/* {{{ public void ZAI_SAPI_TEST_CASE_WITH_PROLOGUE(char *suite, char *description, block_t prologue, block_t code) */
#define ZAI_SAPI_TEST_CASE_WITH_PROLOGUE(suite, description, prologue, ...) \
            ZAI_SAPI_TEST_CASE_IMPL(suite, description,                  \
                ZAI_SAPI_TEST_TAGS_NONE,                                 \
                ZAI_SAPI_TEST_STUB_NONE,                                 \
                prologue,                                                \
            __ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_BEGIN,                  \
            __ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_END,                    \
            __VA_ARGS__) /* }}} */

/* {{{ public void ZAI_SAPI_TEST_CASE_WITH_TAGS_WITH_PROLOGUE(char *suite, char *description, char *tags, block_t prologue, block_t code) */
#define ZAI_SAPI_TEST_CASE_WITH_TAGS_WITH_PROLOGUE(suite, description, tags, prologue, ...) \
            ZAI_SAPI_TEST_CASE_IMPL(suite, description,                  \
                tags,                                                    \
                ZAI_SAPI_TEST_STUB_NONE,                                 \
                prologue,                                                \
            __ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_BEGIN,                  \
            __ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_END,                    \
            __VA_ARGS__) /* }}} */

/* {{{ public void ZAI_SAPI_TEST_CASE_WITH_STUB_WITH_PROLOGUE(char *suite, char *description, char *stub, block_t prologue, block_t code) */
#define ZAI_SAPI_TEST_CASE_WITH_STUB_WITH_PROLOGUE(suite, description, stub, prologue, ...) \
            ZAI_SAPI_TEST_CASE_IMPL(suite, description,                  \
                ZAI_SAPI_TEST_TAGS_NONE,                                 \
                stub,                                                    \
                prologue,                                                \
            __ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_BEGIN,                  \
            __ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_END,                    \
            __VA_ARGS__) /* }}} */

/* {{{ public void ZAI_SAPI_TEST_CASE_WITH_TAGS_WITH_STUB_WITH_PROLOGUE(char *suite, char *description, char *tags, char *stub, block_t prologue, block_t code) */
#define ZAI_SAPI_TEST_CASE_WITH_TAGS_WITH_STUB_WITH_PROLOGUE(suite, description, tags, stub, prologue, ...) \
            ZAI_SAPI_TEST_CASE_IMPL(suite, description,                  \
                tags,                                                    \
                stub,                                                    \
                prologue,                                                \
            __ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_BEGIN,                  \
            __ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_END,                    \
            __VA_ARGS__) /* }}} */

/* {{{ public void ZAI_SAPI_TEST_BAILING_CASE_WITH_PROLOGUE(char *suite, char *description, block_t prologue, block_t code) */
#define ZAI_SAPI_TEST_BAILING_CASE_WITH_PROLOGUE(suite, description, prologue, ...) \
            ZAI_SAPI_TEST_CASE_IMPL(suite, description,                  \
                ZAI_SAPI_TEST_TAGS_NONE,                                 \
                ZAI_SAPI_TEST_STUB_NONE,                                 \
                prologue,                                                \
            __ZAI_SAPI_TEST_CASE_WITH_BAILOUT_BEGIN,                     \
            __ZAI_SAPI_TEST_CASE_WITH_BAILOUT_END,                       \
            __VA_ARGS__) /* }}} */

/* {{{ public void ZAI_SAPI_TEST_BAILING_CASE_WITH_TAGS_WITH_PROLOGUE(char *suite, char *description, char *tags, block_t prologue, block_t code) */
#define ZAI_SAPI_TEST_BAILING_CASE_WITH_TAGS_WITH_PROLOGUE(suite, description, tags, prologue, ...) \
            ZAI_SAPI_TEST_CASE_IMPL(suite, description,                  \
                tags,                                                    \
                ZAI_SAPI_TEST_STUB_NONE,                                 \
                prologue,                                                \
            __ZAI_SAPI_TEST_CASE_WITH_BAILOUT_BEGIN,                     \
            __ZAI_SAPI_TEST_CASE_WITH_BAILOUT_END,                       \
            __VA_ARGS__) /* }}} */

/* {{{ public void ZAI_SAPI_TEST_BAILING_CASE_WITH_STUB_WITH_PROLOGUE(char *suite, char *description, char *stub, block_t prologue, block_t code) */
#define ZAI_SAPI_TEST_BAILING_CASE_WITH_STUB_WITH_PROLOGUE(suite, description, stub, prologue, ...) \
            ZAI_SAPI_TEST_CASE_IMPL(suite, description,                  \
                ZAI_SAPI_TEST_TAGS_NONE,                                 \
                stub,                                                    \
                prologue,                                                \
            __ZAI_SAPI_TEST_CASE_WITH_BAILOUT_BEGIN,                     \
            __ZAI_SAPI_TEST_CASE_WITH_BAILOUT_END,                       \
            __VA_ARGS__) /* }}} */

/* {{{ public void ZAI_SAPI_TEST_BAILING_CASE_WITH_TAGS_WITH_STUB_WITH_PROLOGUE(char *suite, char *description, char *tags, char *stub, block_t prologue, block_t code) */
#define ZAI_SAPI_TEST_BAILING_CASE_WITH_TAGS_WITH_STUB_WITH_PROLOGUE(suite, description, tags, stub, prologue, ...) \
            ZAI_SAPI_TEST_CASE_IMPL(suite, description,                  \
                tags,                                                    \
                stub,                                                    \
                prologue,                                                \
            __ZAI_SAPI_TEST_CASE_WITH_BAILOUT_BEGIN,                     \
            __ZAI_SAPI_TEST_CASE_WITH_BAILOUT_END,                       \
            __VA_ARGS__) /* }}} */
// clang-format on
#endif
