<?php
/**
 * Plugin Name:      WPVDB Search
 * Plugin URI:       https://github.com/rbcorrales/wpvdb-search
 * Description:      Shared dense, sparse, and hybrid search primitives for wpvdb.
 * Version:          0.2.0
 * Author:           Automattic, Ramon Corrales
 * Author URI:       https://automattic.com/
 * Requires PHP:     7.4
 * Requires Plugins: wpvdb
 * License:          GPL-2.0-or-later
 * License URI:      https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:      wpvdb-search
 * Domain Path:      /languages
 *
 * @package WPVDB_Search
 */

namespace WPVDB_Search;

defined( 'ABSPATH' ) || exit;

define( 'WPVDB_SEARCH_VERSION', '0.2.0' );
define( 'WPVDB_SEARCH_FILE', __FILE__ );
define( 'WPVDB_SEARCH_DIR', __DIR__ );

require_once __DIR__ . '/includes/class-schema.php';
require_once __DIR__ . '/includes/class-search.php';
require_once __DIR__ . '/includes/class-abilities.php';

/**
 * Show an admin notice when wpvdb is missing.
 */
function dependency_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	?>
	<div class="notice notice-error">
		<p><?php esc_html_e( 'WPVDB Search requires the wpvdb plugin to be active.', 'wpvdb-search' ); ?></p>
	</div>
	<?php
}

add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain(
			'wpvdb-search',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);

		if ( ! class_exists( '\WPVDB\Core' ) || ! class_exists( '\WPVDB\Settings' ) ) {
			add_action( 'admin_notices', __NAMESPACE__ . '\\dependency_notice' );
			return;
		}

		Schema::init();
		Abilities::init();
	}
);
