
.PHONY: build build-enterprise build-customer deploy deploy-check

# Free / community build: no vendor key, self-signed reports only.
build:
	bash scripts/build.sh

# Team / Enterprise build: embeds the vendor public key so licences verify.
build-enterprise:
	@test -f keys/vendor-public-key.pem || { echo "Missing keys/vendor-public-key.pem" >&2; exit 1; }
	@cp keys/vendor-public-key.pem payload/vendor-public-key.pem
	@bash scripts/build.sh
	@rm -f payload/vendor-public-key.pem
	@echo "Enterprise build complete (licence verification enabled)."

# Customer build: embeds the vendor public key AND a specific customer licence
# so no licence needs to be supplied at boot.
# Usage: make build-customer CUSTOMER="Acme ITAD Ltd" LIC=/path/to/Acme.lic
build-customer:
	@test -f keys/vendor-public-key.pem || { echo "Missing keys/vendor-public-key.pem" >&2; exit 1; }
	@test -n "$(LIC)" || { echo "LIC (path to .lic) required: make build-customer CUSTOMER=\"Acme ITAD Ltd\" LIC=/path/to/Acme.lic" >&2; exit 1; }
	@test -f "$(LIC)" || { echo "Missing licence file: $(LIC)" >&2; exit 1; }
	@trap 'rm -f payload/vendor-public-key.pem payload/license.lic' EXIT; \
	  cp keys/vendor-public-key.pem payload/vendor-public-key.pem; \
	  cp "$(LIC)" payload/license.lic; \
	  bash scripts/build.sh
	@echo "Customer build complete (licence embedded)."

deploy: build
	bash scripts/deploy.sh

deploy-check: build
	bash scripts/deploy.sh --check
