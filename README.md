# WPVDB Search

[![Checks](https://github.com/rbcorrales/wpvdb-search/actions/workflows/ci.yml/badge.svg)](https://github.com/rbcorrales/wpvdb-search/actions/workflows/ci.yml)
[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-3858e9?logo=wordpress&logoColor=white)](#requirements)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777bb4?logo=php&logoColor=white)](#requirements)
[![License](https://img.shields.io/badge/License-GPLv2%2B-blue.svg)](LICENSE)

Shared dense, sparse, and hybrid search primitives for content indexed by [`wpvdb`](https://github.com/rbcorrales/wpvdb).

This plugin is the canonical PHP search service used by [`wpvdb-smart-search`](https://github.com/rbcorrales/wpvdb-smart-search), [`wpvdb-blocks`](https://github.com/rbcorrales/wpvdb-blocks), WordPress Abilities, MCP clients, and WordPress query integrations.

## Requirements

| Requirement | Version or notes |
|---|---|
| WordPress | 6.9 or newer |
| PHP | 8.3 or newer |
| [`wpvdb`](https://github.com/rbcorrales/wpvdb) | Installed and configured |
| MariaDB | Native `VECTOR` support for dense and hybrid search, plus `FULLTEXT` support for sparse and hybrid search |

## What this plugin owns

- `WPVDB_Search\Search::run( array $args )`.
- Dense vector search over `wpvdb` embeddings.
- Sparse MariaDB `FULLTEXT` search over wpvdb chunks.
- Hybrid reciprocal rank fusion over dense and sparse result sets.
- The additive `FULLTEXT` index used by sparse and hybrid search.

## Public API

### Search

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

Accepted parameters:

| Parameter | Type | Values | Default | Description |
|---|---|---|---|---|
| `query` | `string` | 1 to 500 characters | Required | Text to embed and search for. |
| `limit` | `int` | `1` to `20` | `10` | Maximum number of results to return. |
| `mode` | `string` | `dense`, `sparse`, `hybrid` | `hybrid` | Retrieval mode. |
| `post_type` | `string[]` | Any registered post type, or `any` | `any` | Post types to search. |
| `post_status` | `string[]` | Any readable post status | `publish` | Post statuses to search. |
| `model` | `string` | Any configured wpvdb embedding model | Default wpvdb model | Embedding model to query. |
| `fields` | `string[]` | Any result field key | All fields | Optional result field projection. |
| `include_debug` | `bool` | `true`, `false` | `false` | Adds timing and query metadata. |
| `collapse_by_post` | `bool` | `true`, `false` | `false` | Returns at most one result per post. |

By default, `Search::run()` returns chunk level rows, allowing consumers to build precise context and debug ranking. Set `collapse_by_post` to `true` when a consumer needs at most one result per post.

### Related posts

Related posts use stored source vectors and do not embed the source post again:

```php
$related = \WPVDB_Search\Search::related_to_post(
	123,
	5,
	[
		'collapse_by_post' => true,
	]
);
```

Accepted arguments:

| Argument | Type | Values | Default | Description |
|---|---|---|---|---|
| `$post_id` | `int` | Existing post ID | Required | Source post used to find related content. |
| `$limit` | `int` | `1` to `20` | `5` | Maximum number of related results to return. |
| `$args['doc_type']` | `string` | Any wpvdb document type | Source post type | Document type for source vectors. |
| `$args['model']` | `string` | Any configured wpvdb embedding model | Default wpvdb model | Embedding model to compare. |
| `$args['post_type']` | `string[]` | Any registered post type | Source document type | Post types to return. |
| `$args['post_status']` | `string[]` | Any readable post status | `publish` | Post statuses to return. |
| `$args['fields']` | `string[]` | Any result field key | All fields | Optional result field projection. |
| `$args['include_debug']` | `bool` | `true`, `false` | `false` | Adds timing and query metadata. |
| `$args['collapse_by_post']` | `bool` | `true`, `false` | `true` | Returns at most one result per post. |
| `$args['source_chunks']` | `int` | `1` to `10` | `3` | Number of source chunks used for comparison. |

## Abilities API

On WordPress 6.9 or newer, this plugin registers `wpvdb/semantic-search`. The ability is read-only, requires the current user to have `read` by default, and is marked `meta.mcp.public` so the MCP Adapter can discover it.

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

The slash in `wpvdb/semantic-search` is URL encoded as `%2F` in the REST path.

The Abilities REST controller reads execution parameters from the `input` query parameter for read-only abilities. `mode` accepts `dense`, `sparse`, or `hybrid`; the ability defaults to `dense` because hybrid runs both retrieval paths. `limit` is capped at 20. The default is one result per post; set `collapse_by_post` to `false` to return chunk-level rows. Results include post IDs, titles, canonical URLs, publication dates, bounded chunk excerpts, score metadata, matched chunk counts, and source modes.

Sites that need a stricter audience can filter `wpvdb_search_ability_capability`, for example, to require `edit_posts`.

## MCP Adapter

When the WordPress MCP Adapter is installed and active, `wpvdb/semantic-search` is exposed through the adapter because the ability is registered with `meta.mcp.public`.

MCP clients should discover available abilities, then execute `wpvdb/semantic-search` with the same input shape shown above.

## Development

Install development dependencies with Composer, then use the scripts declared in `composer.json` for lint, analysis, i18n preview, and fix tasks.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
