#include "ddprof/exporter.hh"

#include <catch2/catch.hpp>
#include <sstream>

TEST_CASE("stream_exporter basics", "[exporter]") {
    std::stringstream ss{};

    ddprof::stream_exporter exporter{ss};

    auto before = ddprof::system_clock::now();

    ddprof::recorder::event_table_t event_table;
    ddprof::string_table strings{};
    event_table[ddprof::event::type::basic].emplace_back(
        new ddprof::event(ddprof::basic_event{0, ddprof::system_clock::now()}));

    auto after = ddprof::system_clock::now();

    exporter(event_table, strings, before, after);

    std::cout << ss.str() << std::endl;
}
