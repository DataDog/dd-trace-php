#include "ddprof/scheduler.hh"

#include <catch2/catch.hpp>

TEST_CASE("scheduler basics", "[scheduler]") {
    class test_exporter : public ddprof::exporter {
        public:
        bool &exported;
        explicit test_exporter(bool &b) : exported{b} {}

        void operator()(const ddprof::recorder::event_table_t &event_table, const ddprof::string_table &strings,
                        ddprof::system_clock::time_point start_time,
                        ddprof::system_clock::time_point stop_time) override {
            exported = true;
        }
        ~test_exporter() noexcept override = default;
    };

    bool exported = false;

    {
        auto interval = std::chrono::milliseconds(10);
        ddprof::recorder recorder;
        std::vector<std::unique_ptr<ddprof::exporter>> exporters{};
        exporters.emplace_back(std::unique_ptr<ddprof::exporter>(new test_exporter{exported}));

        /* We should be able to push the events into the recorder before the
         * scheduler starts, as it should do a final collection when it stops.
         */
        auto event = new ddprof::event(ddprof::basic_event{0, ddprof::system_clock::now()});
        recorder.push(std::unique_ptr<ddprof::event>(event));

        ddprof::scheduler scheduler{recorder, exporters, interval};
        scheduler.start();

        scheduler.stop();
        scheduler.join();
    }

    REQUIRE(exported);
}
