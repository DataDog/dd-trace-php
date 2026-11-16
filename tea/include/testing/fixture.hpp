#ifndef HAVE_TEA_FIXTURE
#define HAVE_TEA_FIXTURE

extern "C" {
#include "../sapi.h"
};

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

#endif  // HAVE_TEA_FIXTURE