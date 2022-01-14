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
*  Stubs (as a pre-requisite of test code) are required to execute without error
*    Errors in stubs will cause the test to fail
*
*  Bailout Handling:
*
*  The decorator API will generate appropriate bailout handling for the case using
*    ZAI_SAPI_TEST_CASE_[WITH|WITHOUT]_BAILOUT_[BEGIN|END]
*    Bare test cases may also use this API
*
*  Decorated code should use:
*    ZAI_SAPI_TEST_CODE_[WITH|WITHOUT]_BAILOUT(block_t code)
*
*    Code or cases tested with bailout will fail if code does not bail
*    Code or cases tested without bailout will fail if code bails
*/
extern "C" {
#include "../zai_sapi.h"
}

#include <catch2/catch.hpp>

// clang-format off
#define ZAI_SAPI_TEST_TAGS_NONE     ""
#define ZAI_SAPI_TEST_STUB_NONE     NULL
#define ZAI_SAPI_TEST_PROLOGUE_NONE {}

/* {{{ ZAI_SAPI_TEST_TAG_NO_ASAN will hide a test when running under ASAN and always tag with [no-asan] */
#ifdef __SANITIZE_ADDRESS__
# define ZAI_SAPI_TEST_TAG_NO_ASAN   "[no-asan][!hide]"
#else
# define ZAI_SAPI_TEST_TAG_NO_ASAN   "[no-asan]"
#endif
/* }}} */

/* {{{ private void ZAI_SAPI_TEST_CASE_TAG(char *suite, char *description, char *tags) */
#define ZAI_SAPI_TEST_CASE_TAG(\
        __ZAI_SAPI_TEST_CASE_SUITE,                                       \
        __ZAI_SAPI_TEST_CASE_DESCRIPTION,                                 \
        __ZAI_SAPI_TEST_CASE_TAGS)                                        \
    __ZAI_SAPI_TEST_CASE_SUITE " [" __ZAI_SAPI_TEST_CASE_DESCRIPTION "]", \
    "[" __ZAI_SAPI_TEST_CASE_SUITE "]" __ZAI_SAPI_TEST_CASE_TAGS /* }}} */

/* {{{ Test Case Fixing */
typedef enum {
    ZAI_SAPI_TEST_CASE_STAGE_INITIAL =  0b000,
    ZAI_SAPI_TEST_CASE_STAGE_PROLOGUE = 0b001,
    ZAI_SAPI_TEST_CASE_STAGE_PREFORK =  0b010,
    ZAI_SAPI_TEST_CASE_STAGE_REQUEST =  0b100
} ZaiSapiTestCaseStage;

class ZaiSapiTestCaseFixture {
public:
    ZaiSapiTestCaseFixture() {
       stage = ZAI_SAPI_TEST_CASE_STAGE_INITIAL;
    }

    bool zai_sapi_sinit() {
        if (::zai_sapi_sinit()) {
            stage |= ZAI_SAPI_TEST_CASE_STAGE_PROLOGUE;
            return true;
        }
        return false;
    }

    bool zai_sapi_minit() {
        if (::zai_sapi_minit()) {
            stage |= ZAI_SAPI_TEST_CASE_STAGE_PREFORK;
            return true;
        }
        return false;
    }

    bool zai_sapi_rinit() {
        if (::zai_sapi_rinit()) {
            stage |= ZAI_SAPI_TEST_CASE_STAGE_REQUEST;
            return true;
        }
        return false;
    }

    bool zai_sapi_spinup() {
        return zai_sapi_sinit() &&
               zai_sapi_minit() &&
               zai_sapi_rinit();
    }

    void zai_sapi_rshutdown() {
        stage &= ~ZAI_SAPI_TEST_CASE_STAGE_REQUEST;

        ::zai_sapi_rshutdown();
    }

    void zai_sapi_mshutdown() {
        stage &= ~ZAI_SAPI_TEST_CASE_STAGE_PREFORK;

        ::zai_sapi_mshutdown();
    }

    void zai_sapi_sshutdown() {
        stage &= ~ZAI_SAPI_TEST_CASE_STAGE_PROLOGUE;

        ::zai_sapi_sshutdown();
    }

