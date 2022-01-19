#include <include/testing/catch2.hpp>

TEA_TEST_CASE("tea/testing/bailout", "case without pass", {
    /* no bailout */
})

TEA_TEST_CASE_WITH_TAGS("tea/testing/bailout", "case without fail", "[!shouldfail]", {
    zend_bailout();
})

TEA_TEST_BAILING_CASE("tea/testing/bailout", "case with pass", {
    zend_bailout();
})

TEA_TEST_BAILING_CASE_WITH_TAGS("tea/testing/bailout", "case with fail", "[!shouldfail]", {
    /* no bailout */
})

TEA_TEST_CASE("tea/testing/bailout", "code with pass", {
    TEA_TEST_CODE_WITH_BAILOUT({
        zend_bailout();
    });
})

TEA_TEST_CASE_WITH_TAGS("tea/testing/bailout", "code with fail", "[!shouldfail]", {
    TEA_TEST_CODE_WITH_BAILOUT({
        /* no bailout */
    });
})

TEA_TEST_CASE("tea/testing/bailout", "code without pass", {
    TEA_TEST_CODE_WITHOUT_BAILOUT({});
})

TEA_TEST_CASE_WITH_TAGS("tea/testing/bailout", "code without fail", "[!shouldfail]", {
    TEA_TEST_CODE_WITHOUT_BAILOUT({
        zend_bailout();
    })
})
