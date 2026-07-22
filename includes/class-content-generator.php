<?php
/**
 * Content Generator Class
 *
 * トピッククラスター構成の生成プロセス、WP-Cron、予約投稿、LP再生成を制御します。
 *
 * @package Autopilot_LP_Funnel_Builder
 */

// 直接アクセスを禁止
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Autopilot_LP_Funnel_Builder_Content_Generator {

	/**
	 * Gemini API クライアントインスタンス
	 *
	 * @var Autopilot_LP_Funnel_Builder_Gemini_Client
	 */
	private $gemini_client;

	/**
	 * コンストラクタ
	 *
	 * @param Autopilot_LP_Funnel_Builder_Gemini_Client $gemini_client Geminiクライアント。
	 */
	public function __construct( $gemini_client ) {
		$this->gemini_client = $gemini_client;
	}

	/**
	 * プロジェクトの全データを取得
	 */
	public function get_projects() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'alp_projects';
		return $wpdb->get_results( "SELECT * FROM $table_name ORDER BY id DESC", ARRAY_A );
	}

	/**
	 * 特定プロジェクトのデータを取得
	 */
	public function get_project( $id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'alp_projects';
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE id = %d", $id ), ARRAY_A );
	}

	/**
	 * プロジェクトの保存/更新
	 */
	public function save_project( $data ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'alp_projects';

		$project_id = isset( $data['id'] ) ? intval( $data['id'] ) : 0;

		$db_data = array(
			'name'          => sanitize_text_field( $data['name'] ),
			'source_url'    => esc_url_raw( $data['source_url'] ),
			'target_region' => sanitize_text_field( $data['target_region'] ),
			'keyword'       => sanitize_text_field( $data['keyword'] ),
			'cv_url'        => esc_url_raw( $data['cv_url'] ),
			'design_url'    => esc_url_raw( $data['design_url'] ),
			'interval_days' => max( 1, intval( $data['interval_days'] ) ),
		);

		if ( $project_id > 0 ) {
			$wpdb->update( $table_name, $db_data, array( 'id' => $project_id ) );
			return $project_id;
		} else {
			$db_data['status'] = 'pending';
			$wpdb->insert( $table_name, $db_data );
			return $wpdb->insert_id;
		}
	}

	/**
	 * プロジェクトの削除（関連するキューや固定ページ・投稿も任意でクリーンアップ可能）
	 */
	public function delete_project( $id ) {
		global $wpdb;
		$table_projects = $wpdb->prefix . 'alp_projects';
		$table_queue    = $wpdb->prefix . 'alp_generation_queue';

		// プロジェクト情報の取得
		$project = $this->get_project( $id );
		if ( $project ) {
			// 生成されたLPの削除
			if ( ! empty( $project['lp_post_id'] ) ) {
				wp_delete_post( $project['lp_post_id'], true );
			}

			// 生成された記事投稿の削除
			$queue_items = $wpdb->get_results( $wpdb->prepare( "SELECT post_id FROM $table_queue WHERE project_id = %d", $id ) );
			foreach ( $queue_items as $item ) {
				if ( ! empty( $item->post_id ) ) {
					wp_delete_post( $item->post_id, true );
				}
			}
		}

		// DBレコード削除
		$wpdb->delete( $table_projects, array( 'id' => $id ) );
		$wpdb->delete( $table_queue, array( 'project_id' => $id ) );
	}

	/**
	 * プロジェクトのステータス更新
	 */
	public function update_project_status( $id, $status ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'alp_projects';
		$wpdb->update( $table_name, array( 'status' => $status ), array( 'id' => $id ) );
	}

	/**
	 * キューアイテムの一覧を取得
	 */
	public function get_queue_items( $project_id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'alp_generation_queue';
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table_name WHERE project_id = %d ORDER BY id ASC", $project_id ), ARRAY_A );
	}

	/**
	 * 次に生成すべきキューアイテムを1件取得
	 */
	public function get_next_queue_item( $project_id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'alp_generation_queue';
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table_name WHERE project_id = %d AND post_status IN ('pending', 'failed') ORDER BY id ASC LIMIT 1",
				$project_id
			),
			ARRAY_A
		);
	}

	/**
	 * プロジェクトの進捗率をパーセントで取得
	 */
	public function get_project_progress( $project_id ) {
		global $wpdb;
		$table_queue = $wpdb->prefix . 'alp_generation_queue';

		$total = intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table_queue WHERE project_id = %d", $project_id ) ) );
		if ( 0 === $total ) {
			return 0;
		}

		$completed = intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table_queue WHERE project_id = %d AND post_status = 'completed'", $project_id ) ) );

		// LPが生成されていれば+1として計算に含める
		$project = $this->get_project( $project_id );
		$total_steps = $total + 1;
		$completed_steps = $completed;
		if ( ! empty( $project['lp_post_id'] ) ) {
			$completed_steps += 1;
		}

		return round( ( $completed_steps / $total_steps ) * 100 );
	}

	/**
	 * フェーズ1: 構成設計案を策定し、キューとLP下書きを作成する
	 *
	 * @param int $project_id プロジェクトID。
	 * @return true|WP_Error
	 */
	public function start_initial_generation( $project_id ) {
		global $wpdb;
		$project = $this->get_project( $project_id );
		if ( ! $project ) {
			return new WP_Error( 'not_found', 'プロジェクトが見つかりません。' );
		}

		$this->update_project_status( $project_id, 'generating' );

		// 1. 対象URLをスクレイピング
		$scraped_content = '';
		if ( ! empty( $project['source_url'] ) ) {
			$scraped_content = $this->gemini_client->scrape_url( $project['source_url'] );
		}

		// 2. Geminiで構成案を生成 (JSON)
		$plan = $this->gemini_client->generate_cluster_plan( $project, $scraped_content );
		if ( is_wp_error( $plan ) ) {
			$this->update_project_status( $project_id, 'failed' );
			return $plan;
		}

		// 3. LPの初期作成（下書きとして空枠、またはシンプルな紹介テキスト）
		$lp_title = ! empty( $plan['lp_title'] ) ? sanitize_text_field( $plan['lp_title'] ) : '成約用ランディングページ - ' . $project['name'];
		
		$lp_post_id = wp_insert_post(
			array(
				'post_title'   => $lp_title,
				'post_content' => '<!-- LP生成中... しばらくお待ちください -->',
				'post_status'  => 'draft',
				'post_type'    => 'page',
			)
		);

		if ( is_wp_error( $lp_post_id ) || 0 === $lp_post_id ) {
			$this->update_project_status( $project_id, 'failed' );
			return new WP_Error( 'lp_creation_failed', 'LP用の固定ページの作成に失敗しました。' );
		}

		// プロジェクトにLPのIDを書き込む
		$wpdb->update(
			$wpdb->prefix . 'alp_projects',
			array( 'lp_post_id' => $lp_post_id ),
			array( 'id' => $project_id )
		);

		// 4. キューテーブルを一度リセットしてから追加
		$table_queue = $wpdb->prefix . 'alp_generation_queue';
		$wpdb->delete( $table_queue, array( 'project_id' => $project_id ) );

		// スケジュール日時の計算用
		$interval_days = intval( $project['interval_days'] );
		$current_time  = current_time( 'timestamp' ); // WordPress設定のローカルタイムスタンプ

		// 毎朝9:00に予約投稿されるように調整
		$base_time = strtotime( 'tomorrow 09:00:00', $current_time );

		$index = 0;
		foreach ( $plan['articles'] as $article ) {
			$scheduled_time = $base_time + ( $index * $interval_days * DAY_IN_SECONDS );
			$scheduled_at   = date( 'Y-m-d H:i:s', $scheduled_time );

			$wpdb->insert(
				$table_queue,
				array(
					'project_id'   => $project_id,
					'title'        => sanitize_text_field( $article['title'] ),
					'headings'     => wp_json_encode( $article['headings'] ),
					'description'  => sanitize_text_field( $article['description'] ),
					'post_status'  => 'pending',
					'scheduled_at' => $scheduled_at,
				)
			);
			$index++;
		}

		return true;
	}

	/**
	 * 指定プロジェクトのLP（固定ページ）を初回生成する
	 *
	 * @param int $project_id プロジェクトID。
	 * @return true|WP_Error
	 */
	public function generate_lp_content( $project_id ) {
		global $wpdb;
		$project = $this->get_project( $project_id );
		if ( ! $project || empty( $project['lp_post_id'] ) ) {
			return new WP_Error( 'invalid_project', '有効なLP固定ページが紐付いていません。' );
		}

		$scraped_content = '';
		if ( ! empty( $project['source_url'] ) ) {
			$scraped_content = $this->gemini_client->scrape_url( $project['source_url'] );
		}

		// GeminiでLP HTMLコードを新規生成
		$lp_html = $this->gemini_client->regenerate_lp_content( $project, $scraped_content );
		if ( is_wp_error( $lp_html ) ) {
			return $lp_html;
		}

		// 固定ページを「公開」ステータスで更新
		$post_data = array(
			'ID'           => $project['lp_post_id'],
			'post_content' => $lp_html,
			'post_status'  => 'publish', // CVR成約用に公開
		);

		$update_result = wp_update_post( $post_data, true );
		if ( is_wp_error( $update_result ) ) {
			return $update_result;
		}

		return true;
	}

	/**
	 * ユーザーフィードバック指示に従ってLPを再生成・微修正する
	 *
	 * @param int    $project_id プロジェクトID。
	 * @param string $feedback   AIへの修正指示。
	 * @return true|WP_Error
	 */
	public function refine_lp( $project_id, $feedback ) {
		global $wpdb;
		$project = $this->get_project( $project_id );
		if ( ! $project || empty( $project['lp_post_id'] ) ) {
			return new WP_Error( 'invalid_project', '有効なLP固定ページが紐付いていません。' );
		}

		if ( empty( $feedback ) ) {
			return new WP_Error( 'empty_feedback', '修正指示を入力してください。' );
		}

		// 現在のLPのHTMLコードを取得
		$lp_post = get_post( $project['lp_post_id'] );
		$current_html = $lp_post ? $lp_post->post_content : '';

		$scraped_content = '';
		if ( ! empty( $project['source_url'] ) ) {
			$scraped_content = $this->gemini_client->scrape_url( $project['source_url'] );
		}

		// GeminiでLPを微修正して再生成
		$new_html = $this->gemini_client->regenerate_lp_content( $project, $scraped_content, $feedback, $current_html );
		if ( is_wp_error( $new_html ) ) {
			return $new_html;
		}

		// 固定ページの内容を上書き更新
		$post_data = array(
			'ID'           => $project['lp_post_id'],
			'post_content' => $new_html,
		);

		$update_result = wp_update_post( $post_data, true );
		if ( is_wp_error( $update_result ) ) {
			return $update_result;
		}

		// DBレコードのフィードバック情報を更新
		$wpdb->update(
			$wpdb->prefix . 'alp_projects',
			array( 'lp_feedback' => sanitize_textarea_field( $feedback ) ),
			array( 'id' => $project_id )
		);

		return true;
	}

	/**
	 * フェーズ2: キュー内の次の記事を1件生成して予約投稿としてWordPressに挿入する
	 *
	 * @param int $project_id プロジェクトID。
	 * @return true|string|WP_Error 生成完了時はtrue、全完了時は'all_completed'、失敗時はWP_Error。
	 */
	public function generate_next_queued_article( $project_id ) {
		global $wpdb;
		$project = $this->get_project( $project_id );
		if ( ! $project ) {
			return new WP_Error( 'not_found', 'プロジェクトが見つかりません。' );
		}

		// LPがまだ生成されていない場合はまずLPを生成する
		// (LPのURLが記事内のリンクに必要であるため)
		$lp_url = get_permalink( $project['lp_post_id'] );
		$lp_post = get_post( $project['lp_post_id'] );
		if ( $lp_post && '<!-- LP生成中... しばらくお待ちください -->' === trim( $lp_post->post_content ) ) {
			$lp_result = $this->generate_lp_content( $project_id );
			if ( is_wp_error( $lp_result ) ) {
				return $lp_result;
			}
			return true; // 今回のステップはLP生成で終了（Ajaxから再度叩かれる）
		}

		$queue_item = $this->get_next_queue_item( $project_id );
		if ( ! $queue_item ) {
			// すべての記事が生成完了
			$this->update_project_status( $project_id, 'completed' );
			return 'all_completed';
		}

		$table_queue = $wpdb->prefix . 'alp_generation_queue';

		// ステータスを「生成中」に更新
		$wpdb->update( $table_queue, array( 'post_status' => 'generating' ), array( 'id' => $queue_item['id'] ) );

		$headings = json_decode( $queue_item['headings'], true );
		if ( ! is_array( $headings ) ) {
			$headings = array();
		}

		// Geminiで記事本文を生成
		$article_html = $this->gemini_client->generate_article_content( $project, $queue_item['title'], $headings, $lp_url );

		if ( is_wp_error( $article_html ) ) {
			// 失敗ステータスとエラーメッセージを記録
			$wpdb->update(
				$table_queue,
				array(
					'post_status'   => 'failed',
					'error_message' => $article_html->get_error_message(),
				),
				array( 'id' => $queue_item['id'] )
			);
			return $article_html;
		}

		// 新規投稿カテゴリーを作成（なければ）
		$category_name = $project['name'] . '集客記事';
		$cat_id        = get_cat_ID( $category_name );
		if ( 0 === $cat_id ) {
			$cat_id = wp_create_category( $category_name );
		}

		// WordPressへ予約投稿として登録
		$post_arr = array(
			'post_title'     => $queue_item['title'],
			'post_content'   => $article_html,
			'post_status'    => 'future', // 予約投稿
			'post_date'      => $queue_item['scheduled_at'], // 未来の日時を指定
			'post_date_gmt'  => get_gmt_from_date( $queue_item['scheduled_at'] ),
			'post_type'      => 'post',
			'post_category'  => array( $cat_id ),
		);

		$post_id = wp_insert_post( $post_arr, true );

		if ( is_wp_error( $post_id ) ) {
			$wpdb->update(
				$table_queue,
				array(
					'post_status'   => 'failed',
					'error_message' => $post_id->get_error_message(),
				),
				array( 'id' => $queue_item['id'] )
			);
			return $post_id;
		}

		// メタディスクリプションの設定（All in One SEOやRank Math用等、カスタムフィールドにも保存）
		update_post_meta( $post_id, '_yoast_wpseo_metadesc', $queue_item['description'] ); // Yoast SEO互換
		update_post_meta( $post_id, '_description', $queue_item['description'] ); // 汎用

		// 完了ステータスをDBに記録
		$wpdb->update(
			$table_queue,
			array(
				'post_status' => 'completed',
				'post_id'     => $post_id,
			),
			array( 'id' => $queue_item['id'] )
		);

		// 次の記事があるかチェック。なければステータスを完了にする
		$next = $this->get_next_queue_item( $project_id );
		if ( ! $next ) {
			$this->update_project_status( $project_id, 'completed' );
		}

		return true;
	}
}
