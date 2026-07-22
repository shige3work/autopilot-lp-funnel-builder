<?php
/**
 * Gemini API Client Class
 *
 * Gemini 3.5 Flash APIとの連携およびURLスクレイピングを管理します。
 *
 * @package Autopilot_LP_Funnel_Builder
 */

// 直接アクセスを禁止
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Autopilot_LP_Funnel_Builder_Gemini_Client {

	/**
	 * APIエンドポイントURL
	 */
	private $api_url = 'https://generativelanguage.googleapis.com/v1/models/gemini-3.5-flash:generateContent';

	/**
	 * APIキーの取得
	 */
	private function get_api_key() {
		return get_option( 'alp_gemini_api_key', '' );
	}

	/**
	 * API接続テスト
	 *
	 * @param string $api_key テスト対象のAPIキー。
	 * @return bool|string 成功時はtrue、失敗時はエラーメッセージ。
	 */
	public function test_connection( $api_key ) {
		if ( empty( $api_key ) ) {
			return 'APIキーが入力されていません。';
		}

		$url      = $this->api_url . '?key=' . $api_key;
		$payload  = array(
			'contents' => array(
				array(
					'parts' => array(
						array( 'text' => 'Hello. Respond with "OK" if you can read this.' ),
					),
				),
			),
		);
		$response = wp_remote_post(
			$url,
			array(
				'headers'   => array( 'Content-Type' => 'application/json' ),
				'body'      => wp_json_encode( $payload ),
				'timeout'   => 15,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			$err_data = json_decode( $body, true );
			return isset( $err_data['error']['message'] ) ? $err_data['error']['message'] : '接続エラーが発生しました（ステータスコード: ' . $code . '）';
		}

		return true;
	}

	/**
	 * 指定されたURLからテキストコンテンツを抽出する（簡易スクレイピング）
	 *
	 * @param string $url 対象URL。
	 * @return string プレーンテキスト。
	 */
	public function scrape_url( $url ) {
		if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return '';
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 10,
				'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36 WordPress-AutopilotLP',
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$html = wp_remote_retrieve_body( $response );
		if ( empty( $html ) ) {
			return '';
		}

		// 不要なタグ（script, style, head, iframe, noscript）を取り除く
		$html = preg_replace( '/<script\b[^>]*>(.*?)<\/script>/is', '', $html );
		$html = preg_replace( '/<style\b[^>]*>(.*?)<\/style>/is', '', $html );
		$html = preg_replace( '/<head\b[^>]*>(.*?)<\/head>/is', '', $html );
		$html = preg_replace( '/<iframe\b[^>]*>(.*?)<\/iframe>/is', '', $html );

		// タグを除去してプレーンテキスト化
		$text = wp_strip_all_tags( $html );

		// 余分な改行やスペースを整理
		$text = preg_replace( '/[ \t]+/', ' ', $text );
		$text = preg_replace( '/\s*[\r\n]+\s*/', "\n", $text );
		$text = trim( $text );

		// トークン制限を考慮して最大3000文字程度に制限
		return mb_substr( $text, 0, 3000 );
	}

	/**
	 * Gemini APIを呼び出す汎用メソッド
	 *
	 * @param array $payload APIペイロード。
	 * @return array|WP_Error
	 */
	private function call_gemini( $payload ) {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error( 'missing_api_key', 'Gemini APIキーが設定されていません。管理画面の基本設定から入力してください。' );
		}

		$url      = $this->api_url . '?key=' . $api_key;
		$response = wp_remote_post(
			$url,
			array(
				'headers'   => array( 'Content-Type' => 'application/json' ),
				'body'      => wp_json_encode( $payload ),
				'timeout'   => 120, // 処理が重いため長めに設定
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			$err_data = json_decode( $body, true );
			$msg      = isset( $err_data['error']['message'] ) ? $err_data['error']['message'] : 'Gemini APIの呼び出しに失敗しました。';
			return new WP_Error( 'api_error', $msg . ' (Status Code: ' . $code . ')' );
		}

		$data = json_decode( $body, true );
		if ( isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
			return $data['candidates'][0]['content']['parts'][0]['text'];
		}

		return new WP_Error( 'invalid_response', 'Gemini APIから無効な応答が返されました。' );
	}

	/**
	 * プロジェクト設定からトピッククラスター構成案（JSON）を生成する
	 *
	 * @param array  $project           プロジェクトのデータベースレコード。
	 * @param string $scraped_content   解析対象URLからスクレイピングしたコンテンツ。
	 * @return array|WP_Error 構成案の配列、またはWP_Error。
	 */
	public function generate_cluster_plan( $project, $scraped_content ) {
		$prompt = "あなたは優秀なSEOコンサルタントおよびコンテンツマーケターです。
以下の情報を元に、トピッククラスターモデルを用いた集客記事群（投稿用）と成約用LP（固定ページ用）の「構成設計案」を策定してください。

## 入力情報
* プロジェクト用途: {$project['name']}
* メインキーワード・テーマ: {$project['keyword']}
* 対象地域（ローカルSEO）: " . ( ! empty( $project['target_region'] ) ? $project['target_region'] : '全国（特定地域なし）' ) . "
* CV先（お問い合わせ先）: {$project['cv_url']}
" . ( ! empty( $project['source_url'] ) ? "* 参考WebサイトURL: {$project['source_url']}\n" : '' ) . "
" . ( ! empty( $scraped_content ) ? "* 参考Webサイトから抽出した主要コンテンツ内容:\n[START_CONTENT]\n{$scraped_content}\n[END_CONTENT]\n" : '' ) . "

## 成果物への要求
1. **成約用LP（固定ページ用）**: タイトル案と構成の概要。
2. **集客記事（投稿用）**: 合計10〜15本の記事構成案。
   * ピラーページ（まとめ記事・中心テーマ）を1本、クラスターページ（周辺テーマ）を9〜14本設計してください。
   * 各記事には、SEOを意識した「タイトル」「検索意図（メタディスクリプション用）」「大まかなH2見出し（3〜5個）の配列」を設定してください。
   * 各記事はローカルSEOキーワード（対象地域）を必要に応じて自然にタイトルや見出しに含めてください。

## 出力形式
必ず以下のJSONスキーマに従ったJSONのみを出力してください。追加の説明やコードブロック用のマークアップ（```json 等）は一切含めず、純粋なJSON文字列として出力してください。

```json
{
  \"lp_title\": \"成約用LPのタイトル案（例：鹿児島市でリフォームなら○○）\",
  \"lp_outline\": \"LPの構成概要・訴求方針の短い説明\",
  \"articles\": [
    {
      \"title\": \"記事のタイトル（ピラーページまたは個別クラスター記事）\",
      \"description\": \"検索エンジン用のメタディスクリプション（120文字程度）\",
      \"headings\": [
        \"H2見出し案1\",
        \"H2見出し案2\",
        \"H2見出し案3\"
      ]
    }
  ]
}
```";

		$payload = array(
			'contents' => array(
				array(
					'parts' => array(
						array( 'text' => $prompt ),
					),
				),
			),
			'generationConfig' => array(
				'responseMimeType' => 'application/json',
			),
		);

		$result = $this->call_gemini( $payload );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$decoded = json_decode( trim( $result ), true );
		if ( json_last_error() !== JSON_ERROR_NONE || ! isset( $decoded['articles'] ) ) {
			return new WP_Error( 'json_parse_error', 'Gemini APIの応答をJSONとして解析できませんでした。レスポンス: ' . mb_substr( $result, 0, 500 ) );
		}

		return $decoded;
	}

	/**
	 * 個別記事の本文を生成する
	 *
	 * @param array  $project       プロジェクト情報。
	 * @param string $article_title 記事のタイトル。
	 * @param array  $headings      見出し構成案（H2の配列）。
	 * @param string $lp_url        成約用LP（固定ページ）のURL。
	 * @return string|WP_Error 生成された本文HTML（H2/H3/pなど）、またはWP_Error。
	 */
	public function generate_article_content( $project, $article_title, $headings, $lp_url ) {
		$headings_str = implode( "\n* ", $headings );
		$prompt = "あなたはWordPressの記事作成に長けたプロのWebライター兼SEOスペシャリストです。
以下の指示に従い、高品質で読者のためになるブログ記事の本文（HTML形式）を作成してください。

## 入力情報
* 記事タイトル: {$article_title}
* 指定見出し案（H2）:
* {$headings_str}
* 用途テーマ: {$project['name']}
* メインキーワード: {$project['keyword']}
* 対象地域: " . ( ! empty( $project['target_region'] ) ? $project['target_region'] : '指定なし' ) . "
* リンクする成約用LPのURL: {$lp_url}

## 本文執筆のルール
1. **見出し構成の展開**:
   * 指定された見出し案（H2）を網羅して執筆してください。
   * 各H2セクション内には、より詳細に解説するためのH3見出し（`<h3>`）も適宜追加して構造化してください。
2. **SEO対策と読みやすさ**:
   * 検索意図に応える有益で信頼性のある具体的な情報を書いてください。
   * パラグラフごとに適切な長さで、箇条書き（`<ul>` / `<li>`）や太字（`<strong>`）などを使用して読みやすく成形してください。
   * 不要な前置きや、AI特有の挨拶（「こんにちは！」「今回は〜について解説します」など）は省き、冒頭から記事の導入に入ってください。
3. **LPへの導線設計（非常に重要）**:
   * 記事の最後（最後のH2セクションの下など）に、このテーマに深く関連した成約用LPへの誘導文と、リンク付きのCTA（コールトゥアクション）を自然な文脈で挿入してください。
   * リンク先URLは必ず `{$lp_url}` を使用してください。
   * HTMLでのマークアップ例:
     ```html
     <div style=\"background-color: #f3f4f6; padding: 20px; border-radius: 8px; margin-top: 30px; border-left: 4px solid #3b82f6;\">
       <h4 style=\"margin-top: 0; font-size: 1.2em; color: #1f2937;\">鹿児島市でリフォームのご相談なら、弊社の「新築相談窓口」にお任せください！</h4>
       <p style=\"color: #4b5563; font-size: 0.95em;\">ご予算に合わせた最適なプランを専門家が無料でアドバイスいたします。</p>
       <a href=\"{$lp_url}\" style=\"display: inline-block; background-color: #3b82f6; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; font-weight: bold; margin-top: 10px;\">詳しくはこちら（個別相談へ）</a>
     </div>
     ```

## 出力形式
* 本文全体を `<h2>` や `<h3>`、`<p>`、`<ul>` などのHTMLタグでラップして出力してください。
* markdownのコードブロックマークアップ（```html 等）は含めず、プレーンなHTML文字列として出力してください。";

		$payload = array(
			'contents' => array(
				array(
					'parts' => array(
						array( 'text' => $prompt ),
					),
				),
			),
		);

		return $this->call_gemini( $payload );
	}

	/**
	 * 成約用LP（固定ページ用HTML）を生成または修正指示に従って再生成する
	 *
	 * @param array  $project         プロジェクトデータ。
	 * @param string $scraped_content 参考URLのスクレイピングデータ。
	 * @param string $feedback        AIへの追加修正指示（任意）。
	 * @param string $current_html    現在のLPのHTMLコード（修正時のコンテキスト用、任意）。
	 * @return string|WP_Error 生成されたLPのHTMLコード、またはWP_Error。
	 */
	public function regenerate_lp_content( $project, $scraped_content, $feedback = '', $current_html = '' ) {
		$is_revision = ! empty( $current_html ) && ! empty( $feedback );

		if ( $is_revision ) {
			// 修正用のプロンプト
			$prompt = "あなたは優秀なコンバージョン率（CVR）改善スペシャリスト、セールスコピーライター、およびモダンWebデザイナーです。
現在作成されている成約用LPのHTMLコードに対して、ユーザーからの修正指示を反映した新しいHTMLコードを生成してください。

## 修正のコンテキスト
* プロジェクト名/用途: {$project['name']}
* CV先（お問い合わせ先URL）: {$project['cv_url']}
* 対象地域（ローカルSEO）: " . ( ! empty( $project['target_region'] ) ? $project['target_region'] : '全国' ) . "
* メインキーワード: {$project['keyword']}

## 現在のLP HTMLコード:
[START_HTML]
{$current_html}
[END_HTML]

## ユーザーからの修正指示:
[START_FEEDBACK]
{$feedback}
[END_FEEDBACK]

## 制作上のルールと制約:
1. **スタイリング**:
   * スタイリングには **Tailwind CSS (CDN)** を使用します。
   * HTMLの先頭には必ず `<script src=\"https://cdn.tailwindcss.com\"></script>` を含め、Tailwind CSSを用いたモダンなレスポンシブデザイン（モバイル・PC対応）を構築してください。
   * 背景のグラデーション、美しいボタンホバー効果、余白（padding/margin）のゆとり、シャドウ、モダンなフォント設定などを駆使し、非常にプレミアムな印象のLPに仕上げてください。
2. **コンテンツ構成**:
   * ユーザーの修正指示を最優先で反映してください。指示によって構造が大きく変わる場合もありますが、元々機能していたCV先リンク（URL: `{$project['cv_url']}`）や重要な訴求内容は維持してください。
   * ヒーローセクション（魅力的なキャッチコピー）、お客様が抱える課題・お悩みセクション、それらを解決する自社の強み・ソリューションセクション、安心していただけるお客様の声またはよくある質問、そして分かりやすいコンバージョンボタン（CTA）を適切に配置してください。
3. **リンク先**:
   * すべてのCVボタン（お問い合わせ、相談するなどのボタン）のリンク先 `href` は必ず `{$project['cv_url']}` に設定してください。

## 出力形式
* WordPressの固定ページのカスタムHTMLブロック等に貼り付けるだけで機能するような、自己完結型のHTMLコードのみを出力してください。
* markdownのコードブロックマークアップ（```html 等）は一切含めず、純粋なHTML文字列として出力してください。";
		} else {
			// 新規生成用のプロンプト
			$prompt = "あなたは優秀なコンバージョン率（CVR）改善スペシャリスト、セールスコピーライター、およびモダンWebデザイナーです。
以下の情報を元に、WordPressの固定ページにそのまま埋め込んで使用できる、超高品質でモダンな成約用LP（ランディングページ）のHTMLコードを作成してください。

## プロジェクト情報
* 用途/ターゲット: {$project['name']}
* CV先（お問い合わせ先URL）: {$project['cv_url']}
* 対象地域（ローカルSEO用）: " . ( ! empty( $project['target_region'] ) ? $project['target_region'] : '全国' ) . "
* メインキーワード・テーマ: {$project['keyword']}
" . ( ! empty( $project['source_url'] ) ? "* 自社/参考サイトURL: {$project['source_url']}\n" : '' ) . "
" . ( ! empty( $scraped_content ) ? "* 参考Webサイトから抽出した主要コンテンツ内容:\n[START_CONTENT]\n{$scraped_content}\n[END_CONTENT]\n" : '' ) . "

## 制作上のルールと制約:
1. **スタイリング**:
   * スタイリングには **Tailwind CSS (CDN)** を使用します。
   * HTMLの先頭に必ず `<script src=\"https://cdn.tailwindcss.com\"></script>` を記述し、Tailwind CSSを用いたモダンなレスポンシブデザイン（モバイル・PC両対応）を記述してください。
   * 背景のグラデーション、美しいボタンホバー効果、シャドウ、余白のゆとり、適切なフォント設定（Google Fonts等のInterやOutfit調）などを駆使し、 premium かつクリーンな印象に仕上げてください。
2. **コンテンツ構成**:
   * 以下のセクションを必ず含めてください：
     - **ヒーローセクション**: 対象地域名を含めた魅力的な大見出し（キャッチコピー）、サブコピー、直感的なCVボタン。
     - **課題・お悩みセクション**: ターゲット顧客が抱える痛み・不安（「こんなお悩みありませんか？」）。
     - **解決策・ベネフィットセクション**: 自社サービスがそれらを解決できる理由、特徴、選ばれる理由（3つの強みなど）。
     - **実績・お客様の声（あるいはよくある質問）**: 信頼性を補強する情報。
     - **CTAセクション**: お問い合わせや申し込みを促す最終的なCVボタン。
3. **リンク先**:
   * すべてのCVボタンのリンク先 `href` は必ず `{$project['cv_url']}` に設定してください。

## 出力形式
* WordPressの固定ページでそのまま使用できる、自己完結型のHTMLコードのみを出力してください。
* markdownのコードブロックマークアップ（```html 等）は一切含めず、純粋なHTML文字列として出力してください。";
		}

		$payload = array(
			'contents' => array(
				array(
					'parts' => array(
						array( 'text' => $prompt ),
					),
				),
			),
		);

		return $this->call_gemini( $payload );
	}
}
