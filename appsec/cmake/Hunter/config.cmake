
if (CMAKE_CXX_FLAGS)
    set(HUNTER_BOOST_CXX_FLAGS "CMAKE_CXX_FLAGS=${CMAKE_CXX_FLAGS}")
endif()
if (CMAKE_CXX_LINK_FLAGS)
    set(HUNTER_BOOST_CXX_LINKFLAGS "CMAKE_CXX_LINK_FLAGS=${CMAKE_CXX_FLAGS}")
endif()

hunter_config(Boost
        VERSION 1.86.0
        URL "https://archives.boost.io/release/1.86.0/source/boost_1_86_0.tar.bz2"
        SHA1
        fd0d26a7d5eadf454896942124544120e3b7a38f
        CMAKE_ARGS ${HUNTER_BOOST_CXX_FLAGS} ${HUNTER_BOOST_CXX_LINKFLAGS}
)
