<?php
/**
 * Schema add-on for sparse search.
 *
 * @package WPVDB_Search
 */

namespace WPVDB_Search;

defined( 'ABSPATH' ) || exit;

/**
 * Ensures the FULLTEXT index exists on wpvdb's embeddings table.
 */
class Schema {
	const OPTION_INSTALLED = 'wpvdb_search_fulltext_installed';
	const INDEX_NAME       = 'wpvdb_ss_ft_chunk';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'ensure_fulltext_index' ] );
	}

	/**
	 * Fully-qualified wpvdb embeddings table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wpvdb_embeddings';
	}

	/**
	 * Whether the FULLTEXT index is present on the embeddings table.
	 *
	 * @return bool
	 */
	public static function has_fulltext_index() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is trusted.
		$row = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", self::INDEX_NAME ) );
		return ! empty( $row );
	}

	/**
	 * Create the FULLTEXT index if it is missing. Runs once per option flag.
	 */
	public static function ensure_fulltext_index() {
		if ( get_option( self::OPTION_INSTALLED ) === '1' && self::has_fulltext_index() ) {
			return;
		}

		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is trusted.
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
			return;
		}

		if ( self::has_fulltext_index() ) {
			update_option( self::OPTION_INSTALLED, '1', false );
			return;
		}

		$index = self::INDEX_NAME;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and index names are trusted internal values.
		$wpdb->query( "ALTER TABLE {$table} ADD FULLTEXT INDEX {$index} (chunk_content)" );

		if ( empty( $wpdb->last_error ) ) {
			update_option( self::OPTION_INSTALLED, '1', false );
		}
	}
}
