#include <cstdint>
#include <string>
#include <vector>

int main() {
    std::vector<std::string> values = {"hermetic", "libc++", "allocation"};
    std::string joined;
    for (const auto &value : values) {
        joined += value;
    }
    return sizeof(std::uintptr_t) == 8 && joined == "hermeticlibc++allocation" ? 0 : 1;
}
