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
    ostream << "\tcount: " << event_table.size() << ";\n";
    ostream << "\tevent_table: [\n";
    for (auto &event : event_table) {
        ostream << "\t\t{\n";
        ostream << "\t\t\tname: " << &strings[event->name].data[0] << ";\n";
        ostream << "\t\t\tthread_name: " << &strings[event->thread_name].data[0] << ";\n";
        ostream << "\t\t\tthread_id: " << event->thread_id << ";\n";
        ostream << "\t\t\tsystem_time: " << event->system_time.time_since_epoch().count() << ";\n";
        ostream << "\t\t\tsteady_time: " << event->steady_time.time_since_epoch().count() << ";\n";

        ostream << "\t\t\tframes: [\n";
        for (auto &frame : event->frames) {
            ostream << "\t\t\t\t[" ;
            ostream << " " << &strings[frame.function_name].data[0];
            ostream << " " << &strings[frame.filename].data[0];
            ostream << ":" << frame.lineno;
            ostream << "\n";
        }
        ostream << "\t\t\t];\n";

        ostream << "\t\t},\n";
    }

    ostream << "\t]\n";
    ostream << "}\n";
}

inline stream_exporter::~stream_exporter() noexcept = default;

}  // namespace ddprof

#endif  // DDPROF_EXPORTER_HH
