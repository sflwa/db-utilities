<?php
/**
 * Plugin Name: SFLWA DB Master Manager
 * Description: Master DB Diagnostics: Revision limits, manual purging, and Row/Size comparison (Schema vs. Live).
 * Author: Philip Levine / SFLWA Coding
 * Version: 1.7.0

 Place this file in the mu-plugins folder
Efficiency Report: ?sflwa_db_report=1
Meta Detail: ?sflwa_meta_report=1
Purge Revisions: ?sflwa_purge_revisions=1
 
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

	private function purge_revisions() {
		global $wpdb;
		$wpdb->query( "DELETE FROM $wpdb->posts WHERE post_type = 'revision'" );
		$wpdb->query( "DELETE FROM $wpdb->postmeta WHERE post_id NOT IN (SELECT ID FROM $wpdb->posts)" );
		wp_die( '<h1>Success</h1><p>Revisions purged.</p><p><a href="?sflwa_db_report=1">View Efficiency Report</a></p>' );
	}

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

	private function render_comparison_report() {
		global $wpdb;

		$schema_stats = $wpdb->get_results( "
			SELECT 
				TABLE_NAME, 
				TABLE_ROWS as schema_rows,
				ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) as schema_mb
			FROM information_schema.TABLES 
			WHERE TABLE_SCHEMA = '" . DB_NAME . "'
			AND TABLE_NAME LIKE '{$wpdb->prefix}%'
			ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC 
			LIMIT 12
		", OBJECT_K );

		$final_report = [];

		foreach ( $schema_stats as $table_name => $stats ) {
			// 1. Get Live Row Count
			$live_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table_name`" );

			// 2. Get Live Data Size (Sum of all column lengths)
			$columns = $wpdb->get_col( "DESCRIBE `$table_name`" );
			$length_sql = implode( ' + ', array_map( fn($c) => "LENGTH(COALESCE(`$c`, ''))", $columns ) );
			$live_bytes = $wpdb->get_var( "SELECT SUM($length_sql) FROM `$table_name`" );
			
			$live_mb = round( (float)$live_bytes / 1024 / 1024, 2 );
			$waste = round( $stats->schema_mb - $live_mb, 2 );

			$final_report[] = [
				'Table Name'      => $table_name,
				'Reported Rows'   => number_format( $stats->schema_rows ),
				'Actual Rows'     => number_format( $live_rows ),
				'File Size (MB)'  => $stats->schema_mb,
				'Actual Data (MB)' => $live_mb,
				'Waste (MB)'      => ( $waste > 0 ) ? $waste : 0,
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
			h2 { color: #1d2327; margin-bottom: 20px; }
			table { border-collapse: collapse; background: #fff; width: 100%; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-radius: 8px; overflow: hidden; }
			th { background: #2271b1; color: #fff; text-align: left; padding: 14px 12px; font-size: 13px; text-transform: uppercase; letter-spacing: 0.05em; }
			td { padding: 12px; border-bottom: 1px solid #dcdcde; font-size: 14px; }
			tr:nth-child(even) { background: #f6f7f7; }
			tr:hover { background: #f0f6fb; }
			.nav { margin-top: 30px; padding: 20px; background: #fff; display: inline-block; border-radius: 8px; border: 1px solid #dcdcde; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
			.nav a { color: #2271b1; text-decoration: none; font-weight: 600; margin-right: 20px; border-bottom: 1px solid transparent; transition: all 0.2s; }
			.nav a:hover { border-bottom: 1px solid #2271b1; }
			.nav strong { color: #50575e; margin-right: 15px; }
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
				<a href='?sflwa_db_report=1'>Detailed Efficiency Report</a> 
				<a href='?sflwa_meta_report=1'>Meta Key Storage Detail</a> 
				<a href='?sflwa_purge_revisions=1' onclick='return confirm(\"Are you sure you want to delete all revisions?\")'>Purge Revisions Now</a>
				<a href='" . admin_url() . "' style='color: #d63638;'>Exit to Dashboard</a>
			  </div>";
		echo "</body></html>";
		exit;
	}
}

new SFLWA_DB_Manager();
