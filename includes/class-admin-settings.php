<?php
/**
 * Admin Settings Class
 *
 * WordPress管理画面の設定ページ、各種Ajax処理、およびUIデザインの出力を管理します。
 *
 * @package Autopilot_LP_Funnel_Builder
 */

// 直接アクセスを禁止
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Autopilot_LP_Funnel_Builder_Admin_Settings {

	/**
	 * コンテンツジェネレーターインスタンス
	 *
	 * @var Autopilot_LP_Funnel_Builder_Content_Generator
	 */
	private $generator;

	/**
	 * Gemini API クライアントインスタンス
	 *
	 * @var Autopilot_LP_Funnel_Builder_Gemini_Client
	 */
	private $gemini_client;

	/**
	 * コンストラクタ
	 */
	public function __construct( $generator, $gemini_client ) {
		$this->generator     = $generator;
		$this->gemini_client = $gemini_client;

		// アクション・フィルターの登録
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// AJAXハンドラーの登録
		add_action( 'wp_ajax_alp_save_api_key', array( $this, 'ajax_save_api_key' ) );
		add_action( 'wp_ajax_alp_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_alp_save_project', array( $this, 'ajax_save_project' ) );
		add_action( 'wp_ajax_alp_delete_project', array( $this, 'ajax_delete_project' ) );
		add_action( 'wp_ajax_alp_start_generation', array( $this, 'ajax_start_generation' ) );
		add_action( 'wp_ajax_alp_generate_step', array( $this, 'ajax_generate_step' ) );
		add_action( 'wp_ajax_alp_refine_lp', array( $this, 'ajax_refine_lp' ) );
	}

	/**
	 * 管理画面メニューの登録
	 */
	public function register_menu() {
		add_menu_page(
			'Autopilot LP',
			'Autopilot LP',
			'manage_options',
			'autopilot-lp-funnel-builder',
			array( $this, 'render_admin_page' ),
			'dashicons-art',
			30
		);
	}

	/**
	 * アセット（CSS・JS）の読み込み
	 */
	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_autopilot-lp-funnel-builder' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'alp-admin-style',
			AUTOPILOT_LP_FUNNEL_BUILDER_URL . 'assets/admin-style.css',
			array(),
			AUTOPILOT_LP_FUNNEL_BUILDER_VERSION
		);

		wp_enqueue_script(
			'alp-admin-script',
			AUTOPILOT_LP_FUNNEL_BUILDER_URL . 'assets/admin-script.js',
			array( 'jquery' ),
			AUTOPILOT_LP_FUNNEL_BUILDER_VERSION,
			true
		);

		// JavaScriptにデータを渡す
		wp_localize_script(
			'alp-admin-script',
			'alpData',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'alp_admin_nonce' ),
				'strings'  => array(
					'saving'            => '保存中...',
					'saved'             => '保存しました！',
					'testing'           => '接続テスト中...',
					'testing_success'   => '接続成功！Gemini APIと通信可能です。',
					'generating_plan'   => '構成案を策定しています (最大1分ほどかかる場合があります)...',
					'generating_step'   => '記事・LPを順番に生成しています。ページを閉じずにこのままお待ちください...',
					'generation_finish' => 'すべてのLPおよび記事の生成・予約投稿が完了しました！',
					'refining_lp'       => '指示に基づきLPを修正・再生成しています (最大1分ほどかかる場合があります)...',
					'refining_success'  => 'LPの修正と更新が完了しました！',
				),
			)
		);
	}

	/**
	 * 管理画面UIのレンダリング
	 */
	public function render_admin_page() {
		$api_key = get_option( 'alp_gemini_api_key', '' );
		$projects = $this->generator->get_projects();
		?>
		<div class="wrap alp-wrap">
			<header class="alp-header">
				<div class="alp-header-title-area">
					<span class="dashicons dashicons-art alp-main-icon"></span>
					<h1>Autopilot LP Funnel Builder <span class="alp-badge">V1 MVP</span></h1>
				</div>
				<p class="alp-tagline">Gemini 3.5 Flash-Liteを活用し、成約用LPとSEOトピッククラスター記事群をWordPress上に自動構築・予約公開します。</p>
			</header>

			<!-- タブメニューナビゲーション -->
			<nav class="alp-nav-tabs">
				<a href="#projects" class="alp-nav-tab active"><span class="dashicons dashicons-list-view"></span> プロジェクト管理</a>
				<a href="#new-project" class="alp-nav-tab" id="alp-new-project-tab-btn"><span class="dashicons dashicons-plus-alt"></span> 新規プロジェクト作成</a>
				<a href="#settings" class="alp-nav-tab"><span class="dashicons dashicons-admin-generic"></span> API基本設定</a>
			</nav>

			<div class="alp-tab-content-wrapper">
				
				<!-- 1. プロジェクト一覧タブ -->
				<div id="tab-projects" class="alp-tab-content active">
					<div class="alp-card-header">
						<h2>アクティブ・プロジェクト一覧</h2>
						<p>作成済みの用途別プロジェクトの確認、および自動生成の実行・進捗管理が行えます。</p>
					</div>

					<div class="alp-projects-list">
						<?php if ( empty( $projects ) ) : ?>
							<div class="alp-empty-state">
								<span class="dashicons dashicons-welcome-write-blog"></span>
								<p>まだプロジェクトが登録されていません。上の「新規プロジェクト作成」タブから最初のプロジェクトを作成してください。</p>
							</div>
						<?php else : ?>
							<?php foreach ( $projects as $project ) : ?>
								<?php 
									$progress = $this->generator->get_project_progress( $project['id'] );
									$queue_items = $this->generator->get_queue_items( $project['id'] );
									$lp_url = ! empty( $project['lp_post_id'] ) ? get_permalink( $project['lp_post_id'] ) : '';
								?>
								<div class="alp-project-card" id="project-card-<?php echo esc_attr( $project['id'] ); ?>">
									<div class="alp-project-card-header">
										<div>
											<h3 class="alp-project-name"><?php echo esc_html( $project['name'] ); ?></h3>
											<div class="alp-project-meta">
												<span class="alp-meta-item"><strong>キーワード:</strong> <?php echo esc_html( $project['keyword'] ); ?></span>
												<?php if ( ! empty( $project['target_region'] ) ) : ?>
													<span class="alp-meta-item"><strong>地域:</strong> <?php echo esc_html( $project['target_region'] ); ?></span>
												<?php endif; ?>
												<span class="alp-meta-item"><strong>公開間隔:</strong> <?php echo esc_html( $project['interval_days'] ); ?>日に1本</span>
											</div>
										</div>
										<div class="alp-project-status-badge status-<?php echo esc_attr( $project['status'] ); ?>">
											<?php 
												switch ( $project['status'] ) {
													case 'pending': echo '生成前'; break;
													case 'generating': echo '生成中'; break;
													case 'completed': echo '生成完了'; break;
													case 'failed': echo '生成失敗'; break;
												}
											?>
										</div>
									</div>

									<div class="alp-project-card-body">
										<!-- 進捗バー -->
										<div class="alp-progress-wrapper">
											<div class="alp-progress-info">
												<span>コンテンツ構築進捗</span>
												<span class="alp-progress-percent" id="progress-percent-<?php echo esc_attr( $project['id'] ); ?>"><?php echo esc_html( $progress ); ?>%</span>
											</div>
											<div class="alp-progress-bar">
												<div class="alp-progress-fill" id="progress-fill-<?php echo esc_attr( $project['id'] ); ?>" style="width: <?php echo esc_attr( $progress ); ?>%;"></div>
											</div>
										</div>

										<!-- アクションボタンエリア -->
										<div class="alp-project-actions">
											<?php if ( 'completed' !== $project['status'] && 'generating' !== $project['status'] ) : ?>
												<button class="button button-primary alp-btn-generate" data-id="<?php echo esc_attr( $project['id'] ); ?>">
													<span class="dashicons dashicons-controls-play"></span> 自動生成を開始
												</button>
											<?php elseif ( 'generating' === $project['status'] ) : ?>
												<button class="button button-primary alp-btn-generate" data-id="<?php echo esc_attr( $project['id'] ); ?>" disabled>
													<span class="spinner is-active"></span> 生成処理中...
												</button>
											<?php endif; ?>

											<button class="button alp-btn-edit" data-project='<?php echo esc_attr( wp_json_encode( $project ) ); ?>'>
												<span class="dashicons dashicons-edit"></span> 設定編集
											</button>

											<?php if ( ! empty( $lp_url ) ) : ?>
												<a href="<?php echo esc_url( $lp_url ); ?>" class="button alp-btn-view-lp" target="_blank">
													<span class="dashicons dashicons-visibility"></span> LPを表示
												</a>
												<button class="button alp-btn-refine-toggle" data-id="<?php echo esc_attr( $project['id'] ); ?>">
													<span class="dashicons dashicons-update"></span> AI指示でLPを修正
												</button>
											<?php endif; ?>

											<button class="button alp-btn-delete" data-id="<?php echo esc_attr( $project['id'] ); ?>">
												<span class="dashicons dashicons-trash"></span> 削除
											</button>
										</div>

										<!-- AI指示付きLP修正フォーム（トグル表示） -->
										<?php if ( ! empty( $lp_url ) ) : ?>
											<div class="alp-refine-form-wrapper" id="refine-form-<?php echo esc_attr( $project['id'] ); ?>" style="display: none;">
												<h4>AI指示によるLPデザイン・内容の微修正</h4>
												<p class="description">現在のLPの内容をもとに、AIに修正指示を出して再構成します。指示の例: 「もっとキャッチコピーを親しみやすく」「全体のテーマカラーを信頼感のある濃紺にして」「フォーム近くに『無料相談の流れ』セクションを3ステップで追加して」など。</p>
												<div class="alp-refine-textarea-container">
													<textarea id="refine-feedback-<?php echo esc_attr( $project['id'] ); ?>" placeholder="AIへの修正指示を入力してください..." rows="4"><?php echo esc_textarea( $project['lp_feedback'] ); ?></textarea>
												</div>
												<div class="alp-refine-form-actions">
													<button class="button button-primary alp-btn-submit-refine" data-id="<?php echo esc_attr( $project['id'] ); ?>">
														再生成を実行する
													</button>
													<button class="button alp-btn-refine-cancel" data-id="<?php echo esc_attr( $project['id'] ); ?>">
														キャンセル
													</button>
												</div>
											</div>
										<?php endif; ?>

										<!-- トピッククラスター構成テーブル（詳細展開） -->
										<?php if ( ! empty( $queue_items ) ) : ?>
											<div class="alp-cluster-details-toggle">
												<a href="#" class="alp-toggle-details-link" data-id="<?php echo esc_attr( $project['id'] ); ?>">
													トピッククラスター構成・予約投稿スケジュールを表示する
												</a>
											</div>
											<div class="alp-cluster-table-wrapper" id="cluster-table-<?php echo esc_attr( $project['id'] ); ?>" style="display: none;">
												<table class="wp-list-table widefat fixed striped">
													<thead>
														<tr>
															<th style="width: 5%;">No</th>
															<th style="width: 40%;">コンテンツタイトル（集客記事）</th>
															<th style="width: 25%;">公開予定日時（間隔ごと）</th>
															<th style="width: 15%;">生成ステータス</th>
															<th style="width: 15%;">アクション</th>
														</tr>
													</thead>
													<tbody>
														<?php 
															$item_idx = 1;
															foreach ( $queue_items as $item ) : 
														?>
															<tr>
																<td><?php echo esc_html( $item_idx++ ); ?></td>
																<td>
																	<strong><?php echo esc_html( $item['title'] ); ?></strong>
																	<div class="alp-headings-preview">
																		<?php 
																			$headings = json_decode( $item['headings'], true );
																			if ( is_array( $headings ) ) {
																				echo '<span class="alp-heading-tag">構成:</span> ' . esc_html( implode( ' → ', $headings ) );
																			}
																		?>
																	</div>
																</td>
																<td><?php echo esc_html( $item['scheduled_at'] ); ?></td>
																<td>
																	<span class="alp-post-status status-<?php echo esc_attr( $item['post_status'] ); ?>">
																		<?php 
																			switch ( $item['post_status'] ) {
																				case 'pending': echo '待機中'; break;
																				case 'generating': echo '生成中'; break;
																				case 'completed': echo '完了'; break;
																				case 'failed': echo '失敗'; break;
																			}
																		?>
																	</span>
																	<?php if ( ! empty( $item['error_message'] ) ) : ?>
																		<div class="alp-error-tooltip" title="<?php echo esc_attr( $item['error_message'] ); ?>">
																			<span class="dashicons dashicons-warning" style="color:#ef4444; font-size:16px; cursor:help;"></span>
																		</div>
																	<?php endif; ?>
																</td>
																<td>
																	<?php if ( ! empty( $item['post_id'] ) ) : ?>
																		<a href="<?php echo esc_url( get_edit_post_link( $item['post_id'] ) ); ?>" class="button button-small" target="_blank">
																			WordPress編集
																		</a>
																	<?php else : ?>
																		-
																	<?php endif; ?>
																</td>
															</tr>
														<?php endforeach; ?>
													</tbody>
												</table>
											</div>
										<?php endif; ?>

									</div>
								</div>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
				</div>

				<!-- 2. 新規追加/編集タブ -->
				<div id="tab-new-project" class="alp-tab-content">
					<div class="alp-card-header">
						<h2 id="alp-form-title">新規プロジェクト作成</h2>
						<p>ターゲットとするビジネス用途、狙うキーワード、および参考情報から、集客・成約までの動線を自動で構築します。</p>
					</div>

					<form id="alp-project-form" class="alp-form">
						<input type="hidden" name="project_id" id="alp-project-id" value="">

						<div class="alp-form-row">
							<label for="alp-project-name">プロジェクト名 / 用途識別名 <span class="alp-required">*</span></label>
							<input type="text" name="name" id="alp-project-name" class="regular-text" placeholder="例：新築注文住宅の無料相談、リフォーム見積もり相談" required>
							<p class="description">管理用および、AIがプロジェクトのコンテキストを判断するための用途名です。</p>
						</div>

						<div class="alp-form-row">
							<label for="alp-source-url">解析対象URL（自社サイト等の現行URL）</label>
							<input type="url" name="source_url" id="alp-source-url" class="regular-text" placeholder="https://example.com/services">
							<p class="description">現在のサービス情報や事業内容が書かれたURLを入力すると、プラグインがそのHTMLテキストを読み込んで、AIがコンテンツの強みを8割補完・反映します。</p>
						</div>

						<div class="alp-form-row">
							<label for="alp-target-region">対象地域名（ローカルSEO）</label>
							<input type="text" name="target_region" id="alp-target-region" class="regular-text" placeholder="例：鹿児島市、渋谷区">
							<p class="description">ローカルSEO（特定地域に根ざした検索）を狙う場合に入力します。記事のタイトルや見出しに自然に盛り込まれます。</p>
						</div>

						<div class="alp-form-row">
							<label for="alp-keyword">メインキーワード・テーマ <span class="alp-required">*</span></label>
							<input type="text" name="keyword" id="alp-keyword" class="regular-text" placeholder="例：注文住宅 リフォーム、外壁塗装、ホームページ制作" required>
							<p class="description">トピッククラスター構成の中心となるキーワードをカンマ区切り、またはフレーズで指定してください。</p>
						</div>

						<div class="alp-form-row">
							<label for="alp-cv-url">出口(CV) URL <span class="alp-required">*</span></label>
							<input type="url" name="cv_url" id="alp-cv-url" class="regular-text" placeholder="https://lin.ee/xxxxxx または https://example.com/contact" required>
							<p class="description">LP上のボタンや、すべての集客記事から誘導する、最終的なコンバージョン先（LINE公式アカウント、お問い合わせフォーム、予約フォームなど）のURLを指定します。</p>
						</div>

						<div class="alp-form-row">
							<label for="alp-design-url">参考デザインLPのURL（任意）</label>
							<input type="url" name="design_url" id="alp-design-url" class="regular-text" placeholder="https://example.com/good-design-lp">
							<p class="description">トンマナや構成で参考にしたい他社LPなどがあれば指定します（AIへのコンテキストとして渡されます）。</p>
						</div>

						<div class="alp-form-row">
							<label for="alp-interval-days">投稿公開間隔 <span class="alp-required">*</span></label>
							<select name="interval_days" id="alp-interval-days">
								<option value="1">1日1本（毎日）</option>
								<option value="2">2日に1本</option>
								<option value="3">3日に1本</option>
								<option value="7">7日に1本（週刊）</option>
							</select>
							<p class="description">生成されたトピッククラスター記事群（10〜15本）をWordPressに「予約投稿（指定した間隔ごとの朝9:00）」として自動でスケジューリング登録します。</p>
						</div>

						<div class="alp-form-submit">
							<button type="submit" class="button button-primary" id="alp-btn-save-project">プロジェクト設定を保存</button>
							<button type="button" class="button" id="alp-btn-cancel-edit" style="display: none;">編集キャンセル</button>
						</div>
					</form>
				</div>

				<!-- 3. 基本設定タブ -->
				<div id="tab-settings" class="alp-tab-content">
					<div class="alp-card-header">
						<h2>AI連携基本設定</h2>
						<p>Google Gemini APIを利用するための共通キー設定です。</p>
					</div>

					<form id="alp-api-settings-form" class="alp-form">
						<div class="alp-form-row">
							<label for="alp-api-key">Gemini API キー <span class="alp-required">*</span></label>
							<input type="password" name="api_key" id="alp-api-key" class="regular-text" value="<?php echo esc_attr( $api_key ); ?>" required>
							<p class="description">
								Gemini 3.5 Flash-Lite APIキーを入力してください。APIキーは <a href="https://aistudio.google.com/" target="_blank">Google AI Studio</a> から無料で取得可能です。
							</p>
						</div>

						<div class="alp-form-submit">
							<button type="submit" class="button button-primary" id="alp-btn-save-settings">設定を保存</button>
							<button type="button" class="button" id="alp-btn-test-connection">接続テスト実行</button>
						</div>

						<div id="alp-connection-test-result" class="alp-test-result"></div>
					</form>
				</div>

			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: APIキーの保存
	 */
	public function ajax_save_api_key() {
		check_ajax_referer( 'alp_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( '権限がありません。' );
		}

		$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( $_POST['api_key'] ) : '';
		update_option( 'alp_gemini_api_key', $api_key );

		wp_send_json_success();
	}

	/**
	 * AJAX: API接続テスト
	 */
	public function ajax_test_connection() {
		check_ajax_referer( 'alp_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( '権限がありません。' );
		}

		$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( $_POST['api_key'] ) : '';
		
		$result = $this->gemini_client->test_connection( $api_key );

		if ( true === $result ) {
			wp_send_json_success();
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * AJAX: プロジェクト保存
	 */
	public function ajax_save_project() {
		check_ajax_referer( 'alp_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( '権限がありません。' );
		}

		$project_id = $this->generator->save_project( $_POST );

		if ( $project_id ) {
			wp_send_json_success( array( 'project_id' => $project_id ) );
		} else {
			wp_send_json_error( 'データベース保存エラーが発生しました。' );
		}
	}

	/**
	 * AJAX: プロジェクト削除
	 */
	public function ajax_delete_project() {
		check_ajax_referer( 'alp_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( '権限がありません。' );
		}

		$project_id = isset( $_POST['project_id'] ) ? intval( $_POST['project_id'] ) : 0;
		if ( $project_id > 0 ) {
			$this->generator->delete_project( $project_id );
			wp_send_json_success();
		} else {
			wp_send_json_error( '無効なプロジェクトIDです。' );
		}
	}

	/**
	 * AJAX: フェーズ1開始 (構成設計)
	 */
	public function ajax_start_generation() {
		check_ajax_referer( 'alp_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( '権限がありません。' );
		}

		$project_id = isset( $_POST['project_id'] ) ? intval( $_POST['project_id'] ) : 0;
		if ( 0 === $project_id ) {
			wp_send_json_error( '無効なプロジェクトIDです。' );
		}

		$result = $this->generator->start_initial_generation( $project_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		} else {
			wp_send_json_success();
		}
	}

	/**
	 * AJAX: フェーズ2の1ステップ（LPまたは記事1件の生成）の実行
	 */
	public function ajax_generate_step() {
		check_ajax_referer( 'alp_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( '権限がありません。' );
		}

		$project_id = isset( $_POST['project_id'] ) ? intval( $_POST['project_id'] ) : 0;
		if ( 0 === $project_id ) {
			wp_send_json_error( '無効なプロジェクトIDです。' );
		}

		// 1ステップ生成（自動的にLPまたは次の記事を順に生成）
		$result = $this->generator->generate_next_queued_article( $project_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		$progress = $this->generator->get_project_progress( $project_id );
		$is_done  = ( 'all_completed' === $result );

		wp_send_json_success(
			array(
				'progress' => $progress,
				'is_done'  => $is_done,
			)
		);
	}

	/**
	 * AJAX: LPの指示付き再生成（微修正）
	 */
	public function ajax_refine_lp() {
		check_ajax_referer( 'alp_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( '権限がありません。' );
		}

		$project_id = isset( $_POST['project_id'] ) ? intval( $_POST['project_id'] ) : 0;
		$feedback   = isset( $_POST['feedback'] ) ? trim( $_POST['feedback'] ) : '';

		if ( 0 === $project_id ) {
			wp_send_json_error( '無効なプロジェクトIDです。' );
		}

		if ( empty( $feedback ) ) {
			wp_send_json_error( '修正指示を入力してください。' );
		}

		$result = $this->generator->refine_lp( $project_id, $feedback );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		} else {
			wp_send_json_success();
		}
	}
}
