#ifndef DDPROF_EXPORTER_HH
#define DDPROF_EXPORTER_HH

#include <iostream>
#include <sstream>

#include "chrono.hh"
#include "recorder.hh"

namespace ddprof {
class exporter {
  public:
    virtual void operator()(const recorder::event_table_t &event_table, string_table &strings,
                            system_clock::time_point start_time, system_clock::time_point stop_time) = 0;
    virtual ~exporter() noexcept = 0;
};

inline exporter::~exporter() noexcept = default;

class pprof_exporter : public exporter {
    unsigned num = 0;
  public:
    void operator()(const recorder::event_table_t &event_table, string_table &strings,
                    system_clock::time_point start_time, system_clock::time_point stop_time) override;
};

}  // namespace ddprof

namespace ddprof {
class stream_exporter : public exporter {
    std::ostream &ostream;

  public:
    explicit stream_exporter(std::ostream &out) noexcept;
    void operator()(const recorder::event_table_t &event_table, string_table &strings,
                    system_clock::time_point start_time, system_clock::time_point stop_time) override;
    ~stream_exporter() noexcept override;
};

inline stream_exporter::stream_exporter(std::ostream &out) noexcept: ostream{out} {}

inline void stream_exporter::operator()(const recorder::event_table_t &event_table, string_table &strings,
                                        system_clock::time_point start_time, system_clock::time_point stop_time) {
    ostream << "{\n";
    ostream << "\tstarted_at: " << start_time.time_since_epoch().count() << ";\n";
    ostream << "\tstopped_at: " << stop_time.time_since_epoch().count() << ";\n";
    ostream << "\tevent_table: [\n";
    for (auto &event_it : event_table) {
        ostream << "\t\t{\n";
        ostream << "\t\t\tevent_type: " << event_it.first << ";\n";
        ostream << "\t\t\tcount: " << event_it.second.size() << ";\n";
        ostream << "\t\t\tdata: [\n";
        for (auto &event : event_it.second) {
            ostream << "\t\t\t\t" << *event << ",\n";
        }
        ostream << "\t\t\t]\n\t\t},\n";
    }

    ostream << "\t]\n";
    ostream << "}\n";
}

inline stream_exporter::~stream_exporter() noexcept = default;

}  // namespace ddprof

#endif  // DDPROF_EXPORTER_HH
