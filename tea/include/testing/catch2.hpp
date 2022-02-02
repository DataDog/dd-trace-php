#ifndef HAVE_TEA_TEST
#define HAVE_TEA_TEST
/**
* TEA Test Harness in Pre Processor:
*
*  TEA is tested with catch2 and this header provides a convenience
*  and consistency API over catch2
*
*  Test cases will all have meaningful naming (screen vs code), and sensible
*  form in test files.
*
*  For convenience:
*    TEA_TEST_CASE[_WITH_TAGS]_BARE(char *suite, char *description, [char *tags, ] block_t code)
*
*    Will produce a case with tagging consistent with other tests but not decorate the code
*
*  The decorator API provided is essentially:
*    TEA_TEST_[BAILING_]CASE[_WITH_TAGS][_WITH_STUB][_WITH_PROLOGUE]
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
*    TEA_TEST_CASE_[WITH|WITHOUT]_BAILOUT_[BEGIN|END]
*    Bare test cases may also use this API
*
*  Decorated code should use:
*    TEA_TEST_CODE_[WITH|WITHOUT]_BAILOUT(block_t code)
*
*    Code or cases tested with bailout will fail if code does not bail
*    Code or cases tested without bailout will fail if code bails
*/
extern "C" {
#include "../sapi.h"
}

#include <catch2/catch.hpp>

// clang-format off
#define TEA_TEST_TAGS_NONE     ""
#define TEA_TEST_STUB_NONE     NULL
#define TEA_TEST_PROLOGUE_NONE {}

/* {{{ TEA_TEST_TAG_NO_ASAN will hide a test when running under ASAN and always tag with [no-asan] */
#ifdef __SANITIZE_ADDRESS__
# define TEA_TEST_TAG_NO_ASAN   "[no-asan][!hide]"
#else
# define TEA_TEST_TAG_NO_ASAN   "[no-asan]"
#endif
/* }}} */

/* {{{ private void TEA_TEST_CASE_TAG(char *suite, char *description, char *tags) */
#define TEA_TEST_CASE_TAG(\
        __TEA_TEST_CASE_SUITE,                                       \
        __TEA_TEST_CASE_DESCRIPTION,                                 \
        __TEA_TEST_CASE_TAGS)                                        \
    __TEA_TEST_CASE_SUITE " [" __TEA_TEST_CASE_DESCRIPTION "]",      \
    "[" __TEA_TEST_CASE_SUITE "]" __TEA_TEST_CASE_TAGS /* }}} */

/* {{{ Test Case Fixing */
typedef enum {
    TEA_TEST_CASE_STAGE_INITIAL =  0b000,
    TEA_TEST_CASE_STAGE_PROLOGUE = 0b001,
    TEA_TEST_CASE_STAGE_PREFORK =  0b010,
    TEA_TEST_CASE_STAGE_REQUEST =  0b100
} TeaTestCaseStage;

class TeaTestCaseFixture {
public:
    TeaTestCaseFixture() {
       stage = TEA_TEST_CASE_STAGE_INITIAL;
    }

    bool tea_sapi_sinit() {
        if (::tea_sapi_sinit()) {
            stage |= TEA_TEST_CASE_STAGE_PROLOGUE;
            return true;
        }
        return false;
    }

    bool tea_sapi_minit() {
        if (::tea_sapi_minit()) {
            stage |= TEA_TEST_CASE_STAGE_PREFORK;
            return true;
        }
        return false;
    }

    bool tea_sapi_rinit() {
        if (::tea_sapi_rinit()) {
            stage |= TEA_TEST_CASE_STAGE_REQUEST;
            return true;
        }
        return false;
    }

    bool tea_sapi_spinup() {
        return tea_sapi_sinit() &&
               tea_sapi_minit() &&
               tea_sapi_rinit();
    }

    void tea_sapi_rshutdown() {
        stage &= ~TEA_TEST_CASE_STAGE_REQUEST;

        ::tea_sapi_rshutdown();
    }

    void tea_sapi_mshutdown() {
        stage &= ~TEA_TEST_CASE_STAGE_PREFORK;

        ::tea_sapi_mshutdown();
    }

