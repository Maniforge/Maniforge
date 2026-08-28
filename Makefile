# Prefer PATH go (CI / system); fall back to local toolchain install.
GO ?= $(shell command -v go 2>/dev/null || echo $(HOME)/.local/go/bin/go)

.PHONY: deps build migrate preflight siem-forward token-gen backup-drill enterprise-journey run-web frontend-install frontend-dev frontend-build scanner-install scanner-dev scanner-build frontend-all run-rbac run-tl test health racebench \
	rbac-security-journey rbac-admin-journey rbac-platform-ops-journey rbac-delegation-journey \
	platform-init platform-up platform-down platform-logs platform-health platform-migrate platform-journey \
	server-rbac-journey server-manifest-journey server-journey

# Server gateway (override: make server-journey GATEWAY=http://79.174.90.4:18090)
GATEWAY ?= http://127.0.0.1:18090

PLATFORM_COMPOSE = docker compose -f deploy/compose.platform.yml --env-file deploy/.env.platform

deps:
	$(GO) mod tidy

build:
	$(GO) build -o bin/maniforge-migrate ./cmd/migrate
	$(GO) build -o bin/maniforge-preflight ./cmd/preflight
	$(GO) build -o bin/maniforge-siem-forward ./cmd/siem-forward
	$(GO) build -o bin/maniforge-enterprise-journey ./cmd/enterprise-journey
	$(GO) build -o bin/maniforge-token-gen ./cmd/token-gen
	$(GO) build -o bin/maniforge-backup-drill ./cmd/backup-drill
	$(GO) build -o bin/maniforge-rbac ./cmd/rbac
	$(GO) build -o bin/maniforge-tenant-licensing ./cmd/tenant-licensing
	$(GO) build -o bin/maniforge-racebench ./cmd/racebench
	$(GO) build -o bin/maniforge-manifest-engine ./cmd/manifest-engine
	$(GO) build -o bin/maniforge-manifest-journey ./cmd/manifest-journey
	$(GO) build -o bin/maniforge-manifest-refine-gen ./cmd/manifest-refine-gen
	$(GO) build -o bin/maniforge-manifest-presets-seed ./cmd/manifest-presets-seed
	$(GO) build -o bin/maniforge-manifest-client-test-seed ./cmd/manifest-client-test-seed
	$(GO) build -o bin/maniforge-manifest-openapi-export ./cmd/manifest-openapi-export
	$(GO) build -o bin/maniforge-manifest-test ./cmd/manifest-test
	$(GO) build -o bin/maniforge-versioning ./cmd/versioning
	$(GO) build -o bin/maniforge-realtime ./cmd/realtime
	$(GO) build -o bin/maniforge-agency-demo-seed ./cmd/agency-demo-seed
	$(GO) build -o bin/maniforge-warehouses ./cmd/warehouses
	$(GO) build -o bin/maniforge-warehouses-journey ./cmd/warehouses-journey
	$(GO) build -o bin/maniforge-products ./cmd/products
	$(GO) build -o bin/maniforge-inventory ./cmd/inventory
	$(GO) build -o bin/maniforge-inventory-journey ./cmd/inventory-journey

racebench: build
	./bin/maniforge-racebench -workers 32 -duration 3s

pg-up:
	docker-compose up -d postgres || docker start maniforge-postgres

migrate: build
	./bin/maniforge-migrate

preflight: build
	./bin/maniforge-preflight

siem-forward: build
	./bin/maniforge-siem-forward

token-gen: build
	./bin/maniforge-token-gen

backup-drill: build
	./bin/maniforge-backup-drill

enterprise-journey: build
	./bin/maniforge-enterprise-journey

run-web:
	php -S 127.0.0.1:8092 -t public public/index.php

frontend-install:
	cd frontend/apps/admin && npm install

frontend-dev:
	cd frontend/apps/admin && npm run dev

frontend-build:
	cd frontend/apps/admin && npm run build

scanner-install:
	cd frontend/apps/scanner && npm install

scanner-dev:
	cd frontend/apps/scanner && npm run dev

scanner-build:
	cd frontend/apps/scanner && npm run build

frontend-all: frontend-build scanner-build

run-rbac: build
	./bin/maniforge-rbac

run-tl: build
	./bin/maniforge-tenant-licensing

run-manifest: build
	./bin/maniforge-manifest-engine

run-versioning: build
	./bin/maniforge-versioning

