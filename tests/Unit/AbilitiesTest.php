<?php
/**
 * Ability adapter tests.
 *
 * @package WPVDB_Search
 */

declare(strict_types=1);

namespace WPVDB_Search\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WPVDB\Settings;
use WPVDB_Search\Abilities;

/**
 * Tests the WordPress Abilities adapter.
 *
 * @covers \WPVDB_Search\Abilities
 */
final class AbilitiesTest extends TestCase {
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
		$GLOBALS['wpvdb_search_test']['dense_rows']     = null;
		$GLOBALS['wpvdb_search_test']['sparse_rows']    = null;
		$GLOBALS['wpvdb_search_test']['has_fulltext']   = false;
		$GLOBALS['wpvdb_search_test']['embedding']      = [ 1.0, 0.0 ];
		$GLOBALS['wpvdb_search_test']['abilities']      = [];
		$GLOBALS['wpvdb_search_test']['categories']     = [];
		$GLOBALS['wpvdb_search_test']['caps']           = [ 'read' => true ];
		$GLOBALS['wpvdb_search_test']['transients']     = [];
		$GLOBALS['wpvdb_search_test']['user_id']        = 1;
		$GLOBALS['wpvdb_search_test']['get_posts_args'] = [];
		$GLOBALS['wpdb']->queries                       = [];
		$GLOBALS['wpdb']->last_error                    = '';
	}

	/**
	 * Test ability registration includes both public tools.
	 *
	 * @covers \WPVDB_Search\Abilities::register_category
	 * @covers \WPVDB_Search\Abilities::register_abilities
	 */
	public function test_register_abilities_registers_semantic_and_related_tools(): void {
		Abilities::register_category();
		Abilities::register_abilities();

		self::assertArrayHasKey( Abilities::CATEGORY, $GLOBALS['wpvdb_search_test']['categories'], 'Ability category should be registered.' );
		self::assertArrayHasKey( Abilities::SEMANTIC_SEARCH, $GLOBALS['wpvdb_search_test']['abilities'], 'Semantic search ability should be registered.' );
		self::assertArrayHasKey( Abilities::FIND_RELATED, $GLOBALS['wpvdb_search_test']['abilities'], 'Related posts ability should be registered.' );

		$related = $GLOBALS['wpvdb_search_test']['abilities'][ Abilities::FIND_RELATED ];
		self::assertSame( [ Abilities::class, 'execute_find_related_posts' ], $related['execute_callback'], 'Related ability should use the related execution callback.' );
		self::assertTrue( $related['meta']['annotations']['readonly'], 'Related ability should be read-only.' );
		self::assertFalse( $related['meta']['annotations']['destructive'], 'Related ability should be non-destructive.' );
		self::assertTrue( $related['meta']['mcp']['public'], 'Related ability should be public to MCP discovery.' );
		self::assertContains( 'post_id', $related['input_schema']['required'], 'Related ability should require a source post ID.' );
	}

	/**
	 * Test related ability execution formats related search results for agents.
	 *
	 * @covers \WPVDB_Search\Abilities::execute_find_related_posts
	 */
	public function test_execute_find_related_posts_returns_agent_safe_results(): void {
		Settings::$default_model                         = 'demo-model';
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
				'chunk_content' => 'Close related chunk',
				'summary'       => '',
				'embedding'     => '[0.95,0.05]',
			],
			[
				'id'            => 3,
				'doc_id'        => 300,
				'chunk_id'      => '0',
				'chunk_content' => 'Far related chunk',
				'summary'       => '',
				'embedding'     => '[0,1]',
			],
		];

		$result = Abilities::execute_find_related_posts(
			[
				'post_id' => 100,
				'limit'   => 2,
			]
		);

		self::assertIsArray( $result, 'Related ability should return an array result.' );
		self::assertSame( 100, $result['post_id'], 'Related ability should echo the source post ID.' );
		self::assertSame( 'related', $result['mode'], 'Related ability should report related mode.' );
		self::assertSame( [ 200, 300 ], array_column( $result['results'], 'post_id' ), 'Related ability should preserve related rank order.' );
		self::assertSame( 'https://example.test/best', $result['results'][0]['url'], 'Related ability should expose canonical URLs.' );
		self::assertSame( 'Close related chunk', $result['results'][0]['excerpt'], 'Related ability should expose bounded excerpts.' );
		self::assertSame( 'cosine_similarity', $result['results'][0]['score_type'], 'Related ability should label related scores as dense similarity.' );
		self::assertArrayNotHasKey( 'chunk_content', $result['results'][0], 'Related ability should not expose raw search rows.' );
	}

	/**
	 * Test related ability clamps private status against requested post types.
	 *
	 * @covers \WPVDB_Search\Abilities::execute_find_related_posts
	 */
	public function test_execute_find_related_posts_clamps_private_status_by_post_type(): void {
		Settings::$default_model                         = 'demo-model';
		$GLOBALS['wpvdb_search_test']['is_sqlite']       = true;
		$GLOBALS['wpvdb_search_test']['caps']            = [
			'read'               => true,
			'read_private_posts' => true,
		];
		$GLOBALS['wpvdb_search_test']['post_types'][100] = 'post';
		$GLOBALS['wpvdb_search_test']['posts']           = [
			100 => [
				'title' => 'Source',
				'link'  => 'https://example.test/source',
				'date'  => '2026-05-17T00:00:00+00:00',
			],
			200 => [
				'title' => 'Candidate',
				'link'  => 'https://example.test/candidate',
				'date'  => '2026-05-18T00:00:00+00:00',
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
				'chunk_content' => 'Candidate chunk',
				'summary'       => '',
				'embedding'     => '[0.95,0.05]',
			],
		];

		Abilities::execute_find_related_posts(
			[
				'post_id'     => 100,
				'post_type'   => [ 'page' ],
				'post_status' => [ 'private' ],
			]
		);

		$get_posts_args = end( $GLOBALS['wpvdb_search_test']['get_posts_args'] );
		self::assertIsArray( $get_posts_args, 'Related lookup should hydrate through get_posts().' );
		self::assertSame( [ 'page' ], $get_posts_args['post_type'], 'Related ability should preserve the requested post type.' );
		self::assertSame( [ 'publish' ], $get_posts_args['post_status'], 'Private status should be rejected when the user cannot read private posts for the requested type.' );
	}

	/**
	 * Test invalid source posts bubble up as errors.
	 *
	 * @covers \WPVDB_Search\Abilities::execute_find_related_posts
	 */
	public function test_execute_find_related_posts_returns_invalid_post_error(): void {
		$result = Abilities::execute_find_related_posts(
			[
				'post_id' => 999,
			]
		);

		self::assertInstanceOf( WP_Error::class, $result, 'Missing source posts should return an error.' );
		self::assertSame( 'invalid_post', $result->get_error_code(), 'Missing source posts should use the invalid post error code.' );
	}
}
