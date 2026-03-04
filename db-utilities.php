<?php
/**
 * Plugin Name: SFLWA DB Health & Revision Manager
 * Description: Compares Schema-reported size vs. Live-calculated data size to identify locking/bloat.
 * Author: Philip Levine / SFLWA Coding
 * Version: 1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SFLWA_DB_Manager {

	public function __construct() {
		add_filter( 'wp_revisions_to_keep', fn() => 3 );
		add_action( 'init', [ $this, 'handle_url_triggers' ] );
	}

	public function handle_url_triggers() {
		if ( ! current_user_can( 'manage_options' ) ) return;

		if ( isset( $_GET['sflwa_db_report'] ) ) {
			$this->render_comparison_report();
		}
	}

	/**
	 * Compares System Scheme Size vs. Live Data Calculation.
	 */
	private function render_comparison_report() {
		global $wpdb;

		// 1. Get Schema Sizes (What the OS/MySQL claims)
		$schema_stats = $wpdb->get_results( "
			SELECT 
				TABLE_NAME, 
				ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) as schema_mb,
				DATA_FREE / 1024 / 1024 as overhead_mb
			FROM information_schema.TABLES 
			WHERE TABLE_SCHEMA = '" . DB_NAME . "'
			AND TABLE_NAME LIKE '{$wpdb->prefix}%'
			ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC 
			LIMIT 10
		", OBJECT_K );

		$final_report = [];

		// 2. Calculate Live Sizes (Manual row scan)
		foreach ( $schema_stats as $table_name => $stats ) {
			// We calculate the length of all columns to get the "Live" data footprint
			// Note: This is an approximation of raw data volume.
			$live_data = $wpdb->get_var( "
				SELECT SUM(
					LENGTH(COALESCE(post_id, '')) + 
					LENGTH(COALESCE(meta_key, '')) + 
					LENGTH(COALESCE(meta_value, ''))
				) / 1024 / 1024 
				FROM `$table_name`
			" );
			
			// If it's not the postmeta table, we use a simpler count for the report
			if ( strpos( $table_name, 'postmeta' ) === false ) {
				$live_data = "Scan skipped (Meta Only)";
			} else {
				$live_data = round( (float)$live_data, 2 ) . ' MB';
			}

			$final_report[] = [
				'Table'           => $table_name,
				'Scheme Size'     => $stats->schema_mb . ' MB',
				'Calculated Data' => $live_data,
				'Hidden Bloat'    => round( $stats->schema_mb - (float)$live_data, 2 ) . ' MB',
				'Status'          => ( $stats->schema_mb > 50 && (float)$live_data < 20 ) ? '⚠️ LOCKED/BLOATED' : 'OK'
			];
		}

		$this->print_table( 'Schema vs. Manual Calculation Report', $final_report );
	}

	private function print_table( $title, $rows ) {
		$headers = array_keys( (array) $rows[0] );
		echo "<html><body style='font-family:sans-serif; padding:40px; background:#f0f0f1;'>";
		echo "<h2>$title</h2>";
		echo "<table border='1' cellpadding='10' style='border-collapse:collapse; background:#fff; width:100%; max-width:1000px;'>";
		echo "<tr style='background:#2271b1; color:#fff;'>";
		foreach ( $headers as $header ) echo "<th>" . esc_html( $header ) . "</th>";
		echo "</tr>";
		foreach ( $rows as $row ) {
			$style = ( strpos( $row['Status'], 'LOCKED' ) !== false ) ? 'style="background:#fff2f2; font-weight:bold;"' : '';
			echo "<tr $style>";
			foreach ( (array) $row as $cell ) echo "<td>" . esc_html( $cell ) . "</td>";
			echo "</tr>";
		}
		echo "</table><p><a href='" . admin_url() . "'>&laquo; Back to Dashboard</a></p></body></html>";
		exit;
	}
}

new SFLWA_DB_Manager();