run-realtime: build
	./bin/maniforge-realtime

rbac-security-journey: build
	JOURNEY_BASE_URL=http://127.0.0.1:8093/rbac \
	php maniforge/rbac/tools/security_incident_journey.php

rbac-admin-journey: build
	JOURNEY_BASE_URL=http://127.0.0.1:8093/rbac \
	php maniforge/rbac/tools/rbac_admin_journey.php

rbac-platform-ops-journey: build
	JOURNEY_BASE_URL=http://127.0.0.1:8093/rbac \
	JOURNEY_TL_URL=http://127.0.0.1:8094/tenant-licensing \
	php maniforge/rbac/tools/platform_ops_journey.php

warehouses-journey: build
	./bin/maniforge-warehouses-journey

inventory-journey: build
	./bin/maniforge-inventory-journey

rbac-delegation-journey: build
	./bin/maniforge-agency-demo-seed
	JOURNEY_BASE_URL=http://127.0.0.1:8093/rbac \
	php maniforge/rbac/tools/agency_delegation_http_journey.php

rbac-journey: build
	NEW_USER_BASE_URL=http://127.0.0.1:8093/rbac \
	NEW_USER_TL_URL=http://127.0.0.1:8094/tenant-licensing \
	NEW_USER_VER_URL=http://127.0.0.1:8096/versioning \
	php maniforge/rbac/tools/new_user_http_journey.php

manifest-journey: build
	./bin/maniforge-manifest-journey

# nzgapp / production box — gateway + deploy/.env.platform (not dev loopback ports)
server-rbac-journey: build
	NEW_USER_BASE_URL=$(GATEWAY)/rbac \
	NEW_USER_TL_URL=$(GATEWAY)/tenant-licensing \
	NEW_USER_VER_URL=$(GATEWAY)/versioning \
	php maniforge/rbac/tools/new_user_http_journey.php

server-manifest-journey: build
	bash -c 'set -a && source deploy/.env.platform && set +a && ./bin/maniforge-manifest-journey'

server-journey: server-rbac-journey server-manifest-journey

manifest-refine-gen: build
	./bin/maniforge-manifest-refine-gen

manifest-presets-seed: build
	./bin/maniforge-manifest-presets-seed

manifest-client-test-seed: build
	./bin/maniforge-manifest-client-test-seed

manifest-openapi-export: build
	./bin/maniforge-manifest-openapi-export

manifest-openapi-export-live: build
	MANIFEST_OPENAPI_EXPORT_LIVE=1 MANIFEST_OPENAPI_EXPORT_ALL=1 ./bin/maniforge-manifest-openapi-export

manifest-custom-demo-seed: manifest-client-test-seed

manifest-test: build
	$(GO) test ./internal/manifestengine/... ./internal/versioning/...
	./bin/maniforge-manifest-test

manifest-test-all: build
	$(GO) test ./internal/manifestengine/... ./internal/versioning/...
	./bin/maniforge-manifest-journey
	./bin/maniforge-manifest-test

test:
	$(GO) test ./...

health:
	curl -s http://127.0.0.1:8093/rbac/health | jq .
	curl -s http://127.0.0.1:8094/tenant-licensing/health | jq .

platform-init:
	@test -f deploy/.env.platform || cp deploy/.env.platform.example deploy/.env.platform
	@echo "deploy/.env.platform ready"

platform-up: platform-init
	$(PLATFORM_COMPOSE) up -d --build

platform-down:
	$(PLATFORM_COMPOSE) down

platform-logs:
	$(PLATFORM_COMPOSE) logs -f --tail=100

platform-migrate: platform-init
	$(PLATFORM_COMPOSE) run --rm migrate

platform-health:
	@echo "=== direct services ==="
	@curl -sf http://127.0.0.1:8093/rbac/health | jq . || echo "rbac: down"
	@curl -sf http://127.0.0.1:8094/tenant-licensing/health | jq . || echo "tenant-licensing: down"
	@curl -sf http://127.0.0.1:8095/health | jq . || echo "manifest-engine: down"
	@curl -sf http://127.0.0.1:8096/versioning/health | jq . || echo "versioning: down"
	@curl -sf http://127.0.0.1:8097/health | jq . || echo "realtime: down"
	@echo "=== gateway :8080 ==="
	@curl -sf http://127.0.0.1:8080/rbac/health | jq . || echo "gateway/rbac: down"

platform-journey: platform-health rbac-journey manifest-journey