    void tea_sapi_sshutdown() {
        stage &= ~TEA_TEST_CASE_STAGE_PROLOGUE;

        ::tea_sapi_sshutdown();
    }

    void tea_sapi_spindown() {
        tea_sapi_rshutdown();
        tea_sapi_mshutdown();
        tea_sapi_sshutdown();
    }

    virtual ~TeaTestCaseFixture() {
        if (stage & TEA_TEST_CASE_STAGE_REQUEST) {
            tea_sapi_rshutdown();
        }

        if (stage & TEA_TEST_CASE_STAGE_PREFORK) {
            tea_sapi_mshutdown();
        }

        if (stage & TEA_TEST_CASE_STAGE_PROLOGUE) {
            tea_sapi_sshutdown();
        }
    }

private:
    unsigned int stage;
};

#define TEA_TEST_CASE_DECL(...) \
    TEST_CASE_METHOD(           \
        TeaTestCaseFixture,     \
        TEA_TEST_CASE_TAG(__VA_ARGS__)) /* }}} */

/* {{{ public void TEA_TEST_CASE_BARE(char *suite, char *description, block_t code) */
#define TEA_TEST_CASE_BARE(suite, description, ...) \
    TEA_TEST_CASE_DECL(suite, description, TEA_TEST_TAGS_NONE) { \
        { __VA_ARGS__ }                                          \
    } /* }}} */

/* {{{ public void TEA_TEST_CASE_WITH_TAGS_BARE(char *suite, char *description, char *tags, block_t code) */
#define TEA_TEST_CASE_WITH_TAGS_BARE(suite, description, tags, ...) \
    TEA_TEST_CASE_DECL(suite, description, tags) {                  \
        { __VA_ARGS__ }                                             \
    } /* }}} */

/* {{{ Test Case Bailout Handling */
#define TEA_TEST_CASE_WITHOUT_BAILOUT_BEGIN()         \
{                                                     \
    volatile bool                                     \
        tea_test_case_without_bailout = true;         \
    zend_first_try {

#define TEA_TEST_CASE_WITHOUT_BAILOUT_END()           \
    } zend_catch {                                    \
        tea_test_case_without_bailout = false;        \
    } zend_end_try();                                 \
    REQUIRE(tea_test_case_without_bailout);           \
}

#define TEA_TEST_CASE_WITH_BAILOUT_BEGIN()            \
{                                                     \
    volatile bool                                     \
        tea_test_case_with_bailout = false;           \
    zend_first_try {

#define TEA_TEST_CASE_WITH_BAILOUT_END()              \
    } zend_catch {                                    \
        tea_test_case_with_bailout = true;            \
    } zend_end_try();                                 \
    REQUIRE(tea_test_case_with_bailout);              \
}
/* }}} */

/* {{{ Test Code Bailout Handling */

/* {{{ public void TEA_TEST_CODE_WITHOUT_BAILOUT(block_t code) */
#define TEA_TEST_CODE_WITHOUT_BAILOUT(...)            \
{                                                     \
  volatile bool                                       \
    tea_test_code_without_bailout = true;             \
                                                      \
  zend_try {                                          \
    { __VA_ARGS__ }                                   \
  } zend_catch {                                      \
    tea_test_code_without_bailout = false;            \
  } zend_end_try();                                   \
                                                      \
  REQUIRE(tea_test_code_without_bailout);             \
} /* }}} */

/* {{{ public void TEA_TEST_CODE_WITH_BAILOUT(block_t code) */
#define TEA_TEST_CODE_WITH_BAILOUT(...)               \
{                                                     \
  volatile bool                                       \
    tea_test_code_with_bailout = false;               \
                                                      \
  zend_try {                                          \
    { __VA_ARGS__ }                                   \
  } zend_catch {                                      \
    tea_test_code_with_bailout = true;                \
  } zend_end_try();                                   \
                                                      \
  REQUIRE(tea_test_code_with_bailout);                \
} /* }}} */

/* }}} */

/* {{{ private void TEA_TEST_CASE_IMPL(
                        char *suite,
                        char *description,
                        char *tags,
                        char *stub,
                        block_t prologue,
                        callable_t begin,
                        callable_t end,
                        block_t code) */
