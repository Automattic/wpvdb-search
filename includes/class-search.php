<?php
/**
 * Shared dense, sparse, and hybrid search service.
 *
 * @package WPVDB_Search
 */

namespace WPVDB_Search;

defined( 'ABSPATH' ) || exit;

/**
 * Search runner.
 */
class Search {
	const MODES     = [ 'hybrid', 'dense', 'sparse' ];
	const RRF_K     = 60;
	const MAX_LIMIT = 20;
	const MAX_QUERY = 500;

	/**
	 * Run a search.
	 *
	 * @param array $args Search args.
	 * @return array|\WP_Error Search payload or error.
	 */
	public static function run( array $args ) {
		$t_start = microtime( true );
		$args    = self::normalize_args( $args );
		if ( is_wp_error( $args ) ) {
			return $args;
		}

		if ( ! class_exists( '\WPVDB\Core' ) || ! class_exists( '\WPVDB\Settings' ) ) {
			return new \WP_Error( 'wpvdb_missing', __( 'wpvdb plugin classes not available.', 'wpvdb-search' ), [ 'status' => 500 ] );
		}

		global $wpdb;
		$table = Schema::table();

		$limit = (int) $args['limit'];
		$mode  = (string) $args['mode'];
		$query = (string) $args['query'];

		$pool = max( $limit * 3, 30 );

		$dense_ms       = 0;
		$dense_embed_ms = 0;
		$dense_db_ms    = 0;
		$sparse_ms      = 0;
		$dense_rows     = [];
		$sparse_rows    = [];

		if ( 'dense' === $mode || 'hybrid' === $mode ) {
			$t              = microtime( true );
			$dense_timings  = [];
			$dense_rows     = self::dense_query( $query, $pool, $args, $dense_timings );
			$dense_ms       = (int) round( ( microtime( true ) - $t ) * 1000 );
			$dense_embed_ms = (int) ( $dense_timings['embed_ms'] ?? 0 );
			$dense_db_ms    = (int) ( $dense_timings['db_ms'] ?? 0 );
			if ( is_wp_error( $dense_rows ) ) {
				return $dense_rows;
			}
		}

		if ( 'sparse' === $mode || 'hybrid' === $mode ) {
			$t           = microtime( true );
			$sparse_rows = self::sparse_query( $query, $pool, $args );
			$sparse_ms   = (int) round( ( microtime( true ) - $t ) * 1000 );
			if ( is_wp_error( $sparse_rows ) ) {
				return $sparse_rows;
			}
		}

		$merged   = self::merge( $dense_rows, $sparse_rows, $mode, $limit );
		$enriched = self::enrich( $merged, $args );

		$response = [
			'mode'    => $mode,
			'query'   => $query,
			'limit'   => $limit,
			'results' => self::project_results( $enriched, $args['fields'] ),
		];

		if ( ! empty( $args['include_debug'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted table name, no caching needed for a live count.
			$total_vectors     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
			$response['debug'] = [
				'total_vectors'  => $total_vectors,
				'elapsed_ms'     => (int) round( ( microtime( true ) - $t_start ) * 1000 ),
				'dense_ms'       => $dense_ms,
				'dense_embed_ms' => $dense_embed_ms,
				'dense_db_ms'    => $dense_db_ms,
				'sparse_ms'      => $sparse_ms,
				'dense_count'    => count( is_array( $dense_rows ) ? $dense_rows : [] ),
				'sparse_count'   => count( is_array( $sparse_rows ) ? $sparse_rows : [] ),
				'fulltext_ready' => Schema::has_fulltext_index(),
			];
		}

		return $response;
	}

	/**
	 * Compatibility wrapper for the Smart Search REST response.
	 *
	 * @param string $query Query text.
	 * @param int    $limit Max results to return.
	 * @param string $mode  One of hybrid, dense, or sparse.
	 * @return array|\WP_Error
	 */
	public static function query( $query, $limit = 10, $mode = 'hybrid' ) {
		$result = self::run(
			[
				'query'         => $query,
				'limit'         => $limit,
				'mode'          => $mode,
				'include_debug' => true,
			]
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$debug = isset( $result['debug'] ) && is_array( $result['debug'] ) ? $result['debug'] : [];
		unset( $result['debug'] );

		return array_merge(
			[
				'mode'  => $result['mode'],
				'query' => $result['query'],
				'limit' => $result['limit'],
			],
			$debug,
			[
				'results' => $result['results'],
			]
		);
	}

	/**
	 * Normalize and validate search args.
	 *
	 * @param array $args Raw args.
	 * @return array|\WP_Error
	 */
	private static function normalize_args( array $args ) {
		$query = isset( $args['query'] ) ? trim( (string) $args['query'] ) : '';
		if ( '' === $query ) {
			return new \WP_Error( 'empty_query', __( 'Query text is required.', 'wpvdb-search' ), [ 'status' => 400 ] );
		}
		if ( strlen( $query ) > self::MAX_QUERY ) {
			return new \WP_Error(
				'query_too_long',
				sprintf(
					/* translators: %d: maximum number of characters allowed in a query. */
					__( 'Query too long (max %d chars).', 'wpvdb-search' ),
					self::MAX_QUERY
				),
				[ 'status' => 400 ]
			);
		}

		$limit       = isset( $args['limit'] ) ? (int) $args['limit'] : 10;
		$mode        = isset( $args['mode'] ) ? (string) $args['mode'] : 'hybrid';
		$post_type   = isset( $args['post_type'] ) ? (array) $args['post_type'] : [ 'any' ];
		$post_status = isset( $args['post_status'] ) ? (array) $args['post_status'] : [ 'publish' ];
		$fields      = isset( $args['fields'] ) ? (array) $args['fields'] : [];

		$post_type = array_values( array_filter( array_map( 'sanitize_key', $post_type ) ) );
		if ( empty( $post_type ) ) {
			$post_type = [ 'any' ];
		}

		return [
			'query'         => $query,
			'limit'         => max( 1, min( self::MAX_LIMIT, $limit ) ),
			'mode'          => in_array( $mode, self::MODES, true ) ? $mode : 'hybrid',
			'post_type'     => $post_type,
			'post_status'   => array_values( array_filter( array_map( 'sanitize_key', $post_status ) ) ),
			'fields'        => array_values( array_filter( array_map( 'sanitize_key', $fields ) ) ),
			'include_debug' => ! empty( $args['include_debug'] ),
		];
	}

	/**
	 * Dense vector query via MariaDB VEC_DISTANCE_COSINE.
	 *
	 * @param string $query   Query text.
	 * @param int    $limit   Pool size.
	 * @param array  $args    Normalized args.
	 * @param array  $timings Timing measurements populated by reference.
	 * @return array|\WP_Error
	 */
	private static function dense_query( $query, $limit, $args, &$timings = [] ) {
		$timings = [
			'embed_ms' => 0,
			'db_ms'    => 0,
		];

		$provider = \WPVDB\Settings::get_active_provider();
		if ( empty( $provider ) ) {
			$provider = 'openai';
		}
		$model    = \WPVDB\Settings::get_default_model();
		$api_key  = \WPVDB\Settings::get_api_key_for_provider( $provider );
		$api_base = \WPVDB\Settings::get_api_base_for_provider( $provider );

		if ( empty( $api_key ) || empty( $api_base ) ) {
			return new \WP_Error( 'not_configured', __( 'Embedding provider is not configured.', 'wpvdb-search' ), [ 'status' => 500 ] );
		}

		$t_embed             = microtime( true );
		$embedding           = \WPVDB\Core::get_embedding( $query, $model, $api_base, $api_key );
		$timings['embed_ms'] = (int) round( ( microtime( true ) - $t_embed ) * 1000 );
		if ( is_wp_error( $embedding ) ) {
			return $embedding;
		}
		if ( ! self::is_valid_embedding( $embedding ) ) {
			return new \WP_Error( 'bad_embedding', __( 'Embedding provider returned an invalid vector.', 'wpvdb-search' ), [ 'status' => 500 ] );
		}

		global $wpdb;
		$table = Schema::table();
		$json  = wp_json_encode( $embedding );
		$vf    = "VEC_FromText('" . esc_sql( $json ) . "')";
		$df    = "VEC_DISTANCE_COSINE(embedding, $vf)";
		$where = self::doc_type_where_sql( $args['post_type'] );

		$sql = "SELECT id, doc_id, chunk_id, chunk_content, summary, $df as distance
		        FROM {$table}
		        {$where}
		        ORDER BY distance
		        LIMIT " . (int) $limit;

		$t_db = microtime( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Query is assembled from trusted constants and esc_sql'd JSON; vector calls cannot be passed through prepare().
		$rows             = $wpdb->get_results( $sql, ARRAY_A );
		$timings['db_ms'] = (int) round( ( microtime( true ) - $t_db ) * 1000 );
		if ( $wpdb->last_error ) {
			return new \WP_Error( 'db_error', $wpdb->last_error, [ 'status' => 500 ] );
		}
		return empty( $rows ) ? [] : $rows;
	}

	/**
	 * Sparse FULLTEXT natural language query.
	 *
	 * @param string $query Query text.
	 * @param int    $limit Pool size.
	 * @param array  $args  Normalized args.
	 * @return array
	 */
	private static function sparse_query( $query, $limit, $args ) {
		if ( ! Schema::has_fulltext_index() ) {
			return [];
		}

		global $wpdb;
		$table          = Schema::table();
		$doc_type_where = self::doc_type_where_sql( $args['post_type'], 'AND' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is trusted; user input is bound via prepare().
		$sql = $wpdb->prepare(
			"SELECT id, doc_id, chunk_id, chunk_content, summary,
			        MATCH(chunk_content) AGAINST (%s IN NATURAL LANGUAGE MODE) as score
			 FROM {$table}
			 WHERE MATCH(chunk_content) AGAINST (%s IN NATURAL LANGUAGE MODE)
			 {$doc_type_where}
			 ORDER BY score DESC
			 LIMIT %d",
			$query,
			$query,
			(int) $limit
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $sql is prepared above.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return empty( $rows ) ? [] : $rows;
	}

	/**
	 * Build an optional doc_type predicate from normalized post types.
	 *
	 * @param array  $post_types Normalized post types.
	 * @param string $prefix     SQL prefix when a predicate exists.
	 * @return string
	 */
	private static function doc_type_where_sql( $post_types, $prefix = 'WHERE' ) {
		if ( in_array( 'any', $post_types, true ) ) {
			return '';
		}

		$quoted = [];
		foreach ( $post_types as $post_type ) {
			$quoted[] = "'" . esc_sql( $post_type ) . "'";
		}

		if ( empty( $quoted ) ) {
			return '';
		}

		return $prefix . ' doc_type IN (' . implode( ',', $quoted ) . ')';
	}

	/**
	 * Reciprocal Rank Fusion merge.
	 *
	 * @param array  $dense_rows  Dense result rows.
	 * @param array  $sparse_rows Sparse result rows.
	 * @param string $mode        Search mode.
	 * @param int    $limit       Final result count.
	 * @return array
	 */
	private static function merge( $dense_rows, $sparse_rows, $mode, $limit ) {
		if ( 'dense' === $mode ) {
			return array_slice( self::normalize_dense_only( $dense_rows ), 0, $limit );
		}
		if ( 'sparse' === $mode ) {
			return array_slice( self::normalize_sparse_only( $sparse_rows ), 0, $limit );
		}

		$k      = self::RRF_K;
		$merged = [];

		foreach ( $dense_rows as $rank => $row ) {
			$id            = (int) $row['id'];
			$merged[ $id ] = [
				'row'          => $row,
				'dense_rank'   => $rank + 1,
				'sparse_rank'  => null,
				'distance'     => isset( $row['distance'] ) ? (float) $row['distance'] : null,
				'sparse_score' => null,
				'sources'      => [ 'dense' ],
				'rrf'          => 1.0 / ( $k + $rank + 1 ),
			];
		}

		foreach ( $sparse_rows as $rank => $row ) {
			$id           = (int) $row['id'];
			$contribution = 1.0 / ( $k + $rank + 1 );
			if ( isset( $merged[ $id ] ) ) {
				$merged[ $id ]['sparse_rank']  = $rank + 1;
				$merged[ $id ]['sparse_score'] = (float) $row['score'];
				$merged[ $id ]['sources'][]    = 'sparse';
				$merged[ $id ]['rrf']         += $contribution;
			} else {
				$merged[ $id ] = [
					'row'          => $row,
					'dense_rank'   => null,
					'sparse_rank'  => $rank + 1,
					'distance'     => null,
					'sparse_score' => (float) $row['score'],
					'sources'      => [ 'sparse' ],
					'rrf'          => $contribution,
				];
			}
		}

		uasort(
			$merged,
			static function ( $a, $b ) {
				return $b['rrf'] <=> $a['rrf'];
			}
		);

		return array_slice( array_values( $merged ), 0, $limit );
	}

	/**
	 * Wrap dense rows in the same shape as fused results.
	 *
	 * @param array $rows Dense rows.
	 * @return array
	 */
	private static function normalize_dense_only( $rows ) {
		$out = [];
		foreach ( $rows as $rank => $row ) {
			$out[] = [
				'row'          => $row,
				'dense_rank'   => $rank + 1,
				'sparse_rank'  => null,
				'distance'     => isset( $row['distance'] ) ? (float) $row['distance'] : null,
				'sparse_score' => null,
				'sources'      => [ 'dense' ],
				'rrf'          => null,
			];
		}
		return $out;
	}

	/**
	 * Wrap sparse rows in the same shape as fused results.
	 *
	 * @param array $rows Sparse rows.
	 * @return array
	 */
	private static function normalize_sparse_only( $rows ) {
		$out = [];
		foreach ( $rows as $rank => $row ) {
			$out[] = [
				'row'          => $row,
				'dense_rank'   => null,
				'sparse_rank'  => $rank + 1,
				'distance'     => null,
				'sparse_score' => isset( $row['score'] ) ? (float) $row['score'] : null,
				'sources'      => [ 'sparse' ],
				'rrf'          => null,
			];
		}
		return $out;
	}

	/**
	 * Attach post metadata and decode HTML entities.
	 *
	 * @param array $merged Merged rows from self::merge().
	 * @param array $args   Normalized args.
	 * @return array
	 */
	private static function enrich( $merged, $args ) {
		if ( empty( $merged ) ) {
			return [];
		}

		$doc_ids = [];
		foreach ( $merged as $m ) {
			$doc_ids[] = (int) $m['row']['doc_id'];
		}
		$doc_ids = array_values( array_unique( array_filter( $doc_ids ) ) );

		$meta = [];
		if ( ! empty( $doc_ids ) ) {
			$posts = get_posts(
				[
					'include'                => $doc_ids,
					'post_type'              => in_array( 'any', $args['post_type'], true ) ? 'any' : $args['post_type'],
					'post_status'            => empty( $args['post_status'] ) ? [ 'publish' ] : $args['post_status'],
					'numberposts'            => count( $doc_ids ),
					'perm'                   => 'readable',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
					'update_post_meta_cache' => false,
					'orderby'                => 'post__in',
				]
			);
			foreach ( $posts as $p ) {
				$meta[ $p->ID ] = [
					'title' => html_entity_decode( wp_strip_all_tags( get_the_title( $p ) ), ENT_QUOTES, 'UTF-8' ),
					'link'  => get_permalink( $p ),
				];
			}
		}

		$out = [];
		foreach ( $merged as $m ) {
			$row = $m['row'];
			$pid = (int) $row['doc_id'];
			if ( ! isset( $meta[ $pid ] ) ) {
				continue;
			}
			$distance   = $m['distance'];
			$similarity = ( null !== $distance ) ? max( 0.0, 1.0 - $distance ) : null;
			$out[]      = [
				'post_id'       => $pid,
				'title'         => $meta[ $pid ]['title'],
				'link'          => $meta[ $pid ]['link'],
				'chunk_id'      => (int) $row['chunk_id'],
				'chunk_content' => html_entity_decode( (string) $row['chunk_content'], ENT_QUOTES, 'UTF-8' ),
				'summary'       => html_entity_decode( (string) ( $row['summary'] ?? '' ), ENT_QUOTES, 'UTF-8' ),
				'distance'      => null !== $distance ? (float) $distance : null,
				'similarity'    => null !== $similarity ? (float) $similarity : null,
				'sparse_score'  => null !== $m['sparse_score'] ? (float) $m['sparse_score'] : null,
				'dense_rank'    => $m['dense_rank'],
				'sparse_rank'   => $m['sparse_rank'],
				'rrf_score'     => null !== $m['rrf'] ? (float) $m['rrf'] : null,
				'sources'       => $m['sources'],
			];
		}
		return $out;
	}

	/**
	 * Validate a query embedding before composing vector SQL.
	 *
	 * @param mixed $embedding Embedding returned by wpvdb.
	 * @return bool
	 */
	private static function is_valid_embedding( $embedding ) {
		if ( ! is_array( $embedding ) || empty( $embedding ) ) {
			return false;
		}

		foreach ( $embedding as $value ) {
			if ( ! is_int( $value ) && ! is_float( $value ) ) {
				return false;
			}
			if ( ! is_finite( (float) $value ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Return only requested fields. Empty field list returns the full result.
	 *
	 * @param array $results Result rows.
	 * @param array $fields  Requested fields.
	 * @return array
	 */
	private static function project_results( $results, $fields ) {
		if ( empty( $fields ) ) {
			return $results;
		}

		$out = [];
		foreach ( $results as $row ) {
			$projected = [];
			foreach ( $fields as $field ) {
				if ( array_key_exists( $field, $row ) ) {
					$projected[ $field ] = $row[ $field ];
				}
			}
			$out[] = $projected;
		}
		return $out;
	}
}
