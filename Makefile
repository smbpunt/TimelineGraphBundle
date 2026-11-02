SHELL := /bin/bash
BIN   := vendor/bin

.DEFAULT_GOAL := help

.PHONY: help
help: ## List available targets
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

.PHONY: test
test: ## Run the PHPUnit suite
	@$(BIN)/phpunit

.PHONY: cs
cs: ## Check code style (php-cs-fixer, dry-run)
	@$(BIN)/php-cs-fixer check --diff

.PHONY: cs-fix
cs-fix: ## Fix code style (php-cs-fixer)
	@$(BIN)/php-cs-fixer fix

.PHONY: stan
stan: ## Static analysis (phpstan)
	@$(BIN)/phpstan analyse

.PHONY: rector
rector: ## Rector refactorings (dry-run)
	@$(BIN)/rector process --dry-run

.PHONY: rector-fix
rector-fix: ## Apply rector refactorings
	@$(BIN)/rector process

.PHONY: qa
qa: cs stan rector test ## Run all checks (cs + stan + rector + phpunit)
