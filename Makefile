# Prefer PATH go (CI / system); fall back to local toolchain install.
GO ?= $(shell command -v go 2>/dev/null || echo $(HOME)/.local/go/bin/go)

.PHONY: deps build migrate preflight test health \
	siem-forward token-gen backup-drill bootstrap \
	tl-expire-licenses tl-dispatch-events \
	run-rbac run-tl run-manifest run-versioning run-realtime \
	run-warehouses run-products run-inventory run-wms \
	manifest-journey platform-ops-journey \
	server-manifest-journey server-platform-ops-journey server-journey \
	platform-init platform-up platform-down platform-logs platform-health platform-migrate platform-journey \
	install-maniforge verify-maniforge

# Server gateway (override: make server-journey GATEWAY=http://<customer-ip>:18090)
GATEWAY ?= http://127.0.0.1:18090

PLATFORM_COMPOSE = docker compose -f deploy/compose.platform.yml --env-file deploy/.env.platform

deps:
	$(GO) mod tidy

build:
	$(GO) build -o bin/maniforge-migrate ./cmd/migrate
	$(GO) build -o bin/maniforge-preflight ./cmd/preflight
	$(GO) build -o bin/maniforge-siem-forward ./cmd/siem-forward
	$(GO) build -o bin/maniforge-token-gen ./cmd/token-gen
	$(GO) build -o bin/maniforge-backup-drill ./cmd/backup-drill
	$(GO) build -o bin/maniforge-tl-expire-licenses ./cmd/tl-expire-licenses
	$(GO) build -o bin/maniforge-tl-dispatch-events ./cmd/tl-dispatch-events
	$(GO) build -o bin/maniforge-rbac ./cmd/rbac
	$(GO) build -o bin/maniforge-tenant-licensing ./cmd/tenant-licensing
	$(GO) build -o bin/maniforge-manifest-engine ./cmd/manifest-engine
	$(GO) build -o bin/maniforge-manifest-journey ./cmd/manifest-journey
	$(GO) build -o bin/maniforge-platform-ops-journey ./cmd/platform-ops-journey
	$(GO) build -o bin/maniforge-versioning ./cmd/versioning
	$(GO) build -o bin/maniforge-realtime ./cmd/realtime
	$(GO) build -o bin/maniforge-warehouses ./cmd/warehouses
	$(GO) build -o bin/maniforge-products ./cmd/products
	$(GO) build -o bin/maniforge-inventory ./cmd/inventory
	$(GO) build -o bin/maniforge-wms ./cmd/wms
	$(GO) build -o bin/maniforge-bootstrap ./cmd/bootstrap

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

# Demo admin for Desk/Admin. Requires MANIFORGE_ADMIN_LOGIN (phone) + MANIFORGE_ADMIN_PASSWORD.
# tenantId = t- + first 16 hex of SHA-256(random UUID). Password is never written to bootstrap.json.
bootstrap: build
	bash -c 'set -a && [ -f deploy/.env.platform ] && source deploy/.env.platform; set +a && ./bin/maniforge-bootstrap'

backup-drill: build
	./bin/maniforge-backup-drill

tl-expire-licenses: build
	./bin/maniforge-tl-expire-licenses

tl-dispatch-events: build
	./bin/maniforge-tl-dispatch-events

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

run-warehouses: build
	./bin/maniforge-warehouses

run-products: build
	./bin/maniforge-products

run-inventory: build
	./bin/maniforge-inventory

run-wms: build
	./bin/maniforge-wms

manifest-journey: build
	./bin/maniforge-manifest-journey

platform-ops-journey: build
	./bin/maniforge-platform-ops-journey

server-manifest-journey: build
	bash -c 'set -a && source deploy/.env.platform && set +a && ./bin/maniforge-manifest-journey'

server-platform-ops-journey: build
	bash -c 'set -a && source deploy/.env.platform && set +a && JOURNEY_BASE_URL=$(GATEWAY)/rbac JOURNEY_TL_URL=$(GATEWAY)/tenant-licensing ./bin/maniforge-platform-ops-journey'

server-journey: server-platform-ops-journey server-manifest-journey

test:
	$(GO) test ./...

health:
	curl -s http://127.0.0.1:8093/rbac/health | jq .
	curl -s http://127.0.0.1:8094/tenant-licensing/health | jq .
	curl -sf http://127.0.0.1:8098/warehouses/health | jq .
	curl -sf http://127.0.0.1:8099/products/health | jq .
	curl -sf http://127.0.0.1:8100/inventory/health | jq .
	curl -sf http://127.0.0.1:8101/wms/health | jq .
	curl -sf http://127.0.0.1:18090/warehouses/health | jq . || curl -sf http://127.0.0.1:8080/warehouses/health | jq .
	curl -sf http://127.0.0.1:18090/products/health | jq . || curl -sf http://127.0.0.1:8080/products/health | jq .
	curl -sf http://127.0.0.1:18090/inventory/health | jq . || curl -sf http://127.0.0.1:8080/inventory/health | jq .
	curl -sf http://127.0.0.1:18090/wms/health | jq . || curl -sf http://127.0.0.1:8080/wms/health | jq .

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
	@curl -sf http://127.0.0.1:8098/warehouses/health | jq . || echo "warehouses: down"
	@curl -sf http://127.0.0.1:8099/products/health | jq . || echo "products: down"
	@curl -sf http://127.0.0.1:8100/inventory/health | jq . || echo "inventory: down"
	@curl -sf http://127.0.0.1:8101/wms/health | jq . || echo "wms: down"
	@echo "=== gateway :8080 ==="
	@curl -sf http://127.0.0.1:8080/rbac/health | jq . || echo "gateway/rbac: down"
	@curl -sf http://127.0.0.1:8080/warehouses/health | jq . || echo "gateway/warehouses: down"
	@curl -sf http://127.0.0.1:8080/products/health | jq . || echo "gateway/products: down"
	@curl -sf http://127.0.0.1:8080/inventory/health | jq . || echo "gateway/inventory: down"
	@curl -sf http://127.0.0.1:8080/wms/health | jq . || echo "gateway/wms: down"

platform-journey: platform-health manifest-journey platform-ops-journey

# Production Box on customer server (pass args: make install-maniforge ARGS="--domain platform.example.com")
install-maniforge:
	sudo bash deploy/scripts/install-maniforge.sh $(ARGS)

verify-maniforge:
	bash deploy/scripts/verify-maniforge.sh
