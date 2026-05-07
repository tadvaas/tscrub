
.PHONY: build deploy deploy-check

build:
	bash scripts/build.sh

deploy: build
	bash scripts/deploy.sh

deploy-check: build
	bash scripts/deploy.sh --check