    void zai_sapi_spindown() {
        zai_sapi_rshutdown();
        zai_sapi_mshutdown();
        zai_sapi_sshutdown();
    }

    virtual ~ZaiSapiTestCaseFixture() {
        if (stage & ZAI_SAPI_TEST_CASE_STAGE_REQUEST) {
            zai_sapi_rshutdown();
        }

        if (stage & ZAI_SAPI_TEST_CASE_STAGE_PREFORK) {
            zai_sapi_mshutdown();
        }

        if (stage & ZAI_SAPI_TEST_CASE_STAGE_PROLOGUE) {
            zai_sapi_sshutdown();
        }
    }

private:
    unsigned int stage;
};

#define ZAI_SAPI_TEST_CASE_DECL(...) \
    TEST_CASE_METHOD(                \
        ZaiSapiTestCaseFixture,      \
        ZAI_SAPI_TEST_CASE_TAG(__VA_ARGS__)) /* }}} */

/* {{{ public void ZAI_SAPI_TEST_CASE_BARE(char *suite, char *description, block_t code) */
#define ZAI_SAPI_TEST_CASE_BARE(suite, description, ...)                        \
    ZAI_SAPI_TEST_CASE_DECL(suite, description, ZAI_SAPI_TEST_TAGS_NONE) {      \
        { __VA_ARGS__ }                                                         \
    } /* }}} */

/* {{{ public void ZAI_SAPI_TEST_CASE_WITH_TAGS_BARE(char *suite, char *description, char *tags, block_t code) */
#define ZAI_SAPI_TEST_CASE_WITH_TAGS_BARE(suite, description, tags, ...)        \
    ZAI_SAPI_TEST_CASE_DECL(suite, description, tags) {                         \
        { __VA_ARGS__ }                                                         \
    } /* }}} */

/* {{{ Test Case Bailout Handling */
#define ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_BEGIN()    \
{                                                     \
    volatile bool                                     \
        zai_sapi_test_case_without_bailout = true;    \
    zend_first_try {

#define ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_END()      \
    } zend_catch {                                    \
        zai_sapi_test_case_without_bailout = false;   \
    } zend_end_try();                                 \
    REQUIRE(zai_sapi_test_case_without_bailout);      \
}

#define ZAI_SAPI_TEST_CASE_WITH_BAILOUT_BEGIN()       \
{                                                     \
    volatile bool                                     \
        zai_sapi_test_case_with_bailout = false;      \
    zend_first_try {

#define ZAI_SAPI_TEST_CASE_WITH_BAILOUT_END()         \
    } zend_catch {                                    \
        zai_sapi_test_case_with_bailout = true;       \
    } zend_end_try();                                 \
    REQUIRE(zai_sapi_test_case_with_bailout);         \
}
/* }}} */

/* {{{ Test Code Bailout Handling */

/* {{{ public void ZAI_SAPI_TEST_CODE_WITHOUT_BAILOUT(block_t code) */
#define ZAI_SAPI_TEST_CODE_WITHOUT_BAILOUT(...)       \
{                                                     \
  volatile bool                                       \
    zai_sapi_test_code_without_bailout = true;        \
                                                      \
  zend_try {                                          \
    { __VA_ARGS__ }                                   \
  } zend_catch {                                      \
    zai_sapi_test_code_without_bailout = false;       \
  } zend_end_try();                                   \
                                                      \
  REQUIRE(zai_sapi_test_code_without_bailout);        \
} /* }}} */

