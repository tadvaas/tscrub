
.PHONY: build build-enterprise deploy deploy-check

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

deploy: build
	bash scripts/deploy.sh

deploy-check: build
	bash scripts/deploy.sh --check
