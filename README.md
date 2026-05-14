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
		'query'            => 'markets reacting to economic uncertainty',
		'limit'            => 10,
		'mode'             => 'hybrid',
		'include_debug'    => false,
		'collapse_by_post' => false,
	]
);
```

`mode` accepts `dense`, `sparse`, or `hybrid`. By default, `Search::run()` returns chunk level rows so consumers can build precise context and debug ranking. Set `collapse_by_post` to `true` when a consumer needs at most one result per post.

Related posts use stored source vectors and do not re-embed the source post:

```php
$related = \WPVDB_Search\Search::related_to_post(
	123,
	5,
	[
		'collapse_by_post' => true,
	]
);
```

## Abilities API

When the WordPress Abilities API is available, this plugin registers `wpvdb/semantic-search`. The ability is read only, requires the current user to have `read` by default, and is marked `meta.mcp.public` so MCP Adapter can discover it.

```php
$ability = wp_get_ability( 'wpvdb/semantic-search' );
$result  = $ability->execute(
	[
		'query'            => 'markets reacting to economic uncertainty',
		'limit'            => 5,
		'mode'             => 'dense',
		'collapse_by_post' => true,
	]
);
```

The Abilities REST route is:

```text
GET /wp-json/wp-abilities/v1/abilities/wpvdb%2Fsemantic-search/run?input[query]=markets&input[limit]=5&input[mode]=dense
```

The Abilities REST controller reads execution parameters from the `input` query parameter for read only abilities. `mode` accepts `dense`, `sparse`, or `hybrid`; the ability defaults to `dense` because hybrid runs both retrieval paths. `limit` is capped at 20. The ability defaults to one result per post; set `collapse_by_post` to `false` to return chunk level rows. Results include post IDs, titles, canonical URLs, publication dates, bounded chunk excerpts, score metadata, matched chunk counts, and source modes.

Sites that need a stricter audience can filter `wpvdb_search_ability_capability`, for example to require `edit_posts`.

## Development

Install dependencies with Composer, then use the scripts declared in `composer.json` for lint and fix tasks.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
