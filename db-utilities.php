<?php
/**
 * Plugin Name: SFLWA DB Master Manager
 * Description: Revision limits, manual purging, and deep-scan storage reporting (Schema vs. Calculated).
 * Author: Philip Levine / SFLWA Coding
 * Version: 1.5.0
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
		if ( ! current_user_can( 'manage_options' ) ) return;

		// 1. ?sflwa_purge_revisions=1
		if ( isset( $_GET['sflwa_purge_revisions'] ) ) {
			$this->purge_revisions();
		}

		// 2. ?sflwa_db_report=1
		if ( isset( $_GET['sflwa_db_report'] ) ) {
			$this->render_comparison_report();
		}

		// 3. ?sflwa_meta_report=1
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
		wp_die( '<h1>Success</h1><p>Revisions and orphaned meta have been purged.</p><a href="' . admin_url() . '">Back</a>' );
	}

	/**
	 * Detailed Meta Key Report (Top 10 by Size)
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
	 * Deep Scan Report: Comparing Schema Size vs. Actual Row Bytes
	 */
	private function render_comparison_report() {
		global $wpdb;

		$schema_stats = $wpdb->get_results( "
			SELECT 
				TABLE_NAME, 
				ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) as schema_mb
			FROM information_schema.TABLES 
			WHERE TABLE_SCHEMA = '" . DB_NAME . "'
			AND TABLE_NAME LIKE '{$wpdb->prefix}%'
			ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC 
			LIMIT 10
		", OBJECT_K );

		$final_report = [];

		foreach ( $schema_stats as $table_name => $stats ) {
			// Manual byte scan of the table content
			// We dynamically grab columns to avoid 'undefined column' errors on non-standard tables
			$columns = $wpdb->get_col( "DESCRIBE `$table_name`" );
			$col_length_query = "SUM(" . implode( ' + ', array_map( fn($c) => "LENGTH(COALESCE(`$c`, ''))", $columns ) ) . ")";
			
			$live_bytes = $wpdb->get_var( "SELECT $col_length_query FROM `$table_name`" );
			$live_mb = round( (float)$live_bytes / 1024 / 1024, 2 );

			$final_report[] = [
				'Table'           => $table_name,
				'Scheme (File) Size' => $stats->schema_mb . ' MB',
				'Actual Data Size'   => $live_mb . ' MB',
				'Locked/Waste'       => round( $stats->schema_mb - $live_mb, 2 ) . ' MB',
				'Efficiency'         => ( $stats->schema_mb > 0 ) ? round( ( $live_mb / $stats->schema_mb ) * 100, 1 ) . '%' : '0%'
			];
		}

		$this->print_table( 'SFLWA Database Efficiency Report', $final_report );
	}

	private function print_table( $title, $rows ) {
		if ( empty( $rows ) ) wp_die( "No data available." );
		$headers = array_keys( (array) $rows[0] );
		echo "<html><body style='font-family:sans-serif; padding:40px; background:#f0f0f1;'>";
		echo "<h2>$title</h2>";
		echo "<table border='1' cellpadding='10' style='border-collapse:collapse; background:#fff; width:100%; max-width:1100px;'>";
		echo "<tr style='background:#2271b1; color:#fff;'>";
		foreach ( $headers as $header ) echo "<th>" . esc_html( $header ) . "</th>";
		echo "</tr>";
		foreach ( $rows as $row ) {
			echo "<tr>";
			foreach ( (array) $row as $cell ) echo "<td>" . esc_html( $cell ) . "</td>";
			echo "</tr>";
		}
		echo "</table>";
		echo "<p><strong>Tools:</strong> <a href='?sflwa_db_report=1'>DB Report</a> | <a href='?sflwa_meta_report=1'>Meta Detail</a> | <a href='?sflwa_purge_revisions=1'>Purge Revisions</a></p>";
		echo "</body></html>";
		exit;
	}
}

new SFLWA_DB_Manager();
