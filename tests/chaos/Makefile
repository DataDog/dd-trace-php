DURATION := 30s
TRACER_VERSION := 0.49.0

clean:
	docker-compose down -v
	rm -rf app/vendor

build:
	docker-compose build

up: tracer-versions/datadog-php-tracer_$(TRACER_VERSION)_amd64.deb
	TRACER_VERSION=${TRACER_VERSION} docker-compose up -d

download: tracer-versions/datadog-php-tracer_$(TRACER_VERSION)_amd64.deb

tracer-versions/datadog-php-tracer_$(TRACER_VERSION)_amd64.deb:
	curl -L -o ./tracer-versions/datadog-php-tracer_$(TRACER_VERSION)_amd64.deb https://github.com/DataDog/dd-trace-php/releases/download/$(TRACER_VERSION)/datadog-php-tracer_$(TRACER_VERSION)_amd64.deb

load:
	@ echo "Running load tests for ${DURATION}."
	@ echo "GET http://localhost:8000" | vegeta attack -duration=${DURATION} -connections=20 -rate=40 | tee results.bin | vegeta report
