## Build for x86_64

Built by the manual `Packaging build` and `Packaging publish` jobs of the
`ci-images` child pipeline (see `dockerfiles/ci/README.md`). Tags carry a
generation from `.env` (`FPM_PACKAGING_NEXT_VERSION`), so building the next
generation never overwrites the tag master uses.
