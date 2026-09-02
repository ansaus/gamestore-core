DC := docker compose
EXEC := $(DC) exec -T app

.DEFAULT_GOAL := help
.PHONY: help up down build migrate fresh seed test race \
        scenario-timeout scenario-fallback scenario-oos scenario-race \
        reconcile bench logs shell stub-reset

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'

build: ## Build images
	$(DC) build

up: ## Start the whole stack + run migrations
	$(DC) up -d --build
	$(EXEC) php artisan migrate --force

down: ## Stop the stack and drop volumes
	$(DC) down -v

migrate: ## Run migrations
	$(EXEC) php artisan migrate --force

fresh: ## Drop everything and re-migrate + seed
	$(EXEC) php artisan migrate:fresh --force --seed

seed: ## Seed catalog + key pool (SCALE=big for the load dataset)
ifeq ($(SCALE),big)
	$(EXEC) php artisan db:seed --force --class=Database\\Seeders\\BigCatalogSeeder
else
	$(EXEC) php artisan db:seed --force
endif

test: ## Run the full test suite
	$(EXEC) php artisan test

# ---- Acceptance scenarios (section 10 of SPEC) ------------------------------

scenario-race: ## Scenario 1/1b: 50 parallel paid webhooks for one order
	$(EXEC) php scripts/race.php --n=$(or $(N),50) --mode=$(or $(MODE),distinct-events)

race: scenario-race ## alias

scenario-timeout: ## Scenario 4: supplier times out AFTER issuing a code
	$(EXEC) php scripts/scenario_timeout.php

scenario-fallback: ## Scenario 5: supplier A down -> fallback to B
	$(EXEC) php scripts/scenario_fallback.php

scenario-oos: ## Scenario 6: empty stock -> out_of_stock -> refill -> delivered
	$(EXEC) php scripts/scenario_oos.php

# ---- Ops ------------------------------------------------------------------

reconcile: ## Scenario 7: reconciliation report (ledger must balance)
	$(EXEC) php artisan reconcile:report

bench: ## Stage 5: EXPLAIN ANALYZE of the catalog query before/after
	$(EXEC) php scripts/bench.php

logs: ## Tail all container logs
	$(DC) logs -f --tail=100

shell: ## Shell into the app container
	$(DC) exec app bash

stub-reset: ## Reset the supplier stub state
	$(EXEC) php artisan stub:reset