/* {{{ public void ZAI_SAPI_TEST_CODE_WITH_BAILOUT(block_t code) */
#define ZAI_SAPI_TEST_CODE_WITH_BAILOUT(...)          \
{                                                     \
  volatile bool                                       \
    zai_sapi_test_code_with_bailout = false;          \
                                                      \
  zend_try {                                          \
    { __VA_ARGS__ }                                   \
  } zend_catch {                                      \
    zai_sapi_test_code_with_bailout = true;           \
  } zend_end_try();                                   \
                                                      \
  REQUIRE(zai_sapi_test_code_with_bailout);           \
} /* }}} */

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
    ZAI_SAPI_TEST_CASE_DECL(                                             \
        __ZAI_SAPI_TEST_CASE_SUITE,                                      \
        __ZAI_SAPI_TEST_CASE_DESCRIPTION,                                \
        __ZAI_SAPI_TEST_CASE_TAGS) {                                     \
        REQUIRE(zai_sapi_sinit());                                       \
        __ZAI_SAPI_TEST_CASE_PROLOGUE                                    \
        REQUIRE(zai_sapi_minit());                                       \
        REQUIRE(zai_sapi_rinit());                                       \
        ZAI_SAPI_TSRMLS_FETCH();                                         \
        __ZAI_SAPI_TEST_CASE_BEGIN()                                     \
        if (__ZAI_SAPI_TEST_CASE_STUB) {                                 \
            volatile bool                                                \
                zai_sapi_test_case_stub_included = true;                 \
            zend_first_try {                                             \
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
    } /* }}} */

/* {{{ public void ZAI_SAPI_TEST_CASE(char *suite, char *description, block_t code) */
#define ZAI_SAPI_TEST_CASE(suite, description, ...)                      \
            ZAI_SAPI_TEST_CASE_IMPL(suite, description,                  \
                ZAI_SAPI_TEST_TAGS_NONE,                                 \
                ZAI_SAPI_TEST_STUB_NONE,                                 \
                ZAI_SAPI_TEST_PROLOGUE_NONE,                             \
            ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_BEGIN,                    \
            ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_END,                      \
            __VA_ARGS__) /* }}} */

/* {{{ public void ZAI_SAPI_TEST_CASE_WITH_TAGS(char *suite, char *description, char *tags, block_t code) */
#define ZAI_SAPI_TEST_CASE_WITH_TAGS(suite, description, tags, ...)      \
            ZAI_SAPI_TEST_CASE_IMPL(suite, description,                  \
                tags,                                                    \
                ZAI_SAPI_TEST_STUB_NONE,                                 \
                ZAI_SAPI_TEST_PROLOGUE_NONE,                             \
            ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_BEGIN,                    \
            ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_END,                      \
            __VA_ARGS__) /* }}} */

/* {{{ public void ZAI_SAPI_TEST_CASE_WITH_STUB(char *suite, char *description, char *stub, block_t code) */
#define ZAI_SAPI_TEST_CASE_WITH_STUB(suite, description, stub, ...)      \
            ZAI_SAPI_TEST_CASE_IMPL(suite, description,                  \
                ZAI_SAPI_TEST_TAGS_NONE,                                 \
                stub,                                                    \
                ZAI_SAPI_TEST_PROLOGUE_NONE,                             \
            ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_BEGIN,                    \
            ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_END,                      \
            __VA_ARGS__) /* }}} */

/* {{{ public void ZAI_SAPI_TEST_CASE_WITH_TAGS_WITH_STUB(char *suite, char *description, char *tags, char *stub, block_t code) */
#define ZAI_SAPI_TEST_CASE_WITH_TAGS_WITH_STUB(suite, description, tags, stub, ...) \
            ZAI_SAPI_TEST_CASE_IMPL(suite, description,                  \
                tags,                                                    \
                stub,                                                    \
                ZAI_SAPI_TEST_PROLOGUE_NONE,                             \
            ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_BEGIN,                    \
            ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_END,                      \
            __VA_ARGS__) /* }}} */

/* {{{ public void ZAI_SAPI_TEST_BAILING_CASE(char *suite, char *description, block_t code) */
#define ZAI_SAPI_TEST_BAILING_CASE(suite, description, ...)              \
            ZAI_SAPI_TEST_CASE_IMPL(suite, description,                  \
                ZAI_SAPI_TEST_TAGS_NONE,                                 \
                ZAI_SAPI_TEST_STUB_NONE,                                 \
                ZAI_SAPI_TEST_PROLOGUE_NONE,                             \
            ZAI_SAPI_TEST_CASE_WITH_BAILOUT_BEGIN,                       \
            ZAI_SAPI_TEST_CASE_WITH_BAILOUT_END,                         \
            __VA_ARGS__) /* }}} */

/* {{{ public void ZAI_SAPI_TEST_BAILING_CASE_WITH_TAGS(char *suite, char *description, char *tags, block_t code) */
#define ZAI_SAPI_TEST_BAILING_CASE_WITH_TAGS(suite, description, tags, ...) \
            ZAI_SAPI_TEST_CASE_IMPL(suite, description,                  \
                tags,                                                    \
                ZAI_SAPI_TEST_STUB_NONE,                                 \
                ZAI_SAPI_TEST_PROLOGUE_NONE,                             \
            ZAI_SAPI_TEST_CASE_WITH_BAILOUT_BEGIN,                       \
            ZAI_SAPI_TEST_CASE_WITH_BAILOUT_END,                         \
            __VA_ARGS__) /* }}} */

/* {{{ public void ZAI_SAPI_TEST_BAILING_CASE_WITH_STUB(char *suite, char *description, char *stub, block_t code) */
#define ZAI_SAPI_TEST_BAILING_CASE_WITH_STUB(suite, description, stub, ...) \
            ZAI_SAPI_TEST_CASE_IMPL(suite, description,                  \
                ZAI_SAPI_TEST_TAGS_NONE,                                 \
                stub,                                                    \
                ZAI_SAPI_TEST_PROLOGUE_NONE,                             \
            ZAI_SAPI_TEST_CASE_WITH_BAILOUT_BEGIN,                       \
            ZAI_SAPI_TEST_CASE_WITH_BAILOUT_END,                         \
            __VA_ARGS__) /* }}} */

/* {{{ public void ZAI_SAPI_TEST_BAILING_CASE_WITH_TAGS_WITH_STUB(char *suite, char *description, char *tags, char *stub, block_t code) */
#define ZAI_SAPI_TEST_BAILING_CASE_WITH_TAGS_WITH_STUB(suite, description, tags, stub, ...) \
            ZAI_SAPI_TEST_CASE_IMPL(suite, description,                  \
                tags,                                                    \
                stub,                                                    \
                ZAI_SAPI_TEST_PROLOGUE_NONE,                             \
            ZAI_SAPI_TEST_CASE_WITH_BAILOUT_BEGIN,                       \
            ZAI_SAPI_TEST_CASE_WITH_BAILOUT_END,                         \
            __VA_ARGS__) /* }}} */

/* {{{ public void ZAI_SAPI_TEST_CASE_WITH_PROLOGUE(char *suite, char *description, block_t prologue, block_t code) */
#define ZAI_SAPI_TEST_CASE_WITH_PROLOGUE(suite, description, prologue, ...) \
            ZAI_SAPI_TEST_CASE_IMPL(suite, description,                  \
                ZAI_SAPI_TEST_TAGS_NONE,                                 \
                ZAI_SAPI_TEST_STUB_NONE,                                 \
                prologue,                                                \
            ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_BEGIN,                    \
            ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_END,                      \
            __VA_ARGS__) /* }}} */

/* {{{ public void ZAI_SAPI_TEST_CASE_WITH_TAGS_WITH_PROLOGUE(char *suite, char *description, char *tags, block_t prologue, block_t code) */
#define ZAI_SAPI_TEST_CASE_WITH_TAGS_WITH_PROLOGUE(suite, description, tags, prologue, ...) \
            ZAI_SAPI_TEST_CASE_IMPL(suite, description,                  \
                tags,                                                    \
                ZAI_SAPI_TEST_STUB_NONE,                                 \
                prologue,                                                \
            ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_BEGIN,                    \
            ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_END,                      \
            __VA_ARGS__) /* }}} */

/* {{{ public void ZAI_SAPI_TEST_CASE_WITH_STUB_WITH_PROLOGUE(char *suite, char *description, char *stub, block_t prologue, block_t code) */
#define ZAI_SAPI_TEST_CASE_WITH_STUB_WITH_PROLOGUE(suite, description, stub, prologue, ...) \
            ZAI_SAPI_TEST_CASE_IMPL(suite, description,                  \
                ZAI_SAPI_TEST_TAGS_NONE,                                 \
                stub,                                                    \
                prologue,                                                \
            ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_BEGIN,                    \
            ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_END,                      \
            __VA_ARGS__) /* }}} */

/* {{{ public void ZAI_SAPI_TEST_CASE_WITH_TAGS_WITH_STUB_WITH_PROLOGUE(char *suite, char *description, char *tags, char *stub, block_t prologue, block_t code) */
#define ZAI_SAPI_TEST_CASE_WITH_TAGS_WITH_STUB_WITH_PROLOGUE(suite, description, tags, stub, prologue, ...) \
            ZAI_SAPI_TEST_CASE_IMPL(suite, description,                  \
                tags,                                                    \
                stub,                                                    \
                prologue,                                                \
            ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_BEGIN,                    \
            ZAI_SAPI_TEST_CASE_WITHOUT_BAILOUT_END,                      \
            __VA_ARGS__) /* }}} */

/* {{{ public void ZAI_SAPI_TEST_BAILING_CASE_WITH_PROLOGUE(char *suite, char *description, block_t prologue, block_t code) */
#define ZAI_SAPI_TEST_BAILING_CASE_WITH_PROLOGUE(suite, description, prologue, ...) \
            ZAI_SAPI_TEST_CASE_IMPL(suite, description,                  \
                ZAI_SAPI_TEST_TAGS_NONE,                                 \
                ZAI_SAPI_TEST_STUB_NONE,                                 \
                prologue,                                                \
            ZAI_SAPI_TEST_CASE_WITH_BAILOUT_BEGIN,                       \
            ZAI_SAPI_TEST_CASE_WITH_BAILOUT_END,                         \
            __VA_ARGS__) /* }}} */

/* {{{ public void ZAI_SAPI_TEST_BAILING_CASE_WITH_TAGS_WITH_PROLOGUE(char *suite, char *description, char *tags, block_t prologue, block_t code) */
#define ZAI_SAPI_TEST_BAILING_CASE_WITH_TAGS_WITH_PROLOGUE(suite, description, tags, prologue, ...) \
            ZAI_SAPI_TEST_CASE_IMPL(suite, description,                  \
                tags,                                                    \
                ZAI_SAPI_TEST_STUB_NONE,                                 \
                prologue,                                                \
            ZAI_SAPI_TEST_CASE_WITH_BAILOUT_BEGIN,                       \
            ZAI_SAPI_TEST_CASE_WITH_BAILOUT_END,                         \
            __VA_ARGS__) /* }}} */

/* {{{ public void ZAI_SAPI_TEST_BAILING_CASE_WITH_STUB_WITH_PROLOGUE(char *suite, char *description, char *stub, block_t prologue, block_t code) */
#define ZAI_SAPI_TEST_BAILING_CASE_WITH_STUB_WITH_PROLOGUE(suite, description, stub, prologue, ...) \
            ZAI_SAPI_TEST_CASE_IMPL(suite, description,                  \
                ZAI_SAPI_TEST_TAGS_NONE,                                 \
                stub,                                                    \
                prologue,                                                \
            ZAI_SAPI_TEST_CASE_WITH_BAILOUT_BEGIN,                       \
            ZAI_SAPI_TEST_CASE_WITH_BAILOUT_END,                         \
            __VA_ARGS__) /* }}} */

/* {{{ public void ZAI_SAPI_TEST_BAILING_CASE_WITH_TAGS_WITH_STUB_WITH_PROLOGUE(char *suite, char *description, char *tags, char *stub, block_t prologue, block_t code) */
#define ZAI_SAPI_TEST_BAILING_CASE_WITH_TAGS_WITH_STUB_WITH_PROLOGUE(suite, description, tags, stub, prologue, ...) \
            ZAI_SAPI_TEST_CASE_IMPL(suite, description,                  \
                tags,                                                    \
                stub,                                                    \
                prologue,                                                \
            ZAI_SAPI_TEST_CASE_WITH_BAILOUT_BEGIN,                       \
            ZAI_SAPI_TEST_CASE_WITH_BAILOUT_END,                         \
            __VA_ARGS__) /* }}} */
// clang-format on
#endif
