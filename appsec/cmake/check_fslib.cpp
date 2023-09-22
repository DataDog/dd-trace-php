#ifdef __has_include
#  if __has_include(<version>)
#    include <version>
#  endif
#endif

#ifdef __cpp_lib_filesystem
#include <filesystem>
namespace std { namespace fs = filesystem; }
#else
#include <experimental/filesystem>
namespace std { namespace fs = experimental::filesystem; }
#endif
int main(int argc, char **argv)
{
	return std::fs::path{argv[0]}.string().length();
}
