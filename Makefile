# make fetch      download new e-mails into inbox/ (fetchmail.py)
# make populate   let Claude Code turn new e-mails and the readme's sources into events/
# make deploy     upload the site (deploy.sh)
#
# populate.md holds Claude's instructions. Mails that have been handed to Claude are
# listed in inbox/.processed, so each mail is only read once.

.PHONY: fetch populate deploy

# bash for pipefail: populate pipes claude through jq but must still see claude's exit code
SHELL := /bin/bash

# Claude may only edit files, check JSON, and fetch pages from the domains in the
# readme's "Sources to check", so a mail or web page can't send it elsewhere.
SOURCE_DOMAINS := $(shell sed -n '/Sources to check$$/,/Downloading incoming e-mails$$/p' readme.md | grep -oE 'https?://[^/ ]+' | sed -E 's,https?://,,' | sort -u)
CLAUDE_TOOLS := Read Glob Grep Edit Write "Bash(python3 -m json.tool:*)" $(foreach d,$(SOURCE_DOMAINS),"WebFetch(domain:$(d))")

# claude -p is silent until it finishes; stream its steps instead: each tool call, then its text
SHOW_PROGRESS := jq -r --unbuffered 'select(.type == "assistant") | .message.content[] \
	| if .type == "tool_use" then "  → \(.name) \(.input.file_path // .input.url // .input.pattern // .input.command // "")" \
	  elif .type == "text" then .text else empty end'

fetch:
	python3 fetchmail.py

populate:
	@set -o pipefail; touch inbox/.processed; \
	new=$$(for d in inbox/*/; do \
		[ -d "$$d" ] || continue; d=$${d%/}; \
		grep -qxF "$${d#inbox/}" inbox/.processed || echo "$$d"; \
	done); \
	if [ -n "$$new" ]; then echo "New mails:"; echo "$$new" | sed 's/^/  /'; else echo "No new mails; checking the sources in readme.md only."; fi; \
	claude -p "$$(cat populate.md)$$(printf '\n\nNew e-mails:\n%s\n' "$${new:-(none)}")" \
		--permission-mode acceptEdits --output-format stream-json --verbose --allowedTools $(CLAUDE_TOOLS) \
		| $(SHOW_PROGRESS) \
	&& { [ -z "$$new" ] || echo "$$new" | sed 's#^inbox/##' >> inbox/.processed; } \
	&& echo "Done. Check with ./dev.sh, then: make deploy"

deploy:
	./deploy.sh
