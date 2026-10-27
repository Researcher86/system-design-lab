.PHONY: up down build shell htop install test analyse format format-check check \
        experiment experiment-debug scenario benchmark benchmark-debug

# CI has no terminal to attach to (GitHub Actions sets CI=true), so exec runs
# without one there; locally it keeps the TTY for colours and interactive use.
TTY := $(if $(CI),-T,)

up:
	docker compose up -d

down:
	docker compose down

build:
	docker compose build

shell: up
	docker compose exec $(TTY) php bash

htop: up
	docker compose exec $(TTY) php htop

install: up
	docker compose exec $(TTY) php composer install

test: up
	docker compose exec $(TTY) php composer test

analyse: up
	docker compose exec $(TTY) php composer analyse

format: up
	docker compose exec $(TTY) php composer format

format-check: up
	docker compose exec $(TTY) php composer format:check

check: test analyse format-check

experiment: up
	docker compose exec $(TTY) php php bin/experiment $(ARGS)

# The MVP integration scenario has a stable name; ARGS can still override its
# sample size or seed.
scenario: up
	docker compose exec $(TTY) php php bin/experiment scenario:short-link-platform $(ARGS)

benchmark: up
	docker compose exec $(TTY) php php bin/benchmark $(ARGS)

# Xdebug is installed with mode=off, so a debug run has to switch the mode as
# well as raise the trigger. Point the IDE at port 9003 first; without a
# listener the connection attempt times out and the run continues.
experiment-debug: up
	docker compose exec $(TTY) php bash -c "XDEBUG_MODE=debug XDEBUG_TRIGGER=1 php bin/experiment $(ARGS)"

benchmark-debug: up
	docker compose exec $(TTY) php bash -c "XDEBUG_MODE=debug XDEBUG_TRIGGER=1 php bin/benchmark $(ARGS)"
