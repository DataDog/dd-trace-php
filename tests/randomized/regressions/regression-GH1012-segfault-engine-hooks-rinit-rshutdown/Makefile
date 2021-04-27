RESULTS_DIR := ../.results
DURATION := 30s
SCENARIO := regression-GH1012-segfault-engine-hooks-rinit-rshutdown

run: prepare_results_folders
	@DURATION=$(DURATION) docker-compose up php-test
	@docker-compose down

shell: prepare_results_folders
	@docker-compose run --rm php-test bash
	@docker-compose down

prepare_results_folders:
	@rm -rf $(RESULTS_DIR)/$(SCENARIO)
	@mkdir -p $(RESULTS_DIR)/$(SCENARIO)
	@chmod -R a+w $(RESULTS_DIR)/$(SCENARIO)

# keep this line to avoid whitespace errors with generated files
