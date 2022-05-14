.PHONY: all tests
.DEFAULT_GOAL:=help

bash: ## Opens a new bash inside a container.
	docker-compose run --rm heroku-api-client-cli bash

build: ## Rebuilds all containers.
	docker-compose build

composer-update: ## Runs composer update in a new container.
	docker-compose run --rm heroku-api-client-cli bash -c "COMPOSER_MEMORY_LIMIT=-1 composer update; exit $?"

down: ## Stops the local development services.
	docker-compose down

ps: ## Shows the active services in the development stack.
	docker-compose ps

start: ## Starts the local development services, must have been created before.
	docker-compose start

stop: ## Stops the local development services.
	docker-compose stop

up: ## Recreate and start the local development environment.
	docker-compose up -d

help:
	@printf "Usage:               make [\033[34mtarget\033[0m]\n"
	@printf "Default:             \033[34m%s\033[0m\n" $(.DEFAULT_GOAL)
	@printf "Targets:\n"
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf " \033[34m%-19s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)
