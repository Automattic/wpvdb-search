# AGENTS.md - wpvdb-search

Agent guidance for this repository. Keep public documentation in `README.md`; keep this file focused on implementation rules.

## Boundaries

- Do not add credentials, tokens, application passwords, site-specific hostnames, or private deployment details.
- This plugin owns shared PHP search primitives for wpvdb consumers.
- Keep UI, demo page rendering, public Smart Search REST routing, example prompts, and browser assets in `https://github.com/rbcorrales/wpvdb-smart-search`.
- Do not add ingestion, queueing, provider settings, or embedding storage ownership here. Those belong in `https://github.com/rbcorrales/wpvdb`.

## Runtime Contracts

- The plugin slug is `wpvdb-search`.
- The PHP namespace is `WPVDB_Search`.
- The public API entrypoint is `WPVDB_Search\Search::run( array $args )`.
- Sparse mode should degrade to an empty result set if the FULLTEXT index is unavailable or the query has no usable terms.

## Development Notes

- Build and lint commands are defined in `composer.json`; prefer those scripts.
- PHP 7.4 compatibility is required.
- Keep result projection explicit. Consumers should ask for fields instead of receiving UI-only payloads by default.
