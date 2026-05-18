<?php
declare(strict_types=1);

namespace WPVDB_Search\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WP_Error;
use WPVDB\Settings;
use WPVDB_Search\Search;

final class SearchTest extends TestCase {
	protected function setUp(): void {
		Settings::$default_model                         = 'default-model';
		$GLOBALS['wpvdb_search_test']['is_sqlite']       = false;
		$GLOBALS['wpvdb_search_test']['post_types']      = [];
		$GLOBALS['wpvdb_search_test']['posts']           = [];
		$GLOBALS['wpvdb_search_test']['source_ids']      = [];
		$GLOBALS['wpvdb_search_test']['source_rows']     = [];
		$GLOBALS['wpvdb_search_test']['candidate_rows']  = [];
		$GLOBALS['wpdb']->queries                        = [];
		$GLOBALS['wpdb']->last_error                     = '';
	}

	/**
	 * @param list<mixed> $args Method args.
	 */
	private function invoke( string $method, array $args = [] ): mixed {
		$reflection = new ReflectionMethod( Search::class, $method );
		return $reflection->invokeArgs( null, $args );
	}

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

		self::assertIsArray( $normalized );
		self::assertSame( 'market anxiety', $normalized['query'] );
		self::assertSame( Search::MAX_LIMIT, $normalized['limit'] );
		self::assertSame( 'hybrid', $normalized['mode'] );
		self::assertSame( [ 'post', 'newsarticle' ], $normalized['post_type'] );
		self::assertSame( [ 'publish', 'draft' ], $normalized['post_status'] );
		self::assertSame( [ 'title', 'link' ], $normalized['fields'] );
		self::assertTrue( $normalized['include_debug'] );
		self::assertTrue( $normalized['collapse_by_post'] );
	}

	public function test_normalize_args_rejects_empty_and_too_long_queries(): void {
		$empty = $this->invoke( 'normalize_args', [ [ 'query' => '   ' ] ] );
		self::assertInstanceOf( WP_Error::class, $empty );
		self::assertSame( 'empty_query', $empty->get_error_code() );

		$too_long = $this->invoke( 'normalize_args', [ [ 'query' => str_repeat( 'a', Search::MAX_QUERY + 1 ) ] ] );
		self::assertInstanceOf( WP_Error::class, $too_long );
		self::assertSame( 'query_too_long', $too_long->get_error_code() );
	}

	public function test_resolve_model_uses_override_or_default_and_rejects_empty_default(): void {
		Settings::$default_model = 'fallback-model';
		self::assertSame( 'requested-model', $this->invoke( 'resolve_model', [ ' <b>requested-model</b> ' ] ) );
		self::assertSame( 'fallback-model', $this->invoke( 'resolve_model', [ '' ] ) );

		Settings::$default_model = '';
		$error                   = $this->invoke( 'resolve_model', [ '' ] );
		self::assertInstanceOf( WP_Error::class, $error );
		self::assertSame( 'model_missing', $error->get_error_code() );
	}

	public function test_decode_embedding_accepts_json_lists_and_rejects_invalid_vectors(): void {
		self::assertSame( [ 0.25, -0.5, 1.0 ], $this->invoke( 'decode_embedding', [ '[0.25,-0.5,1]' ] ) );
		self::assertNull( $this->invoke( 'decode_embedding', [ '' ] ) );
		self::assertNull( $this->invoke( 'decode_embedding', [ '{"x":1}' ] ) );
		self::assertNull( $this->invoke( 'decode_embedding', [ '["0.25",0.5]' ] ) );
		self::assertNull( $this->invoke( 'decode_embedding', [ '[0,0,0]' ] ) );
	}

	public function test_cosine_distance_is_strict_and_clamped(): void {
		self::assertSame( 0.0, $this->invoke( 'cosine_distance', [ [ 1.0, 0.0 ], [ 1.0, 0.0 ] ] ) );
		self::assertSame( 1.0, $this->invoke( 'cosine_distance', [ [ 1.0, 0.0 ], [ 0.0, 1.0 ] ] ) );
		self::assertNull( $this->invoke( 'cosine_distance', [ [ 1.0 ], [ 1.0, 0.0 ] ] ) );
		self::assertNull( $this->invoke( 'cosine_distance', [ [ 1.0e-20, 0.0 ], [ 1.0, 0.0 ] ] ) );
	}

	public function test_hybrid_merge_prefers_rows_present_in_both_sources(): void {
		$merged = $this->invoke(
			'merge',
			[
				[
					[ 'id' => 10, 'distance' => 0.2 ],
					[ 'id' => 20, 'distance' => 0.1 ],
				],
				[
					[ 'id' => 20, 'score' => 6.0 ],
					[ 'id' => 30, 'score' => 5.0 ],
				],
				'hybrid',
				3,
			]
		);

		self::assertSame( 20, (int) $merged[0]['row']['id'] );
		self::assertSame( [ 'dense', 'sparse' ], $merged[0]['sources'] );
		self::assertSame( 2, $merged[0]['dense_rank'] );
		self::assertSame( 1, $merged[0]['sparse_rank'] );
	}

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

		self::assertIsArray( $result );
		self::assertSame( 'related', $result['mode'] );
		self::assertSame( [ 200, 300 ], array_column( $result['results'], 'post_id' ) );
		self::assertSame( 'Best match', $result['results'][0]['title'] );
		self::assertLessThan( $result['results'][1]['distance'], $result['results'][0]['distance'] );
		self::assertSame( 1, $result['debug']['source_chunks'] );
		self::assertSame( 2, $result['debug']['candidate_count'] );
		self::assertStringContainsString( "AND model = 'demo-model'", implode( "\n", $GLOBALS['wpdb']->queries ) );
	}
}
