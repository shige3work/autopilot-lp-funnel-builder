<?php
/**
 * GitHub Updater Class
 *
 * GitHubのmainブランチから直接バージョン情報を検出し、WordPress管理画面上でプラグインを自動更新できるようにします。
 *
 * @package Autopilot_LP_Funnel_Builder
 */

// 直接アクセスを禁止
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Autopilot_LP_Funnel_Builder_Github_Updater {

	/**
	 * リポジトリのURL
	 */
	private $repo = 'shige3work/autopilot-lp-funnel-builder';

	/**
	 * プラグインのスラッグ
	 */
	private $slug = 'autopilot-lp-funnel-builder/autopilot-lp-funnel-builder.php';

	/**
	 * コンストラクタ
	 */
	public function __construct() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
		add_filter( 'plugins_api', array( $this, 'get_plugin_info' ), 20, 3 );
		add_filter( 'upgrader_post_install', array( $this, 'post_install' ), 10, 3 );
	}

	/**
	 * GitHubのmainブランチ上のrawファイルからリモートバージョンを取得する
	 *
	 * @return string|false バージョン番号。取得失敗時はfalse。
	 */
	private function get_remote_version() {
		$transient_key = 'alp_github_remote_version';
		
		// 強制チェック時はキャッシュを無視
		$force_check = isset( $_GET['force-check'] ) && '1' === $_GET['force-check'];
		$version     = $force_check ? false : get_transient( $transient_key );

		if ( false !== $version ) {
			return $version;
		}

		$url      = "https://raw.githubusercontent.com/{$this->repo}/main/autopilot-lp-funnel-builder.php";
		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
				),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$content = wp_remote_retrieve_body( $response );
		if ( empty( $content ) ) {
			return false;
		}

		// ファイルヘッダーから Version: x.x.x を抽出
		if ( preg_match( '/Version:\s*([0-9.-]+)/i', $content, $matches ) ) {
			$remote_version = trim( $matches[1] );
			// 1時間キャッシュする
			set_transient( $transient_key, $remote_version, HOUR_IN_SECONDS );
			return $remote_version;
		}

		return false;
	}

	/**
	 * 更新チェック処理
	 *
	 * @param object $transient プラグイン更新用のトランスィエント。
	 * @return object
	 */
	public function check_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$remote_version = $this->get_remote_version();
		if ( ! $remote_version ) {
			return $transient;
		}

		$current_version = AUTOPILOT_LP_FUNNEL_BUILDER_VERSION;

		if ( version_compare( $remote_version, $current_version, '>' ) ) {
			$obj              = new stdClass();
			$obj->slug        = 'autopilot-lp-funnel-builder';
			$obj->plugin      = $this->slug;
			$obj->new_version = $remote_version;
			$obj->url         = "https://github.com/{$this->repo}";
			// mainブランチの最新ZIPアーカイブURLを指定
			$obj->package     = "https://github.com/{$this->repo}/archive/refs/heads/main.zip";

			$transient->response[ $this->slug ] = $obj;
		}

		return $transient;
	}

	/**
	 * プラグイン情報の詳細表示（プラグイン画面での「詳細を表示」リンク用）
	 *
	 * @param object|false $res    結果。
	 * @param string       $action アクション名（'plugin_information'など）。
	 * @param object       $args   引数。
	 * @return object|false
	 */
	public function get_plugin_info( $res, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $res;
		}

		if ( isset( $args->slug ) && $args->slug === 'autopilot-lp-funnel-builder' ) {
			$remote_version = $this->get_remote_version();
			if ( ! $remote_version ) {
				return $res;
			}

			$res              = new stdClass();
			$res->name        = 'Autopilot LP Funnel Builder';
			$res->slug        = 'autopilot-lp-funnel-builder';
			$res->version     = $remote_version;
			$res->author      = 'shige3work';
			$res->homepage    = "https://github.com/{$this->repo}";
			$res->download_link = "https://github.com/{$this->repo}/archive/refs/heads/main.zip";
			$res->sections    = array(
				'description' => 'Gemini APIを活用して、WordPressサイト上で「用途別の成約用LP（固定ページ）」および「トピッククラスター構成の集客記事群（投稿）」を自動生成・予約公開するWordPressプラグインです。',
				'changelog'   => 'GitHubへのコミットとバージョンアップにより直接更新が可能です。最新版にアップデートしてください。',
			);
		}

		return $res;
	}

	/**
	 * インストール後のフォルダ名クリーンアップ処理
	 *
	 * GitHubからZIPダウンロードすると、展開後のフォルダ名が 'autopilot-lp-funnel-builder-main' のようになってしまうため、
	 * 元のフォルダ名 'autopilot-lp-funnel-builder' にリネームします。
	 *
	 * @param bool  $response インストール応答。
	 * @param array $hook_extra 追加データ。
	 * @param array $result 展開結果情報。
	 * @return array
	 */
	public function post_install( $response, $hook_extra, $result ) {
		global $wp_filesystem;

		$plugin_folder = 'autopilot-lp-funnel-builder';
		$install_directory = plugin_dir_path( __FILE__ ) . '../../'; // pluginsディレクトリへのパス

		// 展開先ディレクトリ名
		$destination = $result['destination'];

		// 展開先が元のプラグインディレクトリ名と異なる場合、リネームする
		if ( basename( $destination ) !== $plugin_folder ) {
			$correct_destination = trailingslashit( dirname( $destination ) ) . $plugin_folder;

			// 既に正しいフォルダが存在すれば削除
			if ( $wp_filesystem->exists( $correct_destination ) ) {
				$wp_filesystem->delete( $correct_destination, true );
			}

			// リネームを実行
			$wp_filesystem->move( $destination, $correct_destination );
			$result['destination'] = $correct_destination;
		}

		return $result;
	}
}
