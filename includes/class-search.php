<?php
/**
 * Shared dense, sparse, and hybrid search service.
 *
 * @package WPVDB_Search
 */

declare(strict_types=1);

namespace WPVDB_Search;

defined( 'ABSPATH' ) || exit;

/**
 * Search runner.
 */
class Search {
	public const array MODES               = [ 'hybrid', 'dense', 'sparse' ];
	public const int RRF_K                 = 60;
	public const int MAX_LIMIT             = 20;
	public const int MAX_QUERY             = 500;
	public const int RELATED_SOURCE_CHUNKS = 3;

	/**
	 * Run a search.
	 *
	 * @param array $args Search args.
	 * @return array|\WP_Error Search payload or error.
	 */
	public static function run( array $args ): array|\WP_Error {
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

		$collapse_by_post = ! empty( $args['collapse_by_post'] );
		$pool             = $collapse_by_post ? max( $limit * 6, 60 ) : max( $limit * 3, 30 );

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

		$merge_limit = $collapse_by_post ? $pool : $limit;
		$merged      = self::merge( $dense_rows, $sparse_rows, $mode, $merge_limit );
		$enriched    = self::enrich( $merged, $args );
		if ( $collapse_by_post ) {
			$enriched = self::collapse_by_post( $enriched );
		}
		$enriched = array_slice( $enriched, 0, $limit );

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
	 * Find posts related to a source post by comparing stored source vectors.
	 *
	 * @param int   $post_id Source post ID.
	 * @param int   $limit   Max related posts to return.
	 * @param array $args    Optional search args.
	 * @return array|\WP_Error Related payload or error.
	 */
	public static function related_to_post( int $post_id, int $limit = 5, array $args = [] ): array|\WP_Error {
		$t_start = microtime( true );
		$args    = self::normalize_related_args( $post_id, $limit, $args );
		if ( is_wp_error( $args ) ) {
			return $args;
		}

		global $wpdb;
		$table = Schema::table();

		$source_ids = self::source_embedding_ids( $args );
		if ( is_wp_error( $source_ids ) ) {
			return $source_ids;
		}
		if ( empty( $source_ids ) ) {
			return [
				'mode'    => 'related',
				'post_id' => $args['post_id'],
				'limit'   => $args['limit'],
				'results' => [],
			];
		}

		$pool       = max( $args['limit'] * 6, 60 );
		$candidates = [];

		foreach ( $source_ids as $source_id ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is trusted; user input is bound via prepare().
			$sql = $wpdb->prepare(
				"SELECT e.id, e.doc_id, e.chunk_id, e.chunk_content, e.summary,
				        VEC_DISTANCE_COSINE(e.embedding, s.embedding) as distance
				 FROM {$table} e
				 INNER JOIN {$table} s ON s.id = %d
				 WHERE e.doc_id <> %d
				 AND e.doc_type = %s
				 AND e.model = %s
				 ORDER BY distance
				 LIMIT %d",
				(int) $source_id,
				$args['post_id'],
				$args['doc_type'],
				$args['model'],
				$pool
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $sql is prepared above; vector ordering must stay in SQL.
			$rows = $wpdb->get_results( $sql, ARRAY_A );
			if ( $wpdb->last_error ) {
				return new \WP_Error( 'db_error', $wpdb->last_error, [ 'status' => 500 ] );
			}

			foreach ( (array) $rows as $row ) {
				$id       = isset( $row['id'] ) ? (int) $row['id'] : 0;
				$distance = isset( $row['distance'] ) ? (float) $row['distance'] : null;
				if ( ! $id || null === $distance ) {
					continue;
				}
				if ( ! isset( $candidates[ $id ] ) || $distance < (float) $candidates[ $id ]['distance'] ) {
					$candidates[ $id ] = $row;
				}
			}
		}

		$candidates = array_values( $candidates );
		usort(
			$candidates,
			static fn ( array $a, array $b ): int => (float) $a['distance'] <=> (float) $b['distance']
		);

		$merged   = self::normalize_dense_only( array_slice( $candidates, 0, $pool ) );
		$enriched = self::enrich( $merged, $args );
		if ( ! empty( $args['collapse_by_post'] ) ) {
			$enriched = self::collapse_by_post( $enriched );
		}
		$enriched = array_slice( $enriched, 0, $args['limit'] );

		$response = [
			'mode'    => 'related',
			'post_id' => $args['post_id'],
			'limit'   => $args['limit'],
			'results' => self::project_results( $enriched, $args['fields'] ),
		];

		if ( ! empty( $args['include_debug'] ) ) {
			$response['debug'] = [
				'elapsed_ms'      => (int) round( ( microtime( true ) - $t_start ) * 1000 ),
				'source_chunks'   => count( $source_ids ),
				'candidate_count' => count( $candidates ),
			];
		}

		return $response;
	}

	/**
	 * Normalize and validate search args.
	 *
	 * @param array $args Raw args.
	 * @return array|\WP_Error
	 */
	private static function normalize_args( array $args ): array|\WP_Error {
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
			'query'            => $query,
			'limit'            => max( 1, min( self::MAX_LIMIT, $limit ) ),
			'mode'             => in_array( $mode, self::MODES, true ) ? $mode : 'hybrid',
			'post_type'        => $post_type,
			'post_status'      => array_values( array_filter( array_map( 'sanitize_key', $post_status ) ) ),
			'fields'           => array_values( array_filter( array_map( 'sanitize_key', $fields ) ) ),
			'include_debug'    => ! empty( $args['include_debug'] ),
			'collapse_by_post' => ! empty( $args['collapse_by_post'] ),
		];
	}

	/**
	 * Normalize and validate related-post args.
	 *
	 * @param int   $post_id Source post ID.
	 * @param int   $limit   Max result count.
	 * @param array $args    Raw args.
	 * @return array|\WP_Error
	 */
	private static function normalize_related_args( int $post_id, int $limit, array $args ): array|\WP_Error {
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return new \WP_Error( 'invalid_post', __( 'Source post was not found.', 'wpvdb-search' ), [ 'status' => 404 ] );
		}

		if ( ! class_exists( '\WPVDB\Settings' ) ) {
			return new \WP_Error( 'wpvdb_missing', __( 'wpvdb plugin classes not available.', 'wpvdb-search' ), [ 'status' => 500 ] );
		}

		$doc_type = isset( $args['doc_type'] ) ? sanitize_key( (string) $args['doc_type'] ) : '';
		if ( '' === $doc_type ) {
			$doc_type = get_post_type( $post_id );
		}
		if ( ! is_string( $doc_type ) || '' === $doc_type ) {
			$doc_type = 'post';
		}

		$model = isset( $args['model'] ) ? sanitize_text_field( (string) $args['model'] ) : '';
		if ( '' === $model ) {
			$model = \WPVDB\Settings::get_default_model();
		}
		if ( '' === $model ) {
			return new \WP_Error( 'model_missing', __( 'Embedding model is not configured.', 'wpvdb-search' ), [ 'status' => 500 ] );
		}

		$post_type   = isset( $args['post_type'] ) ? (array) $args['post_type'] : [ $doc_type ];
		$post_status = isset( $args['post_status'] ) ? (array) $args['post_status'] : [ 'publish' ];
		$fields      = isset( $args['fields'] ) ? (array) $args['fields'] : [];

		$post_type = array_values( array_filter( array_map( 'sanitize_key', $post_type ) ) );
		if ( empty( $post_type ) ) {
			$post_type = [ $doc_type ];
		}

		$source_chunks = isset( $args['source_chunks'] ) ? (int) $args['source_chunks'] : self::RELATED_SOURCE_CHUNKS;
		$source_chunks = (int) apply_filters( 'wpvdb_search_related_source_chunks', $source_chunks, $post_id, $args );

		return [
			'post_id'          => $post_id,
			'limit'            => max( 1, min( self::MAX_LIMIT, (int) $limit ) ),
			'doc_type'         => $doc_type,
			'model'            => $model,
			'post_type'        => $post_type,
			'post_status'      => array_values( array_filter( array_map( 'sanitize_key', $post_status ) ) ),
			'fields'           => array_values( array_filter( array_map( 'sanitize_key', $fields ) ) ),
			'include_debug'    => ! empty( $args['include_debug'] ),
			'collapse_by_post' => isset( $args['collapse_by_post'] ) ? ! empty( $args['collapse_by_post'] ) : true,
			'source_chunks'    => max( 1, min( 10, $source_chunks ) ),
		];
	}

