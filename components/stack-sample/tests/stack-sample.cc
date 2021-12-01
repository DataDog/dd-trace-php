extern "C" {
#include <components/stack-sample/stack-sample.h>
#include <components/string_view/string_view.h>
}

#include <catch2/catch.hpp>

TEST_CASE("empty ctor and dtor", "[stack-sample]") {
    datadog_php_stack_sample sample;
    datadog_php_stack_sample_ctor(&sample);

    CHECK(datadog_php_stack_sample_depth(&sample) == 0u);

    datadog_php_stack_sample_dtor(&sample);
}

TEST_CASE("empty iterator ctor and dtor", "[stack-sample]") {
    datadog_php_stack_sample sample;
    datadog_php_stack_sample_ctor(&sample);

    datadog_php_stack_sample_iterator iterator = datadog_php_stack_sample_iterator_ctor(&sample);

    CHECK(datadog_php_stack_sample_iterator_depth(&iterator) == 0u);

    for (iterator = datadog_php_stack_sample_iterator_ctor(&sample); datadog_php_stack_sample_iterator_valid(&iterator);
         datadog_php_stack_sample_iterator_next(&iterator)) {
    }

    CHECK(datadog_php_stack_sample_iterator_depth(&iterator) == 0u);

    datadog_php_stack_sample_iterator_dtor(&iterator);
    datadog_php_stack_sample_dtor(&sample);
}

TEST_CASE("iterator", "[stack-sample]") {
    datadog_php_stack_sample sample;
    datadog_php_stack_sample_ctor(&sample);

    const datadog_php_stack_sample_frame main_frame = {datadog_php_string_view_from_cstr("{main}"),
                                                       datadog_php_string_view_from_cstr("/srv/public/index.php"), 3};
    CHECK(datadog_php_stack_sample_try_add(&sample, main_frame));

    CHECK(datadog_php_stack_sample_depth(&sample) == 1u);

    auto iterator = datadog_php_stack_sample_iterator_ctor(&sample);

    CHECK(datadog_php_stack_sample_iterator_depth(&iterator) == 0u);

    for (iterator = datadog_php_stack_sample_iterator_ctor(&sample); datadog_php_stack_sample_iterator_valid(&iterator);
         datadog_php_stack_sample_iterator_next(&iterator)) {
        auto frame = datadog_php_stack_sample_iterator_frame(&iterator);
        CHECK(datadog_php_string_view_equal(frame.function, main_frame.function));
        CHECK(datadog_php_string_view_equal(frame.file, main_frame.file));
        CHECK(frame.lineno == main_frame.lineno);
    }

    CHECK(datadog_php_stack_sample_iterator_depth(&iterator) == 1u);

    datadog_php_stack_sample_iterator_dtor(&iterator);
    datadog_php_stack_sample_dtor(&sample);
}