#define TEA_TEST_CASE_IMPL(                                         \
    __TEA_TEST_CASE_SUITE,                                          \
    __TEA_TEST_CASE_DESCRIPTION,                                    \
    __TEA_TEST_CASE_TAGS,                                           \
    __TEA_TEST_CASE_STUB,                                           \
    __TEA_TEST_CASE_PROLOGUE,                                       \
    __TEA_TEST_CASE_BEGIN,                                          \
    __TEA_TEST_CASE_END,                                            \
    ...)                                                            \
    TEA_TEST_CASE_DECL(                                             \
        __TEA_TEST_CASE_SUITE,                                      \
        __TEA_TEST_CASE_DESCRIPTION,                                \
        __TEA_TEST_CASE_TAGS) {                                     \
        REQUIRE(tea_sapi_sinit());                                  \
        __TEA_TEST_CASE_PROLOGUE                                    \
        REQUIRE(tea_sapi_minit());                                  \
        REQUIRE(tea_sapi_rinit());                                  \
        TEA_TSRMLS_FETCH();                                         \
        __TEA_TEST_CASE_BEGIN()                                     \
        if (__TEA_TEST_CASE_STUB) {                                 \
            volatile bool                                           \
                tea_test_case_stub_included = true;                 \
            zend_try {                                              \
                tea_test_case_stub_included =                       \
                    tea_execute_script(                             \
                        __TEA_TEST_CASE_STUB                        \
                        TEA_TSRMLS_CC);                             \
            } zend_catch {                                          \
                tea_test_case_stub_included = false;                \
            } zend_end_try();                                       \
            REQUIRE(tea_test_case_stub_included);                   \
        }                                                           \
        { __VA_ARGS__ }                                             \
        __TEA_TEST_CASE_END()                                       \
    } /* }}} */

/* {{{ public void TEA_TEST_CASE(char *suite, char *description, block_t code) */
#define TEA_TEST_CASE(suite, description, ...)                      \
            TEA_TEST_CASE_IMPL(suite, description,                  \
                TEA_TEST_TAGS_NONE,                                 \
                TEA_TEST_STUB_NONE,                                 \
                TEA_TEST_PROLOGUE_NONE,                             \
            TEA_TEST_CASE_WITHOUT_BAILOUT_BEGIN,                    \
            TEA_TEST_CASE_WITHOUT_BAILOUT_END,                      \
            __VA_ARGS__) /* }}} */

/* {{{ public void TEA_TEST_CASE_WITH_TAGS(char *suite, char *description, char *tags, block_t code) */
#define TEA_TEST_CASE_WITH_TAGS(suite, description, tags, ...)      \
            TEA_TEST_CASE_IMPL(suite, description,                  \
                tags,                                               \
                TEA_TEST_STUB_NONE,                                 \
                TEA_TEST_PROLOGUE_NONE,                             \
            TEA_TEST_CASE_WITHOUT_BAILOUT_BEGIN,                    \
            TEA_TEST_CASE_WITHOUT_BAILOUT_END,                      \
            __VA_ARGS__) /* }}} */

/* {{{ public void TEA_TEST_CASE_WITH_STUB(char *suite, char *description, char *stub, block_t code) */
#define TEA_TEST_CASE_WITH_STUB(suite, description, stub, ...)      \
            TEA_TEST_CASE_IMPL(suite, description,                  \
                TEA_TEST_TAGS_NONE,                                 \
                stub,                                               \
                TEA_TEST_PROLOGUE_NONE,                             \
            TEA_TEST_CASE_WITHOUT_BAILOUT_BEGIN,                    \
            TEA_TEST_CASE_WITHOUT_BAILOUT_END,                      \
            __VA_ARGS__) /* }}} */

/* {{{ public void TEA_TEST_CASE_WITH_TAGS_WITH_STUB(char *suite, char *description, char *tags, char *stub, block_t code) */
#define TEA_TEST_CASE_WITH_TAGS_WITH_STUB(suite, description, tags, stub, ...) \
            TEA_TEST_CASE_IMPL(suite, description,                  \
                tags,                                               \
                stub,                                               \
                TEA_TEST_PROLOGUE_NONE,                             \
            TEA_TEST_CASE_WITHOUT_BAILOUT_BEGIN,                    \
            TEA_TEST_CASE_WITHOUT_BAILOUT_END,                      \
            __VA_ARGS__) /* }}} */