	/**
	 * Fetch source embedding row IDs for related-post lookup.
	 *
	 * @param array $args Normalized related args.
	 * @return array|\WP_Error
	 */
	private static function source_embedding_ids( array $args ): array|\WP_Error {
		global $wpdb;
		$table = Schema::table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is trusted; user input is bound via prepare().
		$sql = $wpdb->prepare(
			"SELECT id
			 FROM {$table}
			 WHERE doc_id = %d
			 AND doc_type = %s
			 AND model = %s
			 ORDER BY chunk_index ASC
			 LIMIT %d",
			$args['post_id'],
			$args['doc_type'],
			$args['model'],
			$args['source_chunks']
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $sql is prepared above.
		$ids = $wpdb->get_col( $sql );
		if ( $wpdb->last_error ) {
			return new \WP_Error( 'db_error', $wpdb->last_error, [ 'status' => 500 ] );
		}

		return array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
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
	private static function dense_query( string $query, int $limit, array $args, array &$timings = [] ): array|\WP_Error {
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
	private static function sparse_query( string $query, int $limit, array $args ): array {
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
	private static function doc_type_where_sql( array $post_types, string $prefix = 'WHERE' ): string {
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
	private static function merge( array $dense_rows, array $sparse_rows, string $mode, int $limit ): array {
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
			static fn ( array $a, array $b ): int => $b['rrf'] <=> $a['rrf']
		);

		return array_slice( array_values( $merged ), 0, $limit );
	}

	/**
	 * Wrap dense rows in the same shape as fused results.
	 *
	 * @param array $rows Dense rows.
	 * @return array
	 */
	private static function normalize_dense_only( array $rows ): array {
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
	private static function normalize_sparse_only( array $rows ): array {
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
	private static function enrich( array $merged, array $args ): array {
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
					'date'  => get_post_time( DATE_W3C, true, $p ),
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
				'date'          => $meta[ $pid ]['date'],
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
	 * Keep the highest ranked chunk for each post while preserving best metrics.
	 *
	 * @param array $results Enriched result rows.
	 * @return array
	 */
	private static function collapse_by_post( array $results ): array {
		$collapsed = [];

		foreach ( $results as $row ) {
			$post_id = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
			if ( ! $post_id ) {
				continue;
			}

			if ( ! isset( $collapsed[ $post_id ] ) ) {
				$row['matched_chunks'] = 1;
				$collapsed[ $post_id ] = $row;
				continue;
			}

			$collapsed[ $post_id ]['matched_chunks']++;
			$collapsed[ $post_id ]['sources'] = array_values(
				array_unique(
					array_merge(
						(array) $collapsed[ $post_id ]['sources'],
						isset( $row['sources'] ) ? (array) $row['sources'] : []
					)
				)
			);

			if ( null !== $row['distance'] && ( null === $collapsed[ $post_id ]['distance'] || $row['distance'] < $collapsed[ $post_id ]['distance'] ) ) {
				$collapsed[ $post_id ]['distance']   = $row['distance'];
				$collapsed[ $post_id ]['similarity'] = $row['similarity'];
			}

			if ( null !== $row['sparse_score'] && ( null === $collapsed[ $post_id ]['sparse_score'] || $row['sparse_score'] > $collapsed[ $post_id ]['sparse_score'] ) ) {
				$collapsed[ $post_id ]['sparse_score'] = $row['sparse_score'];
			}

			if ( null !== $row['rrf_score'] && ( null === $collapsed[ $post_id ]['rrf_score'] || $row['rrf_score'] > $collapsed[ $post_id ]['rrf_score'] ) ) {
				$collapsed[ $post_id ]['rrf_score'] = $row['rrf_score'];
			}
		}

		return array_values( $collapsed );
	}

	/**
	 * Validate a query embedding before composing vector SQL.
	 *
	 * @param mixed $embedding Embedding returned by wpvdb.
	 * @return bool
	 */
	private static function is_valid_embedding( mixed $embedding ): bool {
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
	private static function project_results( array $results, array $fields ): array {
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
