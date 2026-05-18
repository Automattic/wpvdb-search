<?php
/**
 * Search service tests.
 *
 * @package WPVDB_Search
 */

declare(strict_types=1);

namespace WPVDB_Search\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WP_Error;
use WPVDB\Settings;
use WPVDB_Search\Search;

/**
 * Tests search service behavior.
 *
 * @covers \WPVDB_Search\Search
 */
final class SearchTest extends TestCase {
	/**
	 * Reset shared test state.
	 */
	protected function setUp(): void {
		Settings::$default_model                        = 'default-model';
		$GLOBALS['wpvdb_search_test']['is_sqlite']      = false;
		$GLOBALS['wpvdb_search_test']['post_types']     = [];
		$GLOBALS['wpvdb_search_test']['posts']          = [];
		$GLOBALS['wpvdb_search_test']['source_ids']     = [];
		$GLOBALS['wpvdb_search_test']['source_rows']    = [];
		$GLOBALS['wpvdb_search_test']['candidate_rows'] = [];
		$GLOBALS['wpdb']->queries                       = [];
		$GLOBALS['wpdb']->last_error                    = '';
	}

	/**
	 * Invoke a private static search method.
	 *
	 * @param string       $method Method name.
	 * @param array<mixed> $args   Method args.
	 * @return mixed
	 */
	private function invoke( string $method, array $args = [] ): mixed {
		$reflection = new ReflectionMethod( Search::class, $method );
		return $reflection->invokeArgs( null, $args );
	}

	/**
	 * Test query args are normalized for search.
	 *
	 * @covers \WPVDB_Search\Search::normalize_args
	 */
	public function test_normalize_args_clamps_and_sanitizes_inputs(): void {
		$normalized = $this->invoke(
			'normalize_args',
			[
				[
					'query'            => '  market anxiety  ',
					'limit'            => 999,
					'mode'             => 'unknown',
					'post_type'        => [ 'post', 'News Article!', '' ],
					'post_status'      => [ 'publish', 'Draft!' ],
					'fields'           => [ 'title', 'link!', '' ],
					'include_debug'    => '1',
					'collapse_by_post' => true,
				],
			]
		);

		self::assertIsArray( $normalized, 'Normalized args should be an array.' );
		self::assertSame( 'market anxiety', $normalized['query'], 'Query text should be trimmed.' );
		self::assertSame( Search::MAX_LIMIT, $normalized['limit'], 'Limit should be clamped to the maximum.' );
		self::assertSame( 'hybrid', $normalized['mode'], 'Unknown modes should fall back to hybrid.' );
		self::assertSame( [ 'post', 'newsarticle' ], $normalized['post_type'], 'Post types should be sanitized.' );
		self::assertSame( [ 'publish', 'draft' ], $normalized['post_status'], 'Post statuses should be sanitized.' );
		self::assertSame( [ 'title', 'link' ], $normalized['fields'], 'Response fields should be sanitized.' );
		self::assertTrue( $normalized['include_debug'], 'Debug flag should be normalized to true.' );
		self::assertTrue( $normalized['collapse_by_post'], 'Collapse flag should be normalized to true.' );
	}

	/**
	 * Test invalid query text is rejected.
	 *
	 * @covers \WPVDB_Search\Search::normalize_args
	 */
	public function test_normalize_args_rejects_empty_and_too_long_queries(): void {
		$empty = $this->invoke( 'normalize_args', [ [ 'query' => '   ' ] ] );
		self::assertInstanceOf( WP_Error::class, $empty, 'Empty queries should return an error.' );
		self::assertSame( 'empty_query', $empty->get_error_code(), 'Empty queries should use the empty query code.' );

		$too_long = $this->invoke( 'normalize_args', [ [ 'query' => str_repeat( 'a', Search::MAX_QUERY + 1 ) ] ] );
		self::assertInstanceOf( WP_Error::class, $too_long, 'Too long queries should return an error.' );
		self::assertSame( 'query_too_long', $too_long->get_error_code(), 'Too long queries should use the query too long code.' );
	}

	/**
	 * Test model resolution uses overrides and defaults.
	 *
	 * @covers \WPVDB_Search\Search::resolve_model
	 */
	public function test_resolve_model_uses_override_or_default_and_rejects_empty_default(): void {
		Settings::$default_model = 'fallback-model';
		self::assertSame( 'requested-model', $this->invoke( 'resolve_model', [ ' <b>requested-model</b> ' ] ), 'Explicit models should be sanitized and used.' );
		self::assertSame( 'fallback-model', $this->invoke( 'resolve_model', [ '' ] ), 'Empty models should fall back to the default model.' );

		Settings::$default_model = '';
		$error                   = $this->invoke( 'resolve_model', [ '' ] );
		self::assertInstanceOf( WP_Error::class, $error, 'Missing default models should return an error.' );
		self::assertSame( 'model_missing', $error->get_error_code(), 'Missing models should use the model missing code.' );
	}

	/**
	 * Test embedding decoding validates stored vectors.
	 *
	 * @covers \WPVDB_Search\Search::decode_embedding
	 */
	public function test_decode_embedding_accepts_json_lists_and_rejects_invalid_vectors(): void {
		self::assertSame( [ 0.25, -0.5, 1.0 ], $this->invoke( 'decode_embedding', [ '[0.25,-0.5,1]' ] ), 'Numeric JSON lists should decode to floats.' );
		self::assertNull( $this->invoke( 'decode_embedding', [ '' ] ), 'Empty embeddings should be rejected.' );
		self::assertNull( $this->invoke( 'decode_embedding', [ '{"x":1}' ] ), 'Object shaped embeddings should be rejected.' );
		self::assertNull( $this->invoke( 'decode_embedding', [ '["0.25",0.5]' ] ), 'String vector values should be rejected.' );
		self::assertNull( $this->invoke( 'decode_embedding', [ '[0,0,0]' ] ), 'Zero vectors should be rejected.' );
	}