/* {{{ public void TEA_TEST_BAILING_CASE(char *suite, char *description, block_t code) */
#define TEA_TEST_BAILING_CASE(suite, description, ...)              \
            TEA_TEST_CASE_IMPL(suite, description,                  \
                TEA_TEST_TAGS_NONE,                                 \
                TEA_TEST_STUB_NONE,                                 \
                TEA_TEST_PROLOGUE_NONE,                             \
            TEA_TEST_CASE_WITH_BAILOUT_BEGIN,                       \
            TEA_TEST_CASE_WITH_BAILOUT_END,                         \
            __VA_ARGS__) /* }}} */

/* {{{ public void TEA_TEST_BAILING_CASE_WITH_TAGS(char *suite, char *description, char *tags, block_t code) */
#define TEA_TEST_BAILING_CASE_WITH_TAGS(suite, description, tags, ...) \
            TEA_TEST_CASE_IMPL(suite, description,                  \
                tags,                                               \
                TEA_TEST_STUB_NONE,                                 \
                TEA_TEST_PROLOGUE_NONE,                             \
            TEA_TEST_CASE_WITH_BAILOUT_BEGIN,                       \
            TEA_TEST_CASE_WITH_BAILOUT_END,                         \
            __VA_ARGS__) /* }}} */

/* {{{ public void TEA_TEST_BAILING_CASE_WITH_STUB(char *suite, char *description, char *stub, block_t code) */
#define TEA_TEST_BAILING_CASE_WITH_STUB(suite, description, stub, ...) \
            TEA_TEST_CASE_IMPL(suite, description,                  \
                TEA_TEST_TAGS_NONE,                                 \
                stub,                                               \
                TEA_TEST_PROLOGUE_NONE,                             \
            TEA_TEST_CASE_WITH_BAILOUT_BEGIN,                       \
            TEA_TEST_CASE_WITH_BAILOUT_END,                         \
            __VA_ARGS__) /* }}} */

/* {{{ public void TEA_TEST_BAILING_CASE_WITH_TAGS_WITH_STUB(char *suite, char *description, char *tags, char *stub, block_t code) */
#define TEA_TEST_BAILING_CASE_WITH_TAGS_WITH_STUB(suite, description, tags, stub, ...) \
            TEA_TEST_CASE_IMPL(suite, description,                  \
                tags,                                               \
                stub,                                               \
                TEA_TEST_PROLOGUE_NONE,                             \
            TEA_TEST_CASE_WITH_BAILOUT_BEGIN,                       \
            TEA_TEST_CASE_WITH_BAILOUT_END,                         \
            __VA_ARGS__) /* }}} */

/* {{{ public void TEA_TEST_CASE_WITH_PROLOGUE(char *suite, char *description, block_t prologue, block_t code) */
#define TEA_TEST_CASE_WITH_PROLOGUE(suite, description, prologue, ...) \
            TEA_TEST_CASE_IMPL(suite, description,                  \
                TEA_TEST_TAGS_NONE,                                 \
                TEA_TEST_STUB_NONE,                                 \
                prologue,                                           \
            TEA_TEST_CASE_WITHOUT_BAILOUT_BEGIN,                    \
            TEA_TEST_CASE_WITHOUT_BAILOUT_END,                      \
            __VA_ARGS__) /* }}} */

/* {{{ public void TEA_TEST_CASE_WITH_TAGS_WITH_PROLOGUE(char *suite, char *description, char *tags, block_t prologue, block_t code) */
#define TEA_TEST_CASE_WITH_TAGS_WITH_PROLOGUE(suite, description, tags, prologue, ...) \
            TEA_TEST_CASE_IMPL(suite, description,                  \
                tags,                                               \
                TEA_TEST_STUB_NONE,                                 \
                prologue,                                           \
            TEA_TEST_CASE_WITHOUT_BAILOUT_BEGIN,                    \
            TEA_TEST_CASE_WITHOUT_BAILOUT_END,                      \
            __VA_ARGS__) /* }}} */

