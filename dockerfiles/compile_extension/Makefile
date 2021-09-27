# In order to have compilation properly working in CI, this target has to be
# executed so all relevant images are pushed to Datadog/dd-trace-ci.
publish: \
	push_alpine_5.5 \
	push_alpine_5.6 \
	push_alpine_7.0 \
	push_alpine_7.1 \
	push_alpine_7.2 \
	push_alpine_7.3 \
	push_alpine_7.4 \
	push_alpine_8.0 \
	push_alpine_8.1

build_image_alpine_%:
	docker-compose build $(*)-alpine

push_alpine_%: build_image_alpine_%
	docker-compose push $(*)-alpine

# You can use this target to locally build a specific php version on alpine
#   E.g.:
#     - make build_extension_alpine_7.3
build_extension_alpine_%:
	docker-compose run --rm $(*)-alpine
