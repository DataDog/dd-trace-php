namespace dds {

template <typename T>
struct defer {
    defer(T &&r_): runnable(std::move(r_)) {}
    ~defer() { runnable(); }
    T runnable;
};

} // namespace dds
