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
	public const array MODES                 = [ 'hybrid', 'dense', 'sparse' ];
	public const int RRF_K                   = 60;
	public const int MAX_LIMIT               = 20;
	public const int MAX_POOL                = 200;
	public const int MAX_QUERY               = 500;
	public const int RELATED_SOURCE_CHUNKS   = 3;
	private const int RELATED_FALLBACK_BATCH = 500;

	/**
	 * Run a search.
	 *
	 * @param array<string, mixed> $args Search args.
	 * @return array<string, mixed>|\WP_Error Search payload or error.
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
		$model = self::resolve_model( (string) $args['model'] );
		if ( is_wp_error( $model ) ) {
			return $model;
		}
		$args['model'] = $model;
		$query         = (string) $args['query'];

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
			$total_vectors = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is trusted; user input is bound via prepare().
			$model_vectors_sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE model = %s", $model );
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $model_vectors_sql is prepared above.
			$model_vectors     = (int) $wpdb->get_var( $model_vectors_sql );
			$response['debug'] = [
				'model'          => $model,
				'total_vectors'  => $total_vectors,
				'model_vectors'  => $model_vectors,
				'elapsed_ms'     => (int) round( ( microtime( true ) - $t_start ) * 1000 ),
				'dense_ms'       => $dense_ms,
				'dense_embed_ms' => $dense_embed_ms,
				'dense_db_ms'    => $dense_db_ms,
				'sparse_ms'      => $sparse_ms,
				'dense_count'    => count( $dense_rows ),
				'sparse_count'   => count( $sparse_rows ),
				'fulltext_ready' => Schema::has_fulltext_index(),
			];
		}

		return $response;
	}

	/**
	 * Run a search and return unique ranked post IDs.
	 *
	 * Skips enrichment so callers can hydrate posts in their own WP_Query
	 * context. Current-user visibility is intentionally not applied here; a
	 * caller that caches this pool must run a readable-post pass before output.
	 *
	 * @param array<string, mixed> $args Search args.
	 * @param int                  $pool Max post IDs to return.
	 * @return list<int>|\WP_Error
	 */
	public static function post_ids( array $args, int $pool = 50 ): array|\WP_Error {
		$args = self::normalize_args( $args );
		if ( is_wp_error( $args ) ) {
			return $args;
		}

		if ( ! class_exists( '\WPVDB\Core' ) || ! class_exists( '\WPVDB\Settings' ) ) {
			return new \WP_Error( 'wpvdb_missing', __( 'wpvdb plugin classes not available.', 'wpvdb-search' ), [ 'status' => 500 ] );
		}

		$model = self::resolve_model( (string) $args['model'] );
		if ( is_wp_error( $model ) ) {
			return $model;
		}
		$args['model'] = $model;

		$pool           = max( 1, min( self::MAX_POOL, $pool ) );
		$candidate_pool = max( $pool * 6, 60 );
		$mode           = (string) $args['mode'];
		$query          = (string) $args['query'];
		$dense_rows     = [];
		$sparse_rows    = [];

		if ( 'dense' === $mode || 'hybrid' === $mode ) {
			$dense_rows = self::dense_query( $query, $candidate_pool, $args );
			if ( is_wp_error( $dense_rows ) ) {
				return $dense_rows;
			}
		}

		if ( 'sparse' === $mode || 'hybrid' === $mode ) {
			$sparse_rows = self::sparse_query( $query, $candidate_pool, $args );
		}

		$merged = self::merge( $dense_rows, $sparse_rows, $mode, $candidate_pool );

		return self::collapse_raw_post_ids( $merged, $pool );
	}

	/**
	 * Find posts related to a source post by comparing stored source vectors.
	 *
	 * @param int                  $post_id Source post ID.
	 * @param int                  $limit   Max related posts to return.
	 * @param array<string, mixed> $args Optional search args.
	 * @return array<string, mixed>|\WP_Error Related payload or error.
	 */
	public static function related_to_post( int $post_id, int $limit = 5, array $args = [] ): array|\WP_Error {
		$t_start = microtime( true );
		$args    = self::normalize_related_args( $post_id, $limit, $args );
		if ( is_wp_error( $args ) ) {
			return $args;
		}

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
		$candidates = self::related_candidates( $args, $source_ids, $pool );
		if ( is_wp_error( $candidates ) ) {
			return $candidates;
		}

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
	 * @param array<string, mixed> $args Raw args.
	 * @return array<string, mixed>|\WP_Error
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
		$model       = isset( $args['model'] ) ? (string) $args['model'] : '';

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
			'model'            => $model,
			'include_debug'    => ! empty( $args['include_debug'] ),
			'collapse_by_post' => ! empty( $args['collapse_by_post'] ),
		];
	}

	/**
	 * Resolve the embedding model for a search request.
	 *
	 * @param string $model Requested model, or an empty string for the wpvdb default.
	 * @return string|\WP_Error
	 */
	private static function resolve_model( string $model ): string|\WP_Error {
		$model = trim( sanitize_text_field( $model ) );

		if ( '' === $model ) {
			$model = \WPVDB\Settings::get_default_model();
		}
		if ( '' === $model ) {
			return new \WP_Error( 'model_missing', __( 'Embedding model is not configured.', 'wpvdb-search' ), [ 'status' => 500 ] );
		}

		return $model;
	}

	/**
	 * Normalize and validate related-post args.
	 *
	 * @param int                  $post_id Source post ID.
	 * @param int                  $limit   Max result count.
	 * @param array<string, mixed> $args Raw args.
	 * @return array<string, mixed>|\WP_Error
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

		/**
		 * Filters the embedding model used for related-post lookup.
		 *
		 * @since 0.6.0
		 *
		 * @param string               $model   Requested model, or empty string for the wpvdb default.
		 * @param int                  $post_id Source post ID.
		 * @param array<string, mixed> $args    Raw related-post args.
		 */
		$model_arg = apply_filters(
			'wpvdb_search_related_model',
			isset( $args['model'] ) ? (string) $args['model'] : '',
			$post_id,
			$args
		);
		$model     = self::resolve_model( (string) $model_arg );
		if ( is_wp_error( $model ) ) {
			return $model;
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
	 * @param array<string, mixed> $args Normalized related args.
	 * @return list<int>|\WP_Error
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
		$ids      = $wpdb->get_col( $sql );
		$db_error = self::last_db_error();
		if ( '' !== $db_error ) {
			return new \WP_Error( 'db_error', $db_error, [ 'status' => 500 ] );
		}

		return array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
	}

	/**
	 * Fetch related candidates using native vector SQL or PHP fallback.
	 *
	 * @param array<string, mixed> $args       Normalized related args.
	 * @param array<int>           $source_ids Source embedding row IDs.
	 * @param int                  $pool       Candidate pool size.
	 * @return list<array<string, mixed>>|\WP_Error
	 */
	private static function related_candidates( array $args, array $source_ids, int $pool ): array|\WP_Error {
		return self::should_use_php_vector_fallback()
			? self::related_candidates_php( $args, $pool )
			: self::related_candidates_native( $args, $source_ids, $pool );
	}

	/**
	 * Fetch related candidates using native vector SQL.
	 *
	 * @param array<string, mixed> $args       Normalized related args.
	 * @param array<int>           $source_ids Source embedding row IDs.
	 * @param int                  $pool       Candidate pool size.
	 * @return list<array<string, mixed>>|\WP_Error
	 */
	private static function related_candidates_native( array $args, array $source_ids, int $pool ): array|\WP_Error {
		global $wpdb;
		$table      = Schema::table();
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

		return array_values( $candidates );
	}

	/**
	 * Fetch related candidates using PHP cosine distance.
	 *
	 * @param array<string, mixed> $args Normalized related args.
	 * @param int                  $pool Candidate pool size.
	 * @return list<array<string, mixed>>|\WP_Error
	 */
	private static function related_candidates_php( array $args, int $pool ): array|\WP_Error {
		global $wpdb;
		$table = Schema::table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is trusted; user input is bound via prepare().
		$source_sql = $wpdb->prepare(
			"SELECT id, embedding
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $source_sql is prepared above.
		$source_rows = $wpdb->get_results( $source_sql, ARRAY_A );
		if ( $wpdb->last_error ) {
			return new \WP_Error( 'db_error', $wpdb->last_error, [ 'status' => 500 ] );
		}

		$source_vectors = [];
		foreach ( (array) $source_rows as $row ) {
			$vector = self::decode_embedding( $row['embedding'] ?? null );
			if ( null !== $vector ) {
				$source_vectors[] = $vector;
			}
		}

		if ( empty( $source_vectors ) ) {
			return new \WP_Error( 'bad_source_embedding', __( 'Source post embeddings are not valid.', 'wpvdb-search' ), [ 'status' => 500 ] );
		}

		$candidates = [];
		$last_id    = 0;
		do {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is trusted; user input is bound via prepare().
			$candidate_sql = $wpdb->prepare(
				"SELECT id, doc_id, chunk_id, chunk_content, summary, embedding
				 FROM {$table}
				 WHERE id > %d
				 AND doc_id <> %d
				 AND doc_type = %s
				 AND model = %s
				 ORDER BY id ASC
				 LIMIT %d",
				$last_id,
				$args['post_id'],
				$args['doc_type'],
				$args['model'],
				self::RELATED_FALLBACK_BATCH
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $candidate_sql is prepared above; fallback scans candidates in bounded batches.
			$candidate_rows = $wpdb->get_results( $candidate_sql, ARRAY_A );
			$db_error       = self::last_db_error();
			if ( '' !== $db_error ) {
				return new \WP_Error( 'db_error', $db_error, [ 'status' => 500 ] );
			}

			$row_count = count( (array) $candidate_rows );
			foreach ( (array) $candidate_rows as $row ) {
				$id = isset( $row['id'] ) ? (int) $row['id'] : 0;
				if ( $id > $last_id ) {
					$last_id = $id;
				}

				$candidate_vector = self::decode_embedding( $row['embedding'] ?? null );
				if ( ! $id || null === $candidate_vector ) {
					continue;
				}

				$best_distance = null;
				foreach ( $source_vectors as $source_vector ) {
					$distance = self::cosine_distance( $source_vector, $candidate_vector );
					if ( null === $distance ) {
						continue;
					}
					if ( null === $best_distance || $distance < $best_distance ) {
						$best_distance = $distance;
					}
				}

				if ( null === $best_distance ) {
					continue;
				}

				unset( $row['embedding'] );
				$row['distance'] = $best_distance;
				$candidates[]    = $row;
			}

			if ( count( $candidates ) > $pool * 2 ) {
				$candidates = self::slice_related_candidates( $candidates, $pool );
			}
		} while ( self::RELATED_FALLBACK_BATCH === $row_count );

		return self::slice_related_candidates( $candidates, $pool );
	}

	/**
	 * Whether vector comparisons should run in PHP.
	 *
	 * @return bool
	 */
	private static function should_use_php_vector_fallback(): bool {
		if ( function_exists( 'wpvdb_is_sqlite' ) && wpvdb_is_sqlite() ) {
			return true;
		}

		return ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE )
			|| ( defined( 'DATABASE_TYPE' ) && 'sqlite' === DATABASE_TYPE );
	}

	/**
	 * Sort and trim related candidate rows by distance.
	 *
	 * @param list<array<string, mixed>> $candidates Candidate rows.
	 * @param int                        $pool       Max candidate rows to keep.
	 * @return list<array<string, mixed>>
	 */
	private static function slice_related_candidates( array $candidates, int $pool ): array {
		usort(
			$candidates,
			static fn ( array $a, array $b ): int => (float) $a['distance'] <=> (float) $b['distance']
		);

		return array_slice( $candidates, 0, $pool );
	}

	/**
	 * Return the last database error.
	 *
	 * The wpdb object mutates last_error from query methods, which static analysis cannot
	 * infer between adjacent calls.
	 *
	 * @return string
	 */
	private static function last_db_error(): string {
		global $wpdb;

		return (string) $wpdb->last_error;
	}

	/**
	 * Decode a JSON stored embedding and validate it before comparison.
	 *
	 * @param mixed $embedding Raw stored embedding.
	 * @return list<float>|null
	 */
	private static function decode_embedding( mixed $embedding ): ?array {
		if ( ! is_string( $embedding ) || '' === $embedding ) {
			return null;
		}

		$decoded = json_decode( $embedding, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		$out          = [];
		$expected_key = 0;
		foreach ( $decoded as $key => $value ) {
			if ( $key !== $expected_key || ( ! is_int( $value ) && ! is_float( $value ) ) ) {
				return null;
			}
			if ( ! is_finite( (float) $value ) ) {
				return null;
			}

			$out[] = (float) $value;
			$expected_key++;
		}

		return self::is_valid_embedding( $out ) ? $out : null;
	}

	/**
	 * Calculate strict cosine distance for two validated embeddings.
	 *
	 * @param array<float> $source    Source vector.
	 * @param array<float> $candidate Candidate vector.
	 * @return float|null
	 */
	private static function cosine_distance( array $source, array $candidate ): ?float {
		if ( count( $source ) !== count( $candidate ) || ! self::is_valid_embedding( $source ) || ! self::is_valid_embedding( $candidate ) ) {
			return null;
		}

		$dot       = 0.0;
		$source_sq = 0.0;
		$target_sq = 0.0;
		$length    = count( $source );

		for ( $i = 0; $i < $length; $i++ ) {
			$source_value = $source[ $i ];
			$target_value = $candidate[ $i ];
			$dot         += $source_value * $target_value;
			$source_sq   += $source_value * $source_value;
			$target_sq   += $target_value * $target_value;
		}

		if ( $source_sq <= 1.0e-30 || $target_sq <= 1.0e-30 ) {
			return null;
		}

		$similarity = $dot / ( sqrt( $source_sq ) * sqrt( $target_sq ) );
		if ( ! is_finite( $similarity ) ) {
			return null;
		}

		$distance = 1.0 - max( -1.0, min( 1.0, $similarity ) );

		return is_finite( $distance ) ? $distance : null;
	}

	/**
	 * Dense vector query via MariaDB VEC_DISTANCE_COSINE.
	 *
	 * @param string               $query   Query text.
	 * @param int                  $limit   Pool size.
	 * @param array<string, mixed> $args    Normalized args.
	 * @param array<string, int>   $timings Timing measurements populated by reference.
	 * @return list<array<string, mixed>>|\WP_Error
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
		$model    = (string) $args['model'];
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
		if ( ! is_string( $json ) ) {
			return new \WP_Error( 'bad_embedding', __( 'Embedding provider returned an invalid vector.', 'wpvdb-search' ), [ 'status' => 500 ] );
		}
		$vf    = "VEC_FromText('" . esc_sql( $json ) . "')";
		$df    = "VEC_DISTANCE_COSINE(embedding, $vf)";
		$where = self::doc_type_where_sql( $args['post_type'] );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and vector expression are trusted; user input is bound via prepare().
		if ( '' === $where ) {
			$sql = $wpdb->prepare(
				"SELECT id, doc_id, chunk_id, chunk_content, summary, {$df} as distance
				FROM {$table}
				WHERE model = %s
				ORDER BY distance
				LIMIT %d",
				$model,
				(int) $limit
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT id, doc_id, chunk_id, chunk_content, summary, {$df} as distance
				FROM {$table}
				{$where}
				AND model = %s
				ORDER BY distance
				LIMIT %d",
				$model,
				(int) $limit
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$t_db = microtime( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $sql is prepared above; vector ordering must stay in SQL.
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
	 * @param string               $query Query text.
	 * @param int                  $limit Pool size.
	 * @param array<string, mixed> $args Normalized args.
	 * @return list<array<string, mixed>>
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
			AND model = %s
			{$doc_type_where}
			ORDER BY score DESC
			LIMIT %d",
			$query,
			$query,
			$args['model'],
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
	 * @phpstan-param list<string> $post_types
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
	 * @param list<array<string, mixed>> $dense_rows  Dense result rows.
	 * @param list<array<string, mixed>> $sparse_rows Sparse result rows.
	 * @param string                     $mode        Search mode.
	 * @param int                        $limit       Final result count.
	 * @return list<array<string, mixed>>
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
	 * @param list<array<string, mixed>> $rows Dense rows.
	 * @return list<array<string, mixed>>
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
	 * @param list<array<string, mixed>> $rows Sparse rows.
	 * @return list<array<string, mixed>>
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
	 * Keep the highest ranked raw chunk for each post and return post IDs.
	 *
	 * @param list<array<string, mixed>> $merged Merged rows from self::merge().
	 * @param int                        $limit  Max post IDs to return.
	 * @return list<int>
	 */
	private static function collapse_raw_post_ids( array $merged, int $limit ): array {
		$out  = [];
		$seen = [];

		foreach ( $merged as $m ) {
			$row     = isset( $m['row'] ) && is_array( $m['row'] ) ? $m['row'] : [];
			$post_id = isset( $row['doc_id'] ) ? (int) $row['doc_id'] : 0;
			if ( $post_id <= 0 || isset( $seen[ $post_id ] ) ) {
				continue;
			}

			$seen[ $post_id ] = true;
			$out[]            = $post_id;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Attach post metadata and decode HTML entities.
	 *
	 * @param list<array<string, mixed>> $merged Merged rows from self::merge().
	 * @param array<string, mixed>       $args   Normalized args.
	 * @return list<array<string, mixed>>
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
	 * @param list<array<string, mixed>> $results Enriched result rows.
	 * @return list<array<string, mixed>>
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

		$expected_key = 0;
		$sum_sq       = 0.0;
		foreach ( $embedding as $key => $value ) {
			if ( $key !== $expected_key ) {
				return false;
			}
			if ( ! is_int( $value ) && ! is_float( $value ) ) {
				return false;
			}
			if ( ! is_finite( (float) $value ) ) {
				return false;
			}

			$float_value = (float) $value;
			$sum_sq     += $float_value * $float_value;
			$expected_key++;
		}

		return $sum_sq > 1.0e-30;
	}

	/**
	 * Return only requested fields. Empty field list returns the full result.
	 *
	 * @param list<array<string, mixed>> $results Result rows.
	 * @param array                      $fields  Requested fields.
	 * @phpstan-param list<string> $fields
	 * @return list<array<string, mixed>>
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
