EXEC_REPETITIONS := 30

build: 73_notracer 73_master 73_head 73_release
	@echo "Build completed!"

# nginx:
# 	@docker build -t overhead-nginx -f dockerfiles/Dockerfile_nginx ../..

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

shell_%:
	docker-compose exec php-fpm-$* bash

down:
	docker-compose down -v

clear:
	@rm -f ./callgrind-files/callgrind.*

request_l57: request_l57_notracer request_l57_master request_l57_head request_l57_release
	@echo "Done Laravel 5.7!"

request_synthetic: request_synthetic_notracer request_synthetic_master request_synthetic_head request_synthetic_release
	@echo "Done Synthetic request!"

request_hook: request_hook_notracer request_hook_master request_hook_head request_hook_release
	@echo "Done Synthetic request!"

clear_cache_%:
	@docker-compose exec php-fpm-$* sh -c 'rm -rf /tmp/php-cache/*'

request_l57_%:
	@echo "Laravel 5.7 request: $*"
	@docker-compose exec php-fpm-$* sh -c 'SCRIPT_FILENAME=/var/www/public/index.php REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000'
	@echo "\n------------"

time_l57_%:
	@echo "Benchmarking time for Laravel 5.7 app: $*"
	@docker-compose exec php-fpm-$* sh -c 'SCRIPT_FILENAME=/var/www/public/index.php REQUEST_METHOD=GET multitime -n ${EXEC_REPETITIONS} -s 0 cgi-fcgi -bind -connect 127.0.0.1:9000'
	@echo "\n------------"

request_synthetic_%:
	@echo "Synthetic request: $*"
	@docker-compose exec php-fpm-$* sh -c 'DD_TRACE_CLI_ENABLED=true DD_TRACE_NO_AUTOLOADER=true php /var/www/public/synthetic.php'
	@echo "\n------------"

request_hook_%:
	@echo "Request init hook: $*"
	@docker-compose exec php-fpm-$* sh -c 'DD_TRACE_CLI_ENABLED=true DD_TRACE_NO_AUTOLOADER=true php -dddtrace.request_init_hook="" /var/www/public/inithook.php'
	@echo "\n------------"

time_hook_notracer:
	@echo "Benchmarking time for script only: notracer"
	@docker-compose exec php-fpm-notracer sh -c 'multitime -n ${EXEC_REPETITIONS} -s 0 php /var/www/public/synthetic.php'
	@echo "\n------------"

time_hook_%:
	@echo "Benchmarking time for init hook only: $*"
	@docker-compose exec php-fpm-$* sh -c 'DD_TRACE_CLI_ENABLED=true DD_TRACE_NO_AUTOLOADER=true multitime -n ${EXEC_REPETITIONS} -s 0 php -dddtrace.request_init_hook="" /var/www/public/inithook.php'
	@echo "\n------------"
