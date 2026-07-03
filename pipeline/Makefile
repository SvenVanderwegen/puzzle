# Burnfront content pipeline (WS-05). Run from the repo root as
#   make -C pipeline content DATE=20260706 SEEDS=path/to/seeds.json
PY := .venv/bin/python
DATE ?=
SEQ ?= 1
DAYS ?= 60
SEEDS ?= tests/fixtures/seeds-sample.json
KEY ?= tests/fixtures/dev-signing-key
OUT ?= dist/content

.PHONY: venv content test verify

venv:
	python3 -m venv .venv
	.venv/bin/pip install -r requirements.lock
	.venv/bin/pip install -e .

content:
	@test -n "$(DATE)" || { echo "usage: make content DATE=YYYYMMDD [SEQ=1] [DAYS=60] [SEEDS=...] [KEY=...]"; exit 2; }
	$(PY) -m burnfront_pipeline.cli emit --date $(DATE) --seq $(SEQ) \
		--days $(DAYS) --seeds $(SEEDS) --key $(KEY) --out $(OUT)

verify:
	@test -n "$(DIR)" || { echo "usage: make verify DIR=dist/content/vYYYYMMDD-1 [PUB=...]"; exit 2; }
	$(PY) -m burnfront_pipeline.cli verify --dir $(DIR) \
		--pubkey $(or $(PUB),tests/fixtures/dev-signing-key.pub)

test:
	.venv/bin/pytest
