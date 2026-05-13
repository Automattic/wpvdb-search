# WPVDB Search

WPVDB Search provides shared dense, sparse, and hybrid search primitives for content indexed by [`wpvdb`](https://github.com/rbcorrales/wpvdb).

It is the canonical PHP search service used by [`wpvdb-smart-search`](https://github.com/rbcorrales/wpvdb-smart-search) and future consumers such as WordPress Abilities, MCP clients, related-post surfaces, and WordPress query integrations.

## Requirements

- WordPress with [`wpvdb`](https://github.com/rbcorrales/wpvdb) installed and configured.
- PHP 7.4 or newer.
- MariaDB with native vector support for dense search.
- Composer for PHP development tooling.

## What This Plugin Owns

- `WPVDB_Search\Search::run( array $args )`.
- Dense vector search over wpvdb embeddings.
- Sparse MariaDB FULLTEXT search over wpvdb chunks.
- Hybrid reciprocal rank fusion over dense and sparse result sets.
- The additive FULLTEXT index used by sparse and hybrid search.

## Public API

```php
$result = \WPVDB_Search\Search::run(
	[
		'query'         => 'markets reacting to economic uncertainty',
		'limit'         => 10,
		'mode'          => 'hybrid',
		'include_debug' => false,
	]
);
```

`mode` accepts `dense`, `sparse`, or `hybrid`.

## Development

Install dependencies with Composer, then use the scripts declared in `composer.json` for lint and fix tasks.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
