#include "zai_sapi/testing/catch2.hpp"

ZAI_SAPI_TEST_CASE("zai_sapi/testing/bailout", "case without pass", {
    /* no bailout */
})

ZAI_SAPI_TEST_CASE_WITH_TAGS("zai_sapi/testing/bailout", "case without fail", "[!shouldfail]", {
    zend_bailout();
})

ZAI_SAPI_TEST_BAILING_CASE("zai_sapi/testing/bailout", "case with pass", {
    zend_bailout();
})

ZAI_SAPI_TEST_BAILING_CASE_WITH_TAGS("zai_sapi/testing/bailout", "case with fail", "[!shouldfail]", {
    /* no bailout */
})

ZAI_SAPI_TEST_CASE("zai_sapi/testing/bailout", "code with pass", {
    ZAI_SAPI_TEST_CODE_WITH_BAILOUT({
        zend_bailout();
    });
})

ZAI_SAPI_TEST_CASE_WITH_TAGS("zai_sapi/testing/bailout", "code with fail", "[!shouldfail]", {
    ZAI_SAPI_TEST_CODE_WITH_BAILOUT({
        /* no bailout */
    });
})

ZAI_SAPI_TEST_CASE("zai_sapi/testing/bailout", "code without pass", {
    ZAI_SAPI_TEST_CODE_WITHOUT_BAILOUT({});
})

ZAI_SAPI_TEST_CASE_WITH_TAGS("zai_sapi/testing/bailout", "code without fail", "[!shouldfail]", {
    ZAI_SAPI_TEST_CODE_WITHOUT_BAILOUT({
        zend_bailout();
    })
})
