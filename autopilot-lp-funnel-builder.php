<?php
/**
 * Plugin Name:       Autopilot LP Funnel Builder
 * Plugin URI:        https://github.com/shige3work/autopilot-lp-funnel-builder
 * Description:       Gemini APIを活用して、WordPressサイト上で「用途別の成約用LP（固定ページ）」および「トピッククラスクラスター構成の集客記事群（投稿）」を自動生成・予約公開するWordPressプラグイン。
 * Version:           1.0.5
 * Author:            shige3work
 * Author URI:        https://github.com/shige3work
 * License:           GPL-2.0-or-later
 * Text Domain:       autopilot-lp-funnel-builder
 * GitHub Plugin URI: shige3work/autopilot-lp-funnel-builder
 * Primary Branch:    main
 */

// 直接アクセスを禁止
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 定数の定義
define( 'AUTOPILOT_LP_FUNNEL_BUILDER_VERSION', '1.0.5' );
define( 'AUTOPILOT_LP_FUNNEL_BUILDER_PATH', plugin_dir_path( __FILE__ ) );
define( 'AUTOPILOT_LP_FUNNEL_BUILDER_URL', plugin_dir_url( __FILE__ ) );

// クラスの読み込み
require_once AUTOPILOT_LP_FUNNEL_BUILDER_PATH . 'includes/class-github-updater.php';
require_once AUTOPILOT_LP_FUNNEL_BUILDER_PATH . 'includes/class-gemini-client.php';
require_once AUTOPILOT_LP_FUNNEL_BUILDER_PATH . 'includes/class-content-generator.php';
require_once AUTOPILOT_LP_FUNNEL_BUILDER_PATH . 'includes/class-admin-settings.php';

/**
 * プラグイン有効化時の処理（カスタムテーブル作成など）
 */
function autopilot_lp_funnel_builder_activate() {
	global $wpdb;

	$charset_collate = $wpdb->get_charset_collate();

	// 1. プロジェクト管理テーブル
	$table_projects = $wpdb->prefix . 'alp_projects';
	$sql_projects = "CREATE TABLE $table_projects (
		id bigint(20) NOT NULL AUTO_INCREMENT,
		name varchar(255) NOT NULL,
		source_url text DEFAULT NULL,
		target_region varchar(255) DEFAULT NULL,
		keyword varchar(255) NOT NULL,
		cv_url text NOT NULL,
		design_url text DEFAULT NULL,
		interval_days int(11) NOT NULL DEFAULT 1,
		lp_post_id bigint(20) DEFAULT NULL,
		lp_feedback text DEFAULT NULL,
		status varchar(50) NOT NULL DEFAULT 'pending',
		created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
		PRIMARY KEY  (id)
	) $charset_collate;";

	// 2. 生成ジョブキューテーブル（トピッククラスター記事用）
	$table_queue = $wpdb->prefix . 'alp_generation_queue';
	$sql_queue = "CREATE TABLE $table_queue (
		id bigint(20) NOT NULL AUTO_INCREMENT,
		project_id bigint(20) NOT NULL,
		title varchar(255) NOT NULL,
		headings text DEFAULT NULL,
		description text DEFAULT NULL,
		post_id bigint(20) DEFAULT NULL,
		post_status varchar(50) NOT NULL DEFAULT 'pending',
		error_message text DEFAULT NULL,
		scheduled_at datetime DEFAULT NULL,
		created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
		PRIMARY KEY  (id),
		KEY project_id (project_id)
	) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql_projects );
	dbDelta( $sql_queue );
}
register_activation_hook( __FILE__, 'autopilot_lp_funnel_builder_activate' );

/**
 * プラグイン初期化
 */
function autopilot_lp_funnel_builder_init() {
	// 各コンポーネントクラスの初期化
	$github_updater = new Autopilot_LP_Funnel_Builder_Github_Updater();
	$gemini_client   = new Autopilot_LP_Funnel_Builder_Gemini_Client();
	$generator       = new Autopilot_LP_Funnel_Builder_Content_Generator( $gemini_client );
	$admin_settings  = new Autopilot_LP_Funnel_Builder_Admin_Settings( $generator, $gemini_client );
}
add_action( 'plugins_loaded', 'autopilot_lp_funnel_builder_init' );
