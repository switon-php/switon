NPM ?= npm
DOCS_HOST ?= 127.0.0.1
DOCS_PORT ?= 8501

.PHONY: help docs docs-en docs-dev docs-build docs-preview docs-check docs-links docs-install en release-check component-tests-clean component-tests-clean-preview component-tests-clean-all component-tests-clean-all-preview

help:
	@printf '%s\n' \
		'Switon shortcuts:' \
		'  make docs          Start the English VitePress docs dev server' \
		'  make docs en       Same as make docs; English is the default docs locale' \
		'  make docs-en       Start the English VitePress docs dev server' \
		'  make docs-dev      Start the English VitePress docs dev server' \
		'  make docs-build    Render the static docs site' \
		'  make docs-preview  Preview the rendered docs site' \
		'  make docs-check    Audit docs links and render the docs site' \
		'  make docs-links    Audit Markdown links in docs/en' \
		'  make release-check PKG=router [TEST_ARGS="--fast"]' \
		'  make component-tests-clean              Untracked only under packages/*/tests and packages/*/vendor' \
		'  make component-tests-clean-preview      Dry-run for component-tests-clean' \
		'  make component-tests-clean-all          Same paths + remove ignored (coverage, vendor, caches)' \
		'  make component-tests-clean-all-preview  Dry-run for component-tests-clean-all' \
		'' \
		'Options:' \
		'  DOCS_HOST=0.0.0.0  Override docs dev/preview host' \
		'  PKG=router         Package name for release-check' \
		'  TEST_ARGS="..."    Extra arguments passed to tests/run.sh' \
		'  DOCS_PORT=5174     Override docs dev/preview port'

docs: docs-dev

docs-en: docs-dev

en:
	@printf '%s\n' 'English docs are the default. Use `make docs` or `make docs-en`.'

docs-dev: node_modules/.bin/vitepress
	$(NPM) run docs:dev -- --host $(DOCS_HOST) --port $(DOCS_PORT)

docs-build: node_modules/.bin/vitepress
	$(NPM) run docs:build

docs-preview: docs-build
	$(NPM) run docs:preview -- --host $(DOCS_HOST) --port $(DOCS_PORT)

docs-check: docs-links docs-build

docs-links:
	python3 scripts/docs/markdown_link_audit.py --tree docs/en

release-check:
	@test -n "$(PKG)" || (printf '%s\n' 'Usage: make release-check PKG=router [TEST_ARGS="--fast"]' >&2; exit 1)
	bash build/release-check.sh "$(PKG)" $(TEST_ARGS)

component-tests-clean:
	bash scripts/component-tests-clean.sh

component-tests-clean-preview:
	bash scripts/component-tests-clean.sh --dry-run

component-tests-clean-all:
	bash scripts/component-tests-clean.sh --all-untracked

component-tests-clean-all-preview:
	bash scripts/component-tests-clean.sh --dry-run --all-untracked

docs-install:
	$(NPM) ci

node_modules/.bin/vitepress: package.json package-lock.json
	$(NPM) ci
