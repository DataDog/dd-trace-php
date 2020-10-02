build_extension_alpine_%:
	docker-compose run --rm $(*)-alpine

publish: push_alpine_5.6 push_alpine_7.3

build_image_alpine_%:
	docker-compose build $(*)-alpine

push_alpine_%: build_image_alpine_%
	docker-compose push $(*)-alpine

pull_alpine_%:
	docker-compose pull $(*)-alpine
