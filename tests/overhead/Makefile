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

request_all:
	@echo "no tracer"
	@curl localhost:8886
	@echo ""
	@echo "master"
	@curl localhost:8887
	@echo ""
	@echo "head"
	@curl localhost:8888
	@echo ""
	@echo "release"
	@curl localhost:8889
	@echo ""
