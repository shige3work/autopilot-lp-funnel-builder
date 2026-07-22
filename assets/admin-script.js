/**
 * Autopilot LP Funnel Builder - Admin Javascript
 */
jQuery(document).ready(function($) {

	// ==========================================
	// 1. タブ切り替え制御
	// ==========================================
	$('.alp-nav-tab').on('click', function(e) {
		e.preventDefault();
		var targetTab = $(this).attr('href');

		// ナビゲーションのアクティブ切り替え
		$('.alp-nav-tab').removeClass('active');
		$(this).addClass('active');

		// コンテンツのアクティブ切り替え
		$('.alp-tab-content').removeClass('active');
		$('#tab-' + targetTab.replace('#', '')).addClass('active');

		// ハッシュを更新（リロード時にタブ維持）
		window.location.hash = targetTab;
	});

	// ハッシュがあれば初期表示を調整
	if (window.location.hash) {
		var initialTab = window.location.hash;
		var tabBtn = $('.alp-nav-tab[href="' + initialTab + '"]');
		if (tabBtn.length) {
			tabBtn.trigger('click');
		}
	}

	// ==========================================
	// 2. API設定 & 接続テスト
	// ==========================================
	$('#alp-api-settings-form').on('submit', function(e) {
		e.preventDefault();
		var apiKey = $('#alp-api-key').val();
		var saveBtn = $('#alp-btn-save-settings');

		saveBtn.text(alpData.strings.saving).prop('disabled', true);

		$.post(alpData.ajax_url, {
			action: 'alp_save_api_key',
			nonce: alpData.nonce,
			api_key: apiKey
		}, function(response) {
			saveBtn.text(alpData.strings.saved).prop('disabled', false);
			setTimeout(function() {
				saveBtn.text('設定を保存');
			}, 2000);
		});
	});

	$('#alp-btn-test-connection').on('click', function(e) {
		e.preventDefault();
		var apiKey = $('#alp-api-key').val();
		var resultDiv = $('#alp-connection-test-result');
		var testBtn = $(this);

		testBtn.text(alpData.strings.testing).prop('disabled', true);
		resultDiv.removeClass('success error').hide().text('');

		$.post(alpData.ajax_url, {
			action: 'alp_test_connection',
			nonce: alpData.nonce,
			api_key: apiKey
		}, function(response) {
			testBtn.text('接続テスト実行').prop('disabled', false);
			if (response.success) {
				resultDiv.addClass('success').text(alpData.strings.testing_success);
			} else {
				resultDiv.addClass('error').text('接続失敗: ' + response.data);
			}
		});
	});

	// ==========================================
	// 3. プロジェクトの保存・編集・削除
	// ==========================================
	
	// プロジェクト保存
	$('#alp-project-form').on('submit', function(e) {
		e.preventDefault();
		var form = $(this);
		var submitBtn = $('#alp-btn-save-project');
		var formData = form.serialize() + '&action=alp_save_project&nonce=' + alpData.nonce;

		submitBtn.text(alpData.strings.saving).prop('disabled', true);

		$.post(alpData.ajax_url, formData, function(response) {
			submitBtn.text('プロジェクト設定を保存').prop('disabled', false);
			if (response.success) {
				// フォーム初期化とリロード
				form[0].reset();
				$('#alp-project-id').val('');
				$('#alp-btn-cancel-edit').hide();
				$('#alp-form-title').text('新規プロジェクト作成');
				
				// プロジェクト一覧タブへ切り替えてリロード
				window.location.hash = '#projects';
				window.location.reload();
			} else {
				alert('エラーが発生しました: ' + response.data);
			}
		});
	});

	// プロジェクト編集ボタンクリック
	$('.alp-btn-edit').on('click', function() {
		var project = $(this).data('project');
		
		// フォームへ値を詰め込む
		$('#alp-project-id').val(project.id);
		$('#alp-project-name').val(project.name);
		$('#alp-source-url').val(project.source_url);
		$('#alp-target-region').val(project.target_region);
		$('#alp-keyword').val(project.keyword);
		$('#alp-cv-url').val(project.cv_url);
		$('#alp-design-url').val(project.design_url);
		$('#alp-interval-days').val(project.interval_days);

		// UI調整
		$('#alp-form-title').text('プロジェクト設定編集 (ID: ' + project.id + ')');
		$('#alp-btn-cancel-edit').show();
		
		// タブ切り替え
		$('#alp-new-project-tab-btn').trigger('click');
	});

	// 編集キャンセル
	$('#alp-btn-cancel-edit').on('click', function() {
		$('#alp-project-form')[0].reset();
		$('#alp-project-id').val('');
		$(this).hide();
		$('#alp-form-title').text('新規プロジェクト作成');
		$('.alp-nav-tab[href="#projects"]').trigger('click');
	});

	// プロジェクト削除
	$('.alp-btn-delete').on('click', function() {
		var projectId = $(this).data('id');
		var card = $('#project-card-' + projectId);

		if (!confirm('このプロジェクトを削除しますか？紐付く自動生成ページや記事の公開スケジュールも削除されます。')) {
			return;
		}

		$.post(alpData.ajax_url, {
			action: 'alp_delete_project',
			nonce: alpData.nonce,
			project_id: projectId
		}, function(response) {
			if (response.success) {
				card.fadeOut(400, function() {
					$(this).remove();
					if ($('.alp-project-card').length === 0) {
						window.location.reload(); // エンプティステートを表示するためにリロード
					}
				});
			} else {
				alert('削除エラー: ' + response.data);
			}
		});
	});

	// トピッククラスター詳細アコーディオンの開閉
	$('.alp-toggle-details-link').on('click', function(e) {
		e.preventDefault();
		var projectId = $(this).data('id');
		$('#cluster-table-' + projectId).slideToggle(200);
	});

	// AI指示付きLP修正フォームの開閉
	$('.alp-btn-refine-toggle').on('click', function() {
		var projectId = $(this).data('id');
		$('#refine-form-' + projectId).slideToggle(200);
	});

	$('.alp-btn-refine-cancel').on('click', function() {
		var projectId = $(this).data('id');
		$('#refine-form-' + projectId).slideUp(200);
	});

	// ==========================================
	// 4. 自動生成バッチ非同期処理 (ループAjax)
	// ==========================================
	$('.alp-btn-generate').on('click', function() {
		var btn = $(this);
		var projectId = btn.data('id');
		var card = $('#project-card-' + projectId);
		var badge = card.find('.alp-project-status-badge');
		var percentText = $('#progress-percent-' + projectId);
		var fillBar = $('#progress-fill-' + projectId);

		if (!confirm('トピッククラスターと成約LPの自動生成を開始します。Gemini APIへのリクエストを複数回非同期で実行します。よろしいですか？')) {
			return;
		}

		// UIを生成中状態に移行
		btn.prop('disabled', true).html('<span class="spinner is-active"></span> 構成案の策定中...');
		badge.removeClass().addClass('alp-project-status-badge status-generating').text('生成中');
		
		// 進捗バー初期表示
		percentText.text('5%');
		fillBar.css('width', '5%');

		// ステップ1: 構成案（フェーズ1）をキック
		$.post(alpData.ajax_url, {
			action: 'alp_start_generation',
			nonce: alpData.nonce,
			project_id: projectId
		}, function(response) {
			if (!response.success) {
				alert('構成案の策定中にエラーが発生しました: ' + response.data);
				resetGenerateButton(btn, badge, percentText, fillBar);
				return;
			}

			// 構成設計完了、進捗を更新して順次生成ループ（フェーズ2）へ
			percentText.text('15%');
			fillBar.css('width', '15%');
			btn.html('<span class="spinner is-active"></span> コンテンツ生成中...');
			
			// 順次生成を開始
			runGenerationStep(projectId, btn, badge, percentText, fillBar);
		}).fail(function() {
			alert('サーバーとの通信エラーが発生しました。');
			resetGenerateButton(btn, badge, percentText, fillBar);
		});
	});

	/**
	 * 非同期で1件ずつコンテンツを生成させる再帰関数
	 */
	function runGenerationStep(projectId, btn, badge, percentText, fillBar) {
		$.post(alpData.ajax_url, {
			action: 'alp_generate_step',
			nonce: alpData.nonce,
			project_id: projectId
		}, function(response) {
			if (!response.success) {
				alert('コンテンツ生成中にエラーが発生しました: ' + response.data);
				resetGenerateButton(btn, badge, percentText, fillBar);
				// エラー箇所を視認させるためにテーブル展開
				$('#cluster-table-' + projectId).slideDown(200);
				return;
			}

			var data = response.data;
			
			// 進捗を反映
			percentText.text(data.progress + '%');
			fillBar.css('width', data.progress + '%');

			if (data.is_done) {
				// すべて生成が完了
				btn.remove(); // 開始ボタンは削除して終了とする
				badge.removeClass().addClass('alp-project-status-badge status-completed').text('生成完了');
				alert(alpData.strings.generation_finish);
				window.location.reload(); // スケジュールテーブルやボタンの変化を確実に反映させるため
			} else {
				// 次のコンテンツ生成を実行
				runGenerationStep(projectId, btn, badge, percentText, fillBar);
			}
		}).fail(function() {
			alert('通信エラーのため生成が中断されました。再試行するには「自動生成を開始」を再度クリックしてください。');
			resetGenerateButton(btn, badge, percentText, fillBar);
		});
	}

	/**
	 * エラー等で生成が中断した際、ボタンやステータス表示を差し戻す
	 */
	function resetGenerateButton(btn, badge, percentText, fillBar) {
		btn.prop('disabled', false).html('<span class="dashicons dashicons-controls-play"></span> 自動生成を開始');
		badge.removeClass().addClass('alp-project-status-badge status-failed').text('生成失敗');
	}

	// ==========================================
	// 5. LPの指示付き再生成（微修正）
	// ==========================================
	$('.alp-btn-submit-refine').on('click', function() {
		var btn = $(this);
		var projectId = btn.data('id');
		var wrapper = $('#refine-form-' + projectId);
		var feedback = $('#refine-feedback-' + projectId).val();

		if (feedback.trim() === '') {
			alert('修正の指示を入力してください。');
			return;
		}

		btn.prop('disabled', true).text('LP再構成中...');
		wrapper.find('.alp-btn-refine-cancel').prop('disabled', true);

		$.post(alpData.ajax_url, {
			action: 'alp_refine_lp',
			nonce: alpData.nonce,
			project_id: projectId,
			feedback: feedback
		}, function(response) {
			btn.prop('disabled', false).text('再生成を実行する');
			wrapper.find('.alp-btn-refine-cancel').prop('disabled', false);

			if (response.success) {
				alert(alpData.strings.refining_success);
				wrapper.slideUp(200);
			} else {
				alert('LPの微修正中にエラーが発生しました: ' + response.data);
			}
		}).fail(function() {
			alert('サーバーとの通信エラーが発生しました。');
			btn.prop('disabled', false).text('再生成を実行する');
			wrapper.find('.alp-btn-refine-cancel').prop('disabled', false);
		});
	});

});
