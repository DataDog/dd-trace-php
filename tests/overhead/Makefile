build: nginx 73_notracer 73_master 73_head 73_release
	@echo "Build completed!"

nginx:
	@docker build -t overhead-nginx -f dockerfiles/Dockerfile_nginx ../..

73_base:
	@docker build -t overhead-php-fpm-base -f dockerfiles/Dockerfile_73_fpm_base ../..

73_notracer: 73_base
	@docker build -t overhead-php-fpm-notracer -f dockerfiles/Dockerfile_73_fpm_notracer ../..


73_master: 73_base
	@docker build -t overhead-php-fpm-master -f dockerfiles/Dockerfile_73_fpm_master ../..

73_head: 73_base
	@docker build -t overhead-php-fpm-head -f dockerfiles/Dockerfile_73_fpm_head ../..

73_release: 73_base
	@docker build -t overhead-php-fpm-release -f dockerfiles/Dockerfile_73_fpm_release ../..

start_env:
	docker-compose up -d
	@echo "Environment started: might take a few seconds!"

clear:
	@rm ./callgrind-files/callgrind.*

request: request_notracer request_master request_head request_release
	@echo "Done!"

request_notracer:
	@echo "Issuing request to testing env: notracer"
	@curl localhost:8886
	@echo "\n------------"

request_master:
	@echo "Issuing request to testing env: master"
	@curl localhost:8887
	@echo "\n------------"

request_head:
	@echo "Issuing request to testing env: head"
	@curl localhost:8888
	@echo "\n------------"

request_release:
	@echo "Issuing request to testing env: release"
	@curl localhost:8889
	@echo "\n------------"
