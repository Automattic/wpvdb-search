<?php
/**
 * PHPStan stubs for the wpvdb plugin API consumed by WPVDB Search.
 *
 * @package WPVDB_Search
 */

namespace WPVDB;

class Core {
	/**
	 * @return array<int, float>|\WP_Error
	 */
	public static function get_embedding( string $text, string $model = '', string $api_base = '', string $api_key = '' ): array|\WP_Error {
		throw new \BadMethodCallException( 'PHPStan stub only.' );
	}
}

class Settings {
	public static function get_active_provider(): string {
		throw new \BadMethodCallException( 'PHPStan stub only.' );
	}

	public static function get_default_model(): string {
		throw new \BadMethodCallException( 'PHPStan stub only.' );
	}

	public static function get_api_key_for_provider( string $provider ): string {
		throw new \BadMethodCallException( 'PHPStan stub only.' );
	}

	public static function get_api_base_for_provider( string $provider ): string {
		throw new \BadMethodCallException( 'PHPStan stub only.' );
	}
}