/* {{{ public void TEA_TEST_CASE_WITH_STUB_WITH_PROLOGUE(char *suite, char *description, char *stub, block_t prologue, block_t code) */
#define TEA_TEST_CASE_WITH_STUB_WITH_PROLOGUE(suite, description, stub, prologue, ...) \
            TEA_TEST_CASE_IMPL(suite, description,                  \
                TEA_TEST_TAGS_NONE,                                 \
                stub,                                               \
                prologue,                                           \
            TEA_TEST_CASE_WITHOUT_BAILOUT_BEGIN,                    \
            TEA_TEST_CASE_WITHOUT_BAILOUT_END,                      \
            __VA_ARGS__) /* }}} */

/* {{{ public void TEA_TEST_CASE_WITH_TAGS_WITH_STUB_WITH_PROLOGUE(char *suite, char *description, char *tags, char *stub, block_t prologue, block_t code) */
#define TEA_TEST_CASE_WITH_TAGS_WITH_STUB_WITH_PROLOGUE(suite, description, tags, stub, prologue, ...) \
            TEA_TEST_CASE_IMPL(suite, description,                  \
                tags,                                               \
                stub,                                               \
                prologue,                                           \
            TEA_TEST_CASE_WITHOUT_BAILOUT_BEGIN,                    \
            TEA_TEST_CASE_WITHOUT_BAILOUT_END,                      \
            __VA_ARGS__) /* }}} */

/* {{{ public void TEA_TEST_BAILING_CASE_WITH_PROLOGUE(char *suite, char *description, block_t prologue, block_t code) */
#define TEA_TEST_BAILING_CASE_WITH_PROLOGUE(suite, description, prologue, ...) \
            TEA_TEST_CASE_IMPL(suite, description,                  \
                TEA_TEST_TAGS_NONE,                                 \
                TEA_TEST_STUB_NONE,                                 \
                prologue,                                           \
            TEA_TEST_CASE_WITH_BAILOUT_BEGIN,                       \
            TEA_TEST_CASE_WITH_BAILOUT_END,                         \
            __VA_ARGS__) /* }}} */

/* {{{ public void TEA_TEST_BAILING_CASE_WITH_TAGS_WITH_PROLOGUE(char *suite, char *description, char *tags, block_t prologue, block_t code) */
#define TEA_TEST_BAILING_CASE_WITH_TAGS_WITH_PROLOGUE(suite, description, tags, prologue, ...) \
            TEA_TEST_CASE_IMPL(suite, description,                  \
                tags,                                               \
                TEA_TEST_STUB_NONE,                                 \
                prologue,                                           \
            TEA_TEST_CASE_WITH_BAILOUT_BEGIN,                       \
            TEA_TEST_CASE_WITH_BAILOUT_END,                         \
            __VA_ARGS__) /* }}} */

/* {{{ public void TEA_TEST_BAILING_CASE_WITH_STUB_WITH_PROLOGUE(char *suite, char *description, char *stub, block_t prologue, block_t code) */
#define TEA_TEST_BAILING_CASE_WITH_STUB_WITH_PROLOGUE(suite, description, stub, prologue, ...) \
            TEA_TEST_CASE_IMPL(suite, description,                  \
                TEA_TEST_TAGS_NONE,                                 \
                stub,                                               \
                prologue,                                           \
            TEA_TEST_CASE_WITH_BAILOUT_BEGIN,                       \
            TEA_TEST_CASE_WITH_BAILOUT_END,                         \
            __VA_ARGS__) /* }}} */

/* {{{ public void TEA_TEST_BAILING_CASE_WITH_TAGS_WITH_STUB_WITH_PROLOGUE(char *suite, char *description, char *tags, char *stub, block_t prologue, block_t code) */
#define TEA_TEST_BAILING_CASE_WITH_TAGS_WITH_STUB_WITH_PROLOGUE(suite, description, tags, stub, prologue, ...) \
            TEA_TEST_CASE_IMPL(suite, description,                  \
                tags,                                               \
                stub,                                               \
                prologue,                                           \
            TEA_TEST_CASE_WITH_BAILOUT_BEGIN,                       \
            TEA_TEST_CASE_WITH_BAILOUT_END,                         \
            __VA_ARGS__) /* }}} */
// clang-format on
#endif