	/**
	 * Test cosine distance validates dimensions and magnitude.
	 *
	 * @covers \WPVDB_Search\Search::cosine_distance
	 */
	public function test_cosine_distance_is_strict_and_clamped(): void {
		self::assertSame( 0.0, $this->invoke( 'cosine_distance', [ [ 1.0, 0.0 ], [ 1.0, 0.0 ] ] ), 'Identical vectors should have zero distance.' );
		self::assertSame( 1.0, $this->invoke( 'cosine_distance', [ [ 1.0, 0.0 ], [ 0.0, 1.0 ] ] ), 'Orthogonal vectors should have unit distance.' );
		self::assertNull( $this->invoke( 'cosine_distance', [ [ 1.0 ], [ 1.0, 0.0 ] ] ), 'Dimension mismatches should be rejected.' );
		self::assertNull( $this->invoke( 'cosine_distance', [ [ 1.0e-20, 0.0 ], [ 1.0, 0.0 ] ] ), 'Near zero vectors should be rejected.' );
	}

	/**
	 * Test hybrid merge rewards overlap between search modes.
	 *
	 * @covers \WPVDB_Search\Search::merge
	 */
	public function test_hybrid_merge_prefers_rows_present_in_both_sources(): void {
		$merged = $this->invoke(
			'merge',
			[
				[
					[
						'id'       => 10,
						'distance' => 0.2,
					],
					[
						'id'       => 20,
						'distance' => 0.1,
					],
				],
				[
					[
						'id'    => 20,
						'score' => 6.0,
					],
					[
						'id'    => 30,
						'score' => 5.0,
					],
				],
				'hybrid',
				3,
			]
		);

		self::assertSame( 20, (int) $merged[0]['row']['id'], 'Rows present in both sources should rank first.' );
		self::assertSame( [ 'dense', 'sparse' ], $merged[0]['sources'], 'Merged rows should preserve both source labels.' );
		self::assertSame( 2, $merged[0]['dense_rank'], 'Dense rank should be preserved.' );
		self::assertSame( 1, $merged[0]['sparse_rank'], 'Sparse rank should be preserved.' );
	}

	/**
	 * Test related lookup uses the SQLite PHP fallback.
	 *
	 * @covers \WPVDB_Search\Search::related_to_post
	 * @covers \WPVDB_Search\Search::related_candidates_php
	 */
	public function test_related_to_post_uses_sqlite_fallback_and_model_scoped_candidates(): void {
		$GLOBALS['wpvdb_search_test']['is_sqlite']       = true;
		$GLOBALS['wpvdb_search_test']['post_types'][100] = 'post';
		$GLOBALS['wpvdb_search_test']['posts']           = [
			100 => [
				'title' => 'Source',
				'link'  => 'https://example.test/source',
				'date'  => '2026-05-17T00:00:00+00:00',
			],
			200 => [
				'title' => 'Best match',
				'link'  => 'https://example.test/best',
				'date'  => '2026-05-18T00:00:00+00:00',
			],
			300 => [
				'title' => 'Weak match',
				'link'  => 'https://example.test/weak',
				'date'  => '2026-05-19T00:00:00+00:00',
			],
		];
		$GLOBALS['wpvdb_search_test']['source_ids']      = [ 1 ];
		$GLOBALS['wpvdb_search_test']['source_rows']     = [
			[
				'id'        => 1,
				'embedding' => '[1,0]',
			],
		];
		$GLOBALS['wpvdb_search_test']['candidate_rows']  = [
			[
				'id'            => 2,
				'doc_id'        => 200,
				'chunk_id'      => '0',
				'chunk_content' => 'Close result',
				'summary'       => '',
				'embedding'     => '[0.95,0.05]',
			],
			[
				'id'            => 3,
				'doc_id'        => 300,
				'chunk_id'      => '0',
				'chunk_content' => 'Far result',
				'summary'       => '',
				'embedding'     => '[0,1]',
			],
			[
				'id'            => 4,
				'doc_id'        => 400,
				'chunk_id'      => '0',
				'chunk_content' => 'Invalid vector',
				'summary'       => '',
				'embedding'     => '[0,0]',
			],
		];

		$result = Search::related_to_post(
			100,
			2,
			[
				'model'         => 'demo-model',
				'include_debug' => true,
			]
		);

		self::assertIsArray( $result, 'Related lookup should return an array result.' );
		self::assertSame( 'related', $result['mode'], 'Related lookup should report related mode.' );
		self::assertSame( [ 200, 300 ], array_column( $result['results'], 'post_id' ), 'Related results should be ordered by distance.' );
		self::assertSame( 'Best match', $result['results'][0]['title'], 'Closest candidate should appear first.' );
		self::assertLessThan( $result['results'][1]['distance'], $result['results'][0]['distance'], 'First result should have a smaller distance.' );
		self::assertSame( 1, $result['debug']['source_chunks'], 'Debug data should report one source chunk.' );
		self::assertSame( 2, $result['debug']['candidate_count'], 'Debug data should exclude invalid candidates.' );
		self::assertStringContainsString( "AND model = 'demo-model'", implode( "\n", $GLOBALS['wpdb']->queries ), 'Candidate SQL should be scoped to the requested model.' );
	}
}
