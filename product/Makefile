
.PHONY: build build-slim deploy deploy-check

# Free / community build: embeds the vendor public key so licences verify
# (tScrub always requires a licence — even the free tier).
build:
	@test -f keys/vendor-public-key.pem || { echo "Missing keys/vendor-public-key.pem" >&2; exit 1; }
	@cp keys/vendor-public-key.pem payload/vendor-public-key.pem
	@bash scripts/build.sh
	@rm -f payload/vendor-public-key.pem
	@echo "Build complete (licence verification enabled)."

# Appliance (slim) build: no sedutil payload — the appliance image provides
# sedutil-cli via BR2_PACKAGE_SEDUTIL. Used for the bootable ISO only.
build-slim:
	@test -f keys/vendor-public-key.pem || { echo "Missing keys/vendor-public-key.pem" >&2; exit 1; }
	@cp keys/vendor-public-key.pem payload/vendor-public-key.pem
	@SKIP_SEDUTIL_PAYLOAD=1 bash scripts/build.sh
	@rm -f payload/vendor-public-key.pem
	@echo "Appliance (slim) build complete (sedutil provided by the image)."

deploy: build
	bash scripts/deploy.sh

deploy-check: build
	bash scripts/deploy.sh --check
