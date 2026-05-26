<?php
declare(strict_types=1);

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );

	$GLOBALS['wpvdb_search_test'] = [
		'is_sqlite'      => false,
		'post_types'     => [],
		'posts'          => [],
		'source_ids'     => [],
		'source_rows'    => [],
		'candidate_rows' => [],
		'dense_rows'     => null,
		'sparse_rows'    => null,
		'has_fulltext'   => false,
		'embedding'      => [ 1.0, 0.0 ],
	];

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			/**
			 * @param array<string, mixed> $data Error data.
			 */
			public function __construct(
				private readonly string $code = '',
				private readonly string $message = '',
				private readonly array $data = []
			) {}

			public function get_error_code(): string {
				return $this->code;
			}

			public function get_error_message(): string {
				return $this->message;
			}

			/**
			 * @return array<string, mixed>
			 */
			public function get_error_data(): array {
				return $this->data;
			}
		}
	}

	function __( string $text, string $domain = 'default' ): string {
		unset( $domain );
		return $text;
	}

	function is_wp_error( mixed $value ): bool {
		return $value instanceof \WP_Error;
	}

	/**
	 * @param array<mixed> $args Filter args.
	 */
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		unset( $hook, $args );
		return $value;
	}

	function absint( mixed $value ): int {
		return max( 0, (int) $value );
	}

	function get_post( int $post_id ): object|null {
		return isset( $GLOBALS['wpvdb_search_test']['posts'][ $post_id ] )
			? (object) [ 'ID' => $post_id ]
			: null;
	}

	function get_post_type( int $post_id ): string|false {
		return $GLOBALS['wpvdb_search_test']['post_types'][ $post_id ] ?? false;
	}

	/**
	 * @param array<string, mixed> $args Query args.
	 * @return list<object>
	 */
	function get_posts( array $args ): array {
		$include = isset( $args['include'] ) ? (array) $args['include'] : [];
		$posts   = [];

		foreach ( $include as $post_id ) {
			$post_id = (int) $post_id;
			if ( isset( $GLOBALS['wpvdb_search_test']['posts'][ $post_id ] ) ) {
				$posts[] = (object) [ 'ID' => $post_id ];
			}
		}

		return $posts;
	}

	function get_the_title( object|int $post ): string {
		$post_id = is_object( $post ) ? (int) $post->ID : (int) $post;
		return $GLOBALS['wpvdb_search_test']['posts'][ $post_id ]['title'] ?? '';
	}

	function get_permalink( object|int $post ): string {
		$post_id = is_object( $post ) ? (int) $post->ID : (int) $post;
		return $GLOBALS['wpvdb_search_test']['posts'][ $post_id ]['link'] ?? '';
	}

	function get_post_time( string $format, bool $gmt, object|int $post ): string {
		unset( $format, $gmt );
		$post_id = is_object( $post ) ? (int) $post->ID : (int) $post;
		return $GLOBALS['wpvdb_search_test']['posts'][ $post_id ]['date'] ?? '';
	}

	function sanitize_key( mixed $key ): string {
		return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $key ) ?? '' );
	}

	function sanitize_text_field( mixed $value ): string {
		return trim( wp_strip_all_tags( (string) $value ) );
	}

	function esc_sql( mixed $value ): string {
		return addslashes( (string) $value );
	}

	function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
		return json_encode( $value, $flags, $depth );
	}

	function wp_strip_all_tags( string $text ): string {
		return strip_tags( $text );
	}

	function wpvdb_is_sqlite(): bool {
		return (bool) $GLOBALS['wpvdb_search_test']['is_sqlite'];
	}

	class WPVDB_Search_Test_WPDB {
		public string $prefix = 'wp_';
		public string $last_error = '';

		/**
		 * @var list<string>
		 */
		public array $queries = [];

		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$replacement = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
				$query       = preg_replace( '/%[sd]/', $replacement, $query, 1 ) ?? $query;
			}

			return $query;
		}

		/**
		 * @return list<int>
		 */
		public function get_col( string $query ): array {
			$this->queries[] = $query;
			return $GLOBALS['wpvdb_search_test']['source_ids'];
		}

		public function get_var( string $query ): mixed {
			$this->queries[] = $query;
			if ( str_contains( $query, 'SHOW INDEX' ) ) {
				return $GLOBALS['wpvdb_search_test']['has_fulltext'] ? 'wpvdb_ss_ft_chunk' : null;
			}
			return null;
		}

		/**
		 * @return list<array<string, mixed>>
		 */
		public function get_results( string $query, string $output = ARRAY_A ): array {
			unset( $output );
			$this->queries[] = $query;

			if ( str_contains( $query, 'WHERE doc_id = ' ) ) {
				return $GLOBALS['wpvdb_search_test']['source_rows'];
			}

			if ( ! str_contains( $query, "AND model = 'demo-model'" ) ) {
				return [];
			}

			if ( str_contains( $query, 'VEC_DISTANCE_COSINE' ) && is_array( $GLOBALS['wpvdb_search_test']['dense_rows'] ) ) {
				return $GLOBALS['wpvdb_search_test']['dense_rows'];
			}

			if ( str_contains( $query, 'MATCH(chunk_content)' ) && is_array( $GLOBALS['wpvdb_search_test']['sparse_rows'] ) ) {
				return $GLOBALS['wpvdb_search_test']['sparse_rows'];
			}

			$last_id = 0;
			if ( preg_match( '/WHERE id > (\d+)/', $query, $matches ) ) {
				$last_id = (int) $matches[1];
			}

			return array_values(
				array_slice(
					array_filter(
						$GLOBALS['wpvdb_search_test']['candidate_rows'],
						static fn ( array $row ): bool => (int) $row['id'] > $last_id
					),
					0,
					500
				)
			);
		}
	}

	$GLOBALS['wpdb'] = new WPVDB_Search_Test_WPDB();
}

namespace WPVDB {
	class Core {
		/**
		 * @return list<float>|\WP_Error
		 */
		public static function get_embedding( string $text, string $model, string $api_base, string $api_key ): array|\WP_Error {
			unset( $text, $model, $api_base, $api_key );
			return $GLOBALS['wpvdb_search_test']['embedding'];
		}
	}

	class Settings {
		public static string $default_model = 'default-model';

		public static function get_default_model(): string {
			return self::$default_model;
		}

		public static function get_active_provider(): string {
			return 'openai';
		}

		public static function get_api_key_for_provider( string $provider ): string {
			unset( $provider );
			return 'test-key';
		}

		public static function get_api_base_for_provider( string $provider ): string {
			unset( $provider );
			return 'https://example.test';
		}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/includes/class-schema.php';
	require_once dirname( __DIR__ ) . '/includes/class-search.php';
}
