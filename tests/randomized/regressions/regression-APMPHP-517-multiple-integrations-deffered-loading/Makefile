RESULTS_DIR := ../.results
SCENARIO := regression-APMPHP-517-multiple-integrations-deffered-loading

run: prepare_results_folders
	@docker-compose up php-test
	@docker-compose down

prepare_results_folders:
	@rm -rf $(RESULTS_DIR)/$(SCENARIO)
	@mkdir -p $(RESULTS_DIR)/$(SCENARIO)
	@chmod -R a+w $(RESULTS_DIR)/$(SCENARIO)
