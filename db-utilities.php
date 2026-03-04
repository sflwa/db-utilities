<?php
/**
 * Plugin Name: SFLWA DB Master Manager
 * Description: Master DB Diagnostics: Revision limits, manual purging, and Live vs. Schema size comparison.
 * Author: Philip Levine / SFLWA Coding
 * Version: 1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SFLWA_DB_Manager {

	public function __construct() {
		// Enforce the 3 revision limit globally
		add_filter( 'wp_revisions_to_keep', fn() => 3 );
		add_action( 'init', [ $this, 'handle_url_triggers' ] );
	}

	public function handle_url_triggers() {
		// Security: Only runs for logged-in Administrators
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_GET['sflwa_purge_revisions'] ) ) {
			$this->purge_revisions();
		}

		if ( isset( $_GET['sflwa_db_report'] ) ) {
			$this->render_comparison_report();
		}

		if ( isset( $_GET['sflwa_meta_report'] ) ) {
			$this->render_meta_report();
		}
	}

	/**
	 * Deletes revisions and orphaned meta.
	 */
	private function purge_revisions() {
		global $wpdb;
		$wpdb->query( "DELETE FROM $wpdb->posts WHERE post_type = 'revision'" );
		$wpdb->query( "DELETE FROM $wpdb->postmeta WHERE post_id NOT IN (SELECT ID FROM $wpdb->posts)" );
		wp_die( '<h1>Success</h1><p>Revisions and orphaned meta have been purged.</p><p><a href="?sflwa_db_report=1">View Efficiency Report</a></p>' );
	}

	/**
	 * Meta Detail Report (Top 10 by Size)
	 */
	private function render_meta_report() {
		global $wpdb;
		$results = $wpdb->get_results( "
			SELECT 
				meta_key AS 'Meta Key', 
				COUNT(*) AS 'Entries',
				ROUND(SUM(LENGTH(meta_value)) / 1024 / 1024, 2) AS 'Total Size (MB)',
				ROUND(MAX(LENGTH(meta_value)) / 1024, 2) AS 'Largest Single Row (KB)'
			FROM $wpdb->postmeta
			GROUP BY meta_key
			ORDER BY SUM(LENGTH(meta_value)) DESC
			LIMIT 10
		" );
		$this->print_table( 'Top 10 Post Meta Keys by Storage Size', $results );
	}

	/**
	 * Efficiency Report: Comparing what MySQL says vs. what the rows actually contain.
	 */
	private function render_comparison_report() {
		global $wpdb;

		// Get Schema stats from system
		$schema_stats = $wpdb->get_results( "
			SELECT 
				TABLE_NAME, 
				ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) as schema_mb
			FROM information_schema.TABLES 
			WHERE TABLE_SCHEMA = '" . DB_NAME . "'
			AND TABLE_NAME LIKE '{$wpdb->prefix}%'
			ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC 
			LIMIT 12
		", OBJECT_K );

		$final_report = [];

		foreach ( $schema_stats as $table_name => $stats ) {
			// Get all columns for this table
			$columns = $wpdb->get_col( "DESCRIBE `$table_name`" );
			
			// Build a query that sums the length of every single column in the table
			$length_sql = implode( ' + ', array_map( fn($c) => "LENGTH(COALESCE(`$c`, ''))", $columns ) );
			
			$live_bytes = $wpdb->get_var( "SELECT SUM($length_sql) FROM `$table_name`" );
			$live_mb = round( (float)$live_bytes / 1024 / 1024, 2 );
			$waste = round( $stats->schema_mb - $live_mb, 2 );

			$final_report[] = [
				'Table Name'      => $table_name,
				'File Size (MB)'  => $stats->schema_mb,
				'Actual Data (MB)' => $live_mb,
				'Waste Space (MB)' => ( $waste > 0 ) ? $waste : 0,
				'Efficiency %'     => ( $stats->schema_mb > 0 ) ? round( ( $live_mb / $stats->schema_mb ) * 100, 1 ) . '%' : '100%'
			];
		}

		$this->print_table( 'SFLWA Database Efficiency Report', $final_report );
	}

	private function print_table( $title, $rows ) {
		if ( empty( $rows ) ) wp_die( "No data available." );
		$headers = array_keys( (array) $rows[0] );
		echo "<html><head><style>
			body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen-Sans, Ubuntu, Cantarell, 'Helvetica Neue', sans-serif; padding: 40px; background: #f0f0f1; color: #3c434a; }
			h2 { color: #1d2327; }
			table { border-collapse: collapse; background: #fff; width: 100%; max-width: 1100px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
			th { background: #2271b1; color: #fff; text-align: left; padding: 12px; }
			td { padding: 12px; border-bottom: 1px solid #dcdcde; }
			tr:nth-child(even) { background: #f6f7f7; }
			.nav { margin-top: 20px; padding: 15px; background: #fff; display: inline-block; border-radius: 4px; border: 1px solid #dcdcde; }
			.nav a { color: #2271b1; text-decoration: none; font-weight: 600; margin-right: 15px; }
			.nav a:hover { color: #135e96; }
		</style></head><body>";
		echo "<h2>$title</h2>";
		echo "<table><thead><tr>";
		foreach ( $headers as $header ) echo "<th>" . esc_html( $header ) . "</th>";
		echo "</tr></thead><tbody>";
		foreach ( $rows as $row ) {
			echo "<tr>";
			foreach ( (array) $row as $cell ) echo "<td>" . esc_html( $cell ) . "</td>";
			echo "</tr>";
		}
		echo "</tbody></table>";
		echo "<div class='nav'>
				<strong>Tools:</strong> 
				<a href='?sflwa_db_report=1'>Detailed Efficiency Report</a> | 
				<a href='?sflwa_meta_report=1'>Meta Key Storage Detail</a> | 
				<a href='?sflwa_purge_revisions=1' onclick='return confirm(\"Are you sure you want to delete all revisions?\")'>Purge Revisions Now</a> |
				<a href='" . admin_url() . "'>Exit to Dashboard</a>
			  </div>";
		echo "</body></html>";
		exit;
	}
}

new SFLWA_DB_Manager();
