#ifndef DDPROF_COLLECTOR_HH
#define DDPROF_COLLECTOR_HH

#include "service.hh"

namespace ddprof {

class collector : public service {};

class periodic_collector : public collector, public periodic_service {
    public:
    // todo: should return something iterable
    void collect() noexcept;
};

}  // namespace ddprof

#endif  // DDPROF_COLLECTOR_HH
