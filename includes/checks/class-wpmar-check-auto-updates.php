<?php
/**
 * コア・プラグイン・テーマの「トグル状態」と「実効状態」の事実レイヤーを収集する.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * {@see WPMAR_Data_Collector} 向けに `auto_updates` エンベロープを収集する.
 *
 * このクラスは、WPMAR の他の部分では答えられない問い「このアイテムの自動更新は実際に走るのか、
 * それとも管理画面のトグルが嘘をついているのか」に答えるために存在する（実際に
 * `forced-auto-update-controller` が優先度9999で `auto_update_plugin` フィルターを握り、
 * トグル OFF の全プラグインに対してフィルターが true を返す、という実例を踏まえて設計した）。
 * 以下の設計判断はすべて、プランの番号付き「事実」（記憶ではなく WP コアのソースで検証済み）に
 * 遡れる — 全体の導出は `~/.claude/plans/wp-checksum-verifier-dazzling-meteor.md` を参照。
 * 特に見落としやすい点を挙げる:
 *
 * - **`effective` は限定的な概念である.** `WP_Automatic_Updater::should_update()` はトグル/
 *   フィルター判定に入る前に `request_filesystem_credentials()` によるゲートも通す。
 *   これは意図的に一切呼ばない（WP_Filesystem の初期化・`request_filesystem_credentials`
 *   フィルター・隠れた出力という副作用があるため）。そのため `effective` はここでは
 *   「設定・フィルター・PHP（コアは MySQL も）要件上の実効値」を意味し、
 *   「確実に自動更新が走る」ことまでは保証しない。`site.filesystem_credentials_ok` は
 *   （一切評価しない）`null` のままにし、この限界を恒久的に記録している.
 * - **`WP_Automatic_Updater::is_disabled()` や `wp_is_auto_update_enabled_for_type()` を
 *   そのまま呼ぶことはしない.** どちらも内部で `wp_is_file_mod_allowed()` と
 *   `automatic_updater_disabled` フィルターを再実行するため、両方呼ぶとこれらのフィルターが
 *   1 リクエストで 2 回走ってしまう（WP のフィルターは純粋であるとは限らない —
 *   `resolve_site_gate()` を参照）。代わりに `is_disabled()` の 4 つの入力を
 *   `resolve_site_gate()` で 1 回だけ評価し、`plugins_type_enabled` / `themes_type_enabled` は
 *   その同じ結果から導出し、`{$type}s_auto_update_enabled` フィルターだけを追加で実行する
 *   （`resolve_site_slice()` を参照）.
 * - **VCS チェックアウトは対象タイプ（core/plugin/theme）ごとに追跡する**、単一フラグにはしない.
 *   `WP_Automatic_Updater::is_vcs_checkout( $context )` は常に ABSPATH も同時にチェックするが、
 *   `$context` は対象ごとに異なる（ABSPATH / WP_PLUGIN_DIR / get_theme_root()）ため、例えば
 *   wp-content/plugins 配下の `.git` はコアに影響を与えずプラグインの自動更新だけをブロックする.
 * - **コアの判定は `Core_Upgrader::should_update_to_version( $offered_ver )` に委譲する**
 *   独立した dev/minor/major の 3 軸から再導出することはしない. `class-core-upgrader.php`
 *   （`should_update_to_version()`）を読むと、3 つのフィルターは独立して常に評価される
 *   わけではないと分かった — どれが評価されるかは、現在インストール中のバージョンが
 *   開発版かどうか、およびオファーされたバージョンが同一ブランチか新しいブランチかに依存する.
 *   この分岐を自前で再現すると、コアが本来評価しないタイミングで
 *   `allow_dev_auto_core_updates` を呼んでしまう恐れがあり、これはまさにこのクラスが
 *   避けようとしているサードパーティフィルターの副作用リスクそのものである. そのため `axes` は、
 *   保留中のオファーがなく直接コアに尋ねられないときにのみ使う、*静的*（フィルターなしの）
 *   オプション + 定数の読み取り結果のまま保持する.
 * - **フィルター実行の隔離は 2 種類だけであり、それ以外はない.** `try/catch (Throwable)` は
 *   例外を、`ob_start()`/`ob_get_clean()`（`finally` 内。
 *   `Automatic_Upgrader_Skin::request_filesystem_credentials()` と同じ手法）は意図しない出力
 *   （`echo`、warning/notice — `WP_DEBUG_DISPLAY` 環境では WP-Cron の HTTP レスポンスや
 *   WP-CLI の stdout に漏れてしまう）を、それぞれ捕捉する. サードパーティのフィルターが
 *   オプションを書き込む・HTTP 通信をする・`exit`/`die` を呼ぶことまでは防げない —
 *   これは WPMAR 固有の話ではなく、WordPress のフィルターを実行すること自体に内在する
 *   信頼境界の問題である.
 * - **新しい transient/site_transient・キャッシュ層は追加しない.** 1 回の実行で増えるコストは
 *   フィルター呼び出し数件と、既にキャッシュ済みの `get_site_option()` の読み取りのみ.
 *   自動更新のトグルは即座に反映されるべきものなので、これをキャッシュすると
 *   「設定を変えたのにレポートが古い」という実害のほうが、わずかなコスト削減より重い.
 *
 * ## 静的（リクエストスコープの）キャッシュ
 *
 * ファイルシステム/定数由来の事実のみを {@see self::$fs_gate_cache} にキャッシュする:
 * `wp_is_file_mod_allowed()`、`wp_installing()`、`AUTOMATIC_UPDATER_DISABLED` 定数、
 * および 3 回の `is_vcs_checkout()` 呼び出し. これらは `switch_to_blog()` をまたいでも
 * 変わらない（ファイルシステムと定数はネットワーク全体で共有されるため）. フィルター由来の値 —
 * `automatic_updater_disabled`、`{$type}s_auto_update_enabled`、各アイテムの
 * `auto_update_{plugin,theme}` の結果 — はすべて `collect()` を呼ぶたびに再評価する.
 * サードパーティのフィルターが `get_current_blog_id()` で分岐している可能性があるため.
 */
class WPMAR_Check_Auto_Updates {

	/**
	 * ファイルシステム/定数のみに由来する site gate の入力値。リクエスト単位でキャッシュする
	 * （クラス docblock の「静的（リクエストスコープの）キャッシュ」を参照）。
	 * フィルター由来の値は意図的に一切含めない.
	 *
	 * @var array<string,mixed>|null
	 */
	protected static $fs_gate_cache = null;

	/**
	 * 静的な FS-gate キャッシュをクリアする. テスト用（各テストはクリーンな状態から
	 * 始めるべき）に加え、将来マルチサイト構成でサイトごとにファイルシステムパスが分かれる
	 * ようなケースが出てきた場合、`switch_to_blog()` の境界をまたいで呼んでも安全
	 * （現状は wp-content、したがって WP_PLUGIN_DIR/get_theme_root() もネットワーク全体で
	 * 共有されるため、これは予防的な措置）.
	 *
	 * @return void
	 */
	public static function reset_site_gates_cache() {
		self::$fs_gate_cache = null;
	}

	/**
	 * データセット向けに `auto_updates` エンベロープを構築する.
	 *
	 * @param array<string,mixed> $settings 設定値。未使用: このチェック用のオプトアウト設定は
	 *                                      まだ存在しない（プラン参照 — HTTP を伴わず軽量なため
	 *                                      追加しなかった）。{@see WPMAR_Check_Security_Ops::collect()}
	 *                                      とシグネチャを揃えるために引数として残している.
	 * @param array<string,mixed> $dataset  {@see WPMAR_Data_Collector::gather()} から渡される
	 *                                      構築途中のデータセット。`plugins.inventory` /
	 *                                      `themes.inventory`（この時点で既に構築済み）を読むので、
	 *                                      このクラス自身は `get_plugins()` / `wp_get_themes()` を
	 *                                      呼ぶ必要がない。既定は空配列とし、これらのキーを
	 *                                      持たない呼び出し元でも notice を出さず空のプラグイン/
	 *                                      テーマ行を返す.
	 * @return array<string,mixed>
	 */
	public function collect( array $settings, array $dataset = array() ) {
		unset( $settings );

		if ( ! class_exists( 'WP_Automatic_Updater' ) || ! class_exists( 'Core_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}

		$site = $this->resolve_site_slice();

		$plugins_inventory = isset( $dataset['plugins']['inventory'] ) && is_array( $dataset['plugins']['inventory'] )
			? $dataset['plugins']['inventory']
			: array();
		$themes_inventory  = isset( $dataset['themes']['inventory'] ) && is_array( $dataset['themes']['inventory'] )
			? $dataset['themes']['inventory']
			: array();

		return array(
			'site'    => $site,
			'core'    => $this->collect_core( $site ),
			'plugins' => $this->collect_items_for_type( 'plugin', $plugins_inventory, $site ),
			'themes'  => $this->collect_items_for_type( 'theme', $themes_inventory, $site ),
		);
	}

	// -------------------------------------------------------------------------------
	// site スライス
	// -------------------------------------------------------------------------------

	/**
	 * サイトゲートへのファイルシステム/定数のみの入力値を、静的キャッシュから返す
	 * （クラス docblock を参照）。フィルター由来の値には一切触れない.
	 *
	 * @return array{file_mod_allowed: bool, installing: bool, constant_disabled: bool,
	 *               vcs_checkout: array<string,bool>}
	 */
	protected function fs_gate_inputs() {
		if ( null !== self::$fs_gate_cache ) {
			return self::$fs_gate_cache;
		}

		$updater = class_exists( 'WP_Automatic_Updater' ) ? new WP_Automatic_Updater() : null;

		self::$fs_gate_cache = array(
			'file_mod_allowed'  => function_exists( 'wp_is_file_mod_allowed' ) ? (bool) wp_is_file_mod_allowed( 'automatic_updater' ) : true,
			'installing'        => function_exists( 'wp_installing' ) && wp_installing(),
			'constant_disabled' => defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED,
			// タイプ別の context（事実6）: WP_PLUGIN_DIR 配下の `.git` がコアをブロックしては
			// いけない。is_vcs_checkout() は $context に関わらず常に ABSPATH からも遡って探す.
			'vcs_checkout'      => array(
				'core'   => $updater ? (bool) $updater->is_vcs_checkout( defined( 'ABSPATH' ) ? ABSPATH : '' ) : false,
				'plugin' => $updater ? (bool) $updater->is_vcs_checkout( defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '' ) : false,
				'theme'  => $updater ? (bool) $updater->is_vcs_checkout( function_exists( 'get_theme_root' ) ? get_theme_root() : '' ) : false,
			),
		);

		return self::$fs_gate_cache;
	}

	/**
	 * `site` スライスを構築する: `is_disabled()` の 4 入力から再導出したゲートの事実
	 * （`is_disabled()` 自体は一切呼ばない — 事実5）に加え、タイプ別の
	 * 「管理画面のトグルがそもそも参照されるか」の事実を含む.
	 *
	 * @return array<string,mixed>
	 */
	protected function resolve_site_slice() {
		$fs = $this->fs_gate_inputs();

		// automatic_updater_disabled はフィルター由来（サードパーティが blog ID で分岐しうる）
		// なので、$fs の値とは違いキャッシュせず、呼び出しのたびに再評価する.
		$filter_disabled_probe = $this->guarded_filter(
			'automatic_updater_disabled',
			$fs['constant_disabled'],
			array(),
			'site:automatic_updater_disabled'
		);
		$filter_disabled       = (bool) $filter_disabled_probe['result'];

		$gate = self::resolve_site_gate(
			array(
				'file_mod_allowed'  => $fs['file_mod_allowed'],
				'installing'        => $fs['installing'],
				'constant_disabled' => $fs['constant_disabled'],
				'filter_disabled'   => $filter_disabled,
				'vcs_checkout'      => $fs['vcs_checkout'],
			)
		);

		// wp_is_auto_update_enabled_for_type() はここでは呼ばない: この関数は内部で
		// is_disabled()（wp-admin/includes/update.php）を再実行するため、
		// wp_is_file_mod_allowed() と automatic_updater_disabled フィルターが二重に走る.
		// 上ですでに評価済みの $gate['updater_disabled'] があるので、これに重ねて
		// タイプ別フィルターだけを追加で実行すればよい.
		$plugins_type_enabled_probe = $this->guarded_filter(
			'plugins_auto_update_enabled',
			! $gate['updater_disabled'],
			array(),
			'site:plugins_auto_update_enabled'
		);
		$themes_type_enabled_probe  = $this->guarded_filter(
			'themes_auto_update_enabled',
			! $gate['updater_disabled'],
			array(),
			'site:themes_auto_update_enabled'
		);

		global $wpdb;
		$mysql_version = ( $wpdb instanceof wpdb && method_exists( $wpdb, 'db_version' ) ) ? (string) $wpdb->db_version() : '';

		// 情報提供のみ（事実2の既知の限界）: has_filter() はコールバックが渡された値と
		// 同じ値を返した場合でも true になるため、`overridden` が全項目で false のままでも
		// これが非空になることがある。state の導出には一切使わない.
		$filters_present = array();
		if ( function_exists( 'has_filter' ) ) {
			foreach ( array( 'automatic_updater_disabled', 'auto_update_plugin', 'auto_update_theme' ) as $hook ) {
				if ( has_filter( $hook ) ) {
					$filters_present[] = $hook;
				}
			}
		}

		return array(
			'file_mod_allowed'          => $fs['file_mod_allowed'],
			'installing'                => $fs['installing'],
			'constant_disabled'         => $fs['constant_disabled'],
			'filter_disabled'           => $filter_disabled,
			'updater_disabled'          => $gate['updater_disabled'],
			'vcs_checkout'              => $gate['vcs_checkout'],
			'site_gate_blocked'         => $gate['site_gate_blocked'],
			'disabled_reason'           => $gate['disabled_reason'],
			// 意図的に一切評価しない — クラス docblock の「`effective` は限定的な概念」を参照.
			'filesystem_credentials_ok' => null,
			'filters_present'           => $filters_present,
			'plugins_type_enabled'      => (bool) $plugins_type_enabled_probe['result'],
			'themes_type_enabled'       => (bool) $themes_type_enabled_probe['result'],
			'php_version'               => PHP_VERSION,
			'mysql_version'             => $mysql_version,
			'is_multisite'              => function_exists( 'is_multisite' ) && is_multisite(),
		);
	}

	// -------------------------------------------------------------------------------
	// プラグイン / テーマ
	// -------------------------------------------------------------------------------

	/**
	 * プラグインまたはテーマのアイテムごとの事実を収集する。両者は `should_update()`
	 * （事実1）のルールをすべて共有し、違うのはオプション/トランジェント/フィルター名と、
	 * どの inventory フィールドを識別キーにするか（プラグインは basename、テーマは
	 * stylesheet）だけである.
	 *
	 * @param string              $type      'plugin' または 'theme'.
	 * @param array<int,array>    $inventory `$dataset['plugins']['inventory']` または
	 *                                       `['themes']['inventory']`.
	 * @param array<string,mixed> $site      {@see self::resolve_site_slice()} の戻り値.
	 * @return array<string,array<string,mixed>> 識別子（basename/stylesheet）をキーにした配列.
	 */
	protected function collect_items_for_type( $type, array $inventory, array $site ) {
		$is_plugin    = ( 'plugin' === $type );
		$option_key   = $is_plugin ? 'auto_update_plugins' : 'auto_update_themes';
		$hook         = $is_plugin ? 'auto_update_plugin' : 'auto_update_theme';
		$transient    = get_site_transient( $is_plugin ? 'update_plugins' : 'update_themes' );
		$type_enabled = $is_plugin ? $site['plugins_type_enabled'] : $site['themes_type_enabled'];
		$gate_blocked = $site['site_gate_blocked'][ $type ];

		// auto_update_themes は site option として存在しないことが実際にある（この環境で
		// 観測済み）。get_site_option() の既定値はプラグイン側にも適用しておく —
		// 同様に何も書き込まれていない環境で auto_update_plugins が欠けている場合に備えて.
		$toggle_list = get_site_option( $option_key, array() );

		$response  = ( is_object( $transient ) && isset( $transient->response ) && is_array( $transient->response ) )
			? $transient->response
			: array();
		$no_update = ( is_object( $transient ) && isset( $transient->no_update ) && is_array( $transient->no_update ) )
			? $transient->no_update
			: array();

		$rows = array();

		foreach ( $inventory as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$key   = $is_plugin
				? ( isset( $entry['basename'] ) ? (string) $entry['basename'] : '' )
				: ( isset( $entry['slug'] ) ? (string) $entry['slug'] : '' );
			$slug  = isset( $entry['slug'] ) ? (string) $entry['slug'] : '';
			$label = $is_plugin
				? ( isset( $entry['title'] ) ? (string) $entry['title'] : $slug )
				: ( isset( $entry['name'] ) ? (string) $entry['name'] : $slug );

			if ( '' === $key ) {
				continue;
			}

			$offer_bucket = '';
			$raw_offer    = null;
			if ( array_key_exists( $key, $response ) ) {
				$offer_bucket = 'response';
				$raw_offer    = $response[ $key ];
			} elseif ( array_key_exists( $key, $no_update ) ) {
				$offer_bucket = 'no_update';
				$raw_offer    = $no_update[ $key ];
			}
			// どちらのバケットにもない場合: このアイテムは wp.org にもサードパーティの
			// 更新サーバーにも登録されていない（事実8 — この環境で実際に3件観測済み）。
			// has_offer は false のままとし、フィルターも呼ばない（下記を参照）.

			$offer     = ( null !== $raw_offer ) ? self::normalize_offer( $raw_offer, $type ) : null;
			$has_offer = null !== $offer;

			$toggle             = self::toggle_from_option( $toggle_list, $key );
			$offer_autoupdate   = $has_offer && ! empty( $offer['autoupdate'] );
			$disable_autoupdate = $has_offer && ! empty( $offer['disable_autoupdate'] );
			$requires_php       = $has_offer && isset( $offer['requires_php'] ) ? self::sanitize_version_token( $offer['requires_php'] ) : '';
			$requires_wp        = $has_offer && isset( $offer['requires'] ) ? self::sanitize_version_token( $offer['requires'] ) : '';
			$new_version        = $has_offer && isset( $offer['new_version'] ) ? self::sanitize_version_token( $offer['new_version'] ) : '';
			$update_source      = $has_offer && isset( $offer['id'] ) ? self::update_source_from_id( $offer['id'] ) : '';

			$php_ok = ( '' === $requires_php ) || version_compare( PHP_VERSION, $requires_php, '>=' );

			// 記録のみ — 事実1: WP バージョン要件は `should_update()` のゲートではない.
			$wp_ok = true;
			if ( '' !== $requires_wp ) {
				$wp_current = isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '';
				$wp_ok      = ( '' === $wp_current ) || version_compare( $wp_current, $requires_wp, '>=' );
			}

			$filter_ran    = false;
			$filter_error  = false;
			$filter_result = null;

			if ( $has_offer ) {
				// フィルター適用前の値。should_update() のフィルター呼び出し直前まで
				// （そこは含まない）を再現する（事実1: disable_autoupdate はフィルターより
				// 前に適用され、フィルターが最終決定権を持つ）.
				$pre = $offer_autoupdate;
				if ( ! $pre && $type_enabled ) {
					$pre = $toggle;
				}
				if ( $disable_autoupdate ) {
					$pre = false;
				}

				// $item は実際のオファーからのみ組み立てる。オファーのないアイテムに対して
				// 捏造することは絶対にしない（プランの「未知パターンへの備え」の注記:
				// 偽の $item をサードパーティフィルターに渡してはいけない）.
				$item_obj = (object) $offer;

				$probe = $this->guarded_filter( $hook, $pre, array( $item_obj ), 'item:' . $type . ':' . $key );

				$filter_ran    = $probe['ran'];
				$filter_error  = $probe['error'];
				$filter_result = $probe['result'];
			}

			$rows[ $key ] = self::evaluate_item(
				array(
					'key'                => $key,
					'slug'               => $slug,
					'label'              => $label,
					'toggle'             => $toggle,
					'offer_autoupdate'   => $offer_autoupdate,
					'type_enabled'       => $type_enabled,
					'disable_autoupdate' => $disable_autoupdate,
					'filter_ran'         => $filter_ran,
					'filter_error'       => $filter_error,
					'filter_result'      => $filter_result,
					'php_ok'             => $php_ok,
					'has_offer'          => $has_offer,
					'site_gate_blocked'  => $gate_blocked,
					'requires_php'       => $requires_php,
					'requires_wp'        => $requires_wp,
					'wp_ok'              => $wp_ok,
					'offer_bucket'       => $offer_bucket,
					'update_source'      => $update_source,
					'new_version'        => $new_version,
				)
			);
		}

		return $rows;
	}

	// -------------------------------------------------------------------------------
	// コア
	// -------------------------------------------------------------------------------

	/**
	 * コアの事実を収集する。プラグイン/テーマと違い、「このアイテムの自動更新は ON か」
	 * という問いは、保留中のオファーがあれば（クラス docblock を参照）
	 * `Core_Upgrader::should_update_to_version()` に委譲し、軸ごとに再導出することはしない.
	 *
	 * @param array<string,mixed> $site {@see self::resolve_site_slice()} の戻り値.
	 * @return array<string,mixed>
	 */
	protected function collect_core( array $site ) {
		$options = array(
			'dev'   => (string) get_site_option( 'auto_update_core_dev', 'enabled' ),
			'minor' => (string) get_site_option( 'auto_update_core_minor', 'enabled' ),
			'major' => (string) get_site_option( 'auto_update_core_major', 'unset' ),
		);

		$constant_defined = defined( 'WP_AUTO_UPDATE_CORE' );
		$constant_value   = $constant_defined ? WP_AUTO_UPDATE_CORE : null;
		$constant_code    = self::core_constant_code( $constant_defined, $constant_value );
		// 正規化コードとあわせて生の値も保持しておく。レポートの「constant」由来の注記で、
		// 内部の 'undefined'|'all'|'minor'|'false' のバケットではなく、実際に設定されている値
		// （例: 'minor'、'true'）を名指しできるようにするため.
		$constant_raw = self::format_core_constant_raw( $constant_defined, $constant_value );

		// 事実7: `critical` フラグのみを見る。実際の failure_data 構造には
		// `current`/`attempted`/`retry`（非 critical な失敗に対する「同じ A→B ジャンプの
		// リトライを禁止する」ガード — class-wp-automatic-updater.php /
		// class-core-upgrader.php を参照）もあるが、これは意図的に再現しない:
		// このクラスが報告する on/off の state モデルに収まらない、狭く一時的なルールであり、
		// 再現してもコア自身の記録を複製するだけでレポートとしての価値は乏しい.
		$failure_data     = get_site_option( 'auto_core_update_failed' );
		$critical_failure = is_array( $failure_data ) && ! empty( $failure_data['critical'] );

		$core_updates = function_exists( 'get_core_updates' ) ? get_core_updates( array( 'dismissed' => false ) ) : array();
		$offer        = $this->pick_core_offer( $core_updates );
		$has_offer    = null !== $offer;

		$decision        = false;
		$branch_relation = 'none';
		$php_ok          = true;
		$mysql_ok        = true;
		$offer_out       = array(
			'version'       => '',
			'response'      => '',
			'php_version'   => '',
			'mysql_version' => '',
			'php_ok'        => true,
			'mysql_ok'      => true,
			'kind'          => '',
		);

		if ( $has_offer ) {
			$offered_ver = isset( $offer->current ) ? (string) $offer->current : '';
			$wp_current  = isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '';

			$current_branch  = self::branch_of( $wp_current );
			$new_branch      = self::branch_of( $offered_ver );
			$branch_relation = self::core_branch_relation( $wp_current, $offered_ver, $current_branch, $new_branch );

			$req_php   = isset( $offer->php_version ) ? (string) $offer->php_version : '';
			$req_mysql = isset( $offer->mysql_version ) ? (string) $offer->mysql_version : '';

			$php_ok = ( '' === $req_php ) || version_compare( PHP_VERSION, $req_php, '>=' );

			global $wpdb;
			$mysql_current = ( $wpdb instanceof wpdb && method_exists( $wpdb, 'db_version' ) ) ? (string) $wpdb->db_version() : '';
			$mysql_ok      = ( '' === $req_mysql || '' === $mysql_current ) || version_compare( $mysql_current, $req_mysql, '>=' );

			$decision_probe = $this->guarded_core_decision( $offered_ver );
			$decision       = $decision_probe['result'];

			$response_code = isset( $offer->response ) ? sanitize_key( (string) $offer->response ) : '';

			$offer_out = array(
				'version'       => self::sanitize_version_token( $offered_ver ),
				'response'      => $response_code,
				'php_version'   => self::sanitize_version_token( $req_php ),
				'mysql_version' => self::sanitize_version_token( $req_mysql ),
				'php_ok'        => $php_ok,
				'mysql_ok'      => $mysql_ok,
				'kind'          => $response_code,
			);
		}

		$evaluated = self::evaluate_core(
			array(
				'site_gate_blocked' => $site['site_gate_blocked']['core'],
				'has_offer'         => $has_offer,
				'decision'          => $decision,
				'branch_relation'   => $branch_relation,
				'php_ok'            => $php_ok,
				'mysql_ok'          => $mysql_ok,
				'raw'               => array(
					'options'          => $options,
					'constant'         => $constant_code,
					'constant_raw'     => $constant_raw,
					'critical_failure' => $critical_failure,
				),
			)
		);

		$evaluated['offer']     = $offer_out;
		$evaluated['effective'] = $has_offer ? ( $decision && $php_ok && $mysql_ok ) : null;

		return $evaluated;
	}

	/**
	 * `get_core_updates()` から最初の実質的な upgrade/development オファーを選ぶ。
	 * なければ null.
	 *
	 * {@see WPMAR_Data_Collector::pending_core_upgrade_versions()} と同じレスポンスコードの
	 * フィルタリング基準（`latest`/空のレスポンスは同一バージョンの再インストール提案であり、
	 * 保留中の更新ではない — wp-admin/update-core.php の list_core_update() を参照）を
	 * ミラーするが、`should_update_to_version()` と PHP/MySQL 要件チェックがオファー全体を
	 * 必要とするため、バージョン文字列ではなく生のオブジェクトを返す.
	 *
	 * @param array<int,object>|false $raw_updates {@see get_core_updates()} の戻り値.
	 * @return object|null
	 */
	protected function pick_core_offer( $raw_updates ) {
		if ( empty( $raw_updates ) || ! is_array( $raw_updates ) ) {
			return null;
		}

		foreach ( $raw_updates as $u ) {
			if ( ! is_object( $u ) ) {
				continue;
			}

			$response = isset( $u->response ) ? sanitize_key( (string) $u->response ) : '';
			if ( '' === $response || 'latest' === $response ) {
				continue;
			}
			if ( 'upgrade' !== $response && 'development' !== $response ) {
				continue;
			}

			return $u;
		}

		return null;
	}

	// -------------------------------------------------------------------------------
	// フィルター実行の隔離
	// -------------------------------------------------------------------------------

	/**
	 * `apply_filters()` を例外と意図しない出力の両方から隔離して実行する（クラス docblock の
	 * 「フィルター実行の隔離は2種類だけであり、それ以外はない」を参照）。出力はバイト数のみを
	 * ログに残す — 中身そのものは一切表に出さない（任意のサードパーティ由来の出力のため）.
	 *
	 * @param string       $hook_name フック名.
	 * @param mixed        $pre       フィルター適用前の初期値.
	 * @param array<mixed> $args      apply_filters() に $pre の後に渡す追加引数.
	 * @param string       $log_key   WPMAR_Logger::step() 用の識別子.
	 * @return array{result: mixed, ran: bool, error: bool}
	 */
	protected function guarded_filter( $hook_name, $pre, array $args, $log_key ) {
		$ran      = false;
		$error    = false;
		$result   = $pre;
		$buffered = '';

		try {
			ob_start();
			$result = apply_filters( $hook_name, $pre, ...$args );
			$ran    = true;
		} catch ( Throwable $e ) {
			$error  = true;
			$result = $pre;
		} finally {
			// try の直後ではなく finally に置くのは、コールバックが例外を投げた場合でも
			// 意図しない warning/echo を確実に捕捉するため — ここでバッファを閉じ忘れると、
			// 以降のリクエスト全体の出力が黙って飲み込まれてしまう.
			if ( ob_get_level() > 0 ) {
				$buffered = (string) ob_get_clean();
			}
		}

		if ( '' !== $buffered ) {
			$this->log_filter_output( $log_key, strlen( $buffered ) );
		}

		return array(
			'result' => $result,
			'ran'    => $ran,
			'error'  => $error,
		);
	}

	/**
	 * 捕捉した出力のバイト数だけをログに残す（中身は絶対に残さない — クラス docblock を参照）。
	 * {@see self::guarded_filter()} / {@see self::guarded_core_decision()} からこの1行だけを
	 * 切り出しているのは、テストが本物の `WPMAR_Logger`（`WPMAR_Private_Storage` /
	 * `WPMAR_Jobs_Repository` を引き込みディスクに書き込む）を必要とせず、この関数だけを
	 * オーバーライドできるようにするため.
	 *
	 * @param string $log_key ログエントリの識別子.
	 * @param int    $bytes   捕捉したバイト数.
	 * @return void
	 */
	protected function log_filter_output( $log_key, $bytes ) {
		if ( class_exists( 'WPMAR_Logger' ) ) {
			WPMAR_Logger::step(
				'gather:auto-updates:filter-output',
				array(
					'key'   => $log_key,
					'bytes' => $bytes,
				)
			);
		}
	}

	/**
	 * {@see self::guarded_filter()} と同じ隔離のもとで `Core_Upgrader::should_update_to_version()`
	 * を実行する — 内部で dev/minor/major ゲート用の `apply_filters()` を呼ぶため、
	 * 同じサードパーティの信頼境界が当てはまる.
	 *
	 * @param string $offered_ver オファーされたコアバージョン.
	 * @return array{result: bool, error: bool}
	 */
	protected function guarded_core_decision( $offered_ver ) {
		$result   = false;
		$error    = false;
		$buffered = '';

		try {
			ob_start();
			if ( class_exists( 'Core_Upgrader' ) && method_exists( 'Core_Upgrader', 'should_update_to_version' ) ) {
				$result = (bool) Core_Upgrader::should_update_to_version( $offered_ver );
			}
		} catch ( Throwable $e ) {
			$error  = true;
			$result = false;
		} finally {
			if ( ob_get_level() > 0 ) {
				$buffered = (string) ob_get_clean();
			}
		}

		if ( '' !== $buffered ) {
			$this->log_filter_output( 'core:should_update_to_version', strlen( $buffered ) );
		}

		return array(
			'result' => $result,
			'error'  => $error,
		);
	}

	// =================================================================================
	// 純関数（WP API を一切呼ばない。以下の各分岐はいずれも WordPress のブートストラップなしで
	// 単体テスト可能 — tests/CheckAutoUpdatesTest.php を参照）.
	// =================================================================================

	/**
	 * プラグイン/テーマの更新トランジェントの1エントリを、プレーンな配列に正規化する.
	 *
	 * `update_plugins` の `response`/`no_update` エントリは stdClass だが、`update_themes` の
	 * それはプレーン配列（WP 側でキャストの仕方が異なる — wp-includes/update.php を参照）。
	 * どちらの形も同じ連想配列構造に正規化することで、このクラスの他の部分が型で分岐する
	 * 必要をなくしている.
	 *
	 * @param mixed  $entry 生のトランジェントエントリ.
	 * @param string $type  'plugin' または 'theme'。現状は未使用（どちらの形も同じに正規化
	 *                      される）だが、将来の WP バージョンでタイプ別のフィールド処理が
	 *                      必要になった場合に備えてシグネチャに残している.
	 * @return array<string,mixed>|null 空でないオブジェクト/配列以外はすべて null.
	 */
	public static function normalize_offer( $entry, $type ) {
		unset( $type );

		if ( $entry instanceof stdClass ) {
			$entry = (array) $entry;
		}

		if ( ! is_array( $entry ) || empty( $entry ) ) {
			return null;
		}

		return $entry;
	}

	/**
	 * `$key` が `get_site_option( 'auto_update_{plugins,themes}', ... )` のリストに
	 * 含まれているか.
	 *
	 * この環境では `auto_update_themes` が site option として一切存在しない
	 * （空配列ではなく `false` が返る）ことが実際に観測されているため、非配列のケースは
	 * `in_array()` から PHP warning を出させるのではなく「何もトグルされていない」として
	 * 扱う必要がある.
	 *
	 * @param mixed  $raw_option トグルリスト用 `get_site_option()` の戻り値.
	 * @param string $key        プラグインの basename またはテーマの stylesheet.
	 * @return bool
	 */
	public static function toggle_from_option( $raw_option, $key ) {
		$list = is_array( $raw_option ) ? $raw_option : array();

		return in_array( (string) $key, $list, true );
	}

	/**
	 * 更新オファーの `id`（例: `w.org/plugins/akismet`、`github.com/owner/repo`、または
	 * サードパーティの更新サーバー URL）から更新元のホストを抽出しサニタイズする.
	 *
	 * これは信頼できない外部入力（どの更新サーバーでも設定できる）であり、Markdown の
	 * レポート本文やスナップショット差分の行に流れ込む。`sanitize_text_field()` だけでは
	 * 不十分: 改行は潰すが `|`（スナップショットプレビューの GFM テーブル行を壊しうる）は
	 * 除去せず、長さ制限もない。順序が重要: スキームは '/' で分割する*前*に剥がす必要がある。
	 * そうしないと `https://evil.example/x` は、`https:` から非ホスト文字を除去した結果
	 * 「host」として `https` を返してしまう.
	 *
	 * @param mixed $id 更新オファーの生の `id` フィールド.
	 * @return string ホストのみ、`[A-Za-z0-9.-]`、最大64文字.
	 */
	public static function update_source_from_id( $id ) {
		$id = (string) $id;
		$id = preg_replace( '#^[a-z][a-z0-9+.\-]*://#i', '', $id );

		$head = explode( '/', (string) $id )[0];
		$head = preg_replace( '/[^A-Za-z0-9.\-]/', '', (string) $head );

		return substr( (string) $head, 0, 64 );
	}

	/**
	 * 更新オファーのバージョン様トークン（`new_version`、`requires_php`、`requires`）を
	 * レポートやスナップショットに届く前にサニタイズする。信頼できない入力である理由は
	 * {@see self::update_source_from_id()} と同様で、バージョン文字列として妥当な文字種に
	 * 制限する.
	 *
	 * @param mixed $value 生の値.
	 * @return string
	 */
	public static function sanitize_version_token( $value ) {
		$value = preg_replace( '/[^0-9A-Za-z.\-+]/', '', (string) $value );

		return substr( (string) $value, 0, 32 );
	}

	/**
	 * プラグインまたはテーマ1件分の事実レイヤー全体 + 表示用 `state` を導出する.
	 *
	 * `WP_Automatic_Updater::should_update()`（事実1）をフィルター呼び出しの直前まで再現し、
	 * フィルターの結果をコアの `if ( ! $update )` と全く同じように適用し（`null` の戻り値は
	 * 「介入なし」ではなく `false` — 事実3）、その上に優先順位に従って `state` ラベルを
	 * 重ねる。`state` はあくまで表示用ラベルであり、入力事実はすべて（スナップショット差分や
	 * 拡張フィルターなど何かに使う場合に備えて）戻り値にそのまま保持する.
	 *
	 * state の優先順位（最初に一致したものが勝つ）:
	 *   1. site_gate_blocked            -> disabled_site
	 *   2. ! has_offer                  -> no_update_source
	 *   3. decision && ! php_ok         -> blocked_php（effective ではなく decision である点に
	 *                                      注意: effective は既に decision && php_ok なので、
	 *                                      effective で判定するとこの分岐に到達できなくなる）
	 *   4. overridden && decision       -> filter_forced_on
	 *   5. overridden && ! decision     -> filter_forced_off
	 *   6. decision                     -> on
	 *   7. ! type_enabled && ! offer_autoupdate && toggle -> type_disabled
	 *      （toggle=true が必須: これがなければサイト全体の type ゲートが上書きしている
	 *      「管理画面の設定」自体が存在しないので、単なる `off` になる）
	 *   8. それ以外                     -> off
	 *
	 * `$raw` から読む入力事実（それ以外のキーはそのまま素通りする）:
	 *   - key/slug/label            識別子（basename/stylesheet）と表示ラベル.
	 *   - toggle                    `auto_update_{plugins,themes}` リストに含まれているか.
	 *   - offer_autoupdate          更新オファーの `$item->autoupdate`.
	 *   - type_enabled              サイト全体の「トグルがそもそも参照されるか」のゲート.
	 *   - disable_autoupdate        更新オファーの `$item->disable_autoupdate`.
	 *   - filter_ran / filter_error `auto_update_{plugin,theme}` が実行されたか / 例外を
	 *                               投げたか.
	 *   - filter_result             フィルターの生の戻り値（null もありうる）.
	 *   - php_ok                    PHP 要件を満たしているか（要件がない場合も含む）.
	 *   - has_offer                 `response` または `no_update` のいずれかのバケットに
	 *                               存在するか.
	 *   - site_gate_blocked         このタイプのサイト全体ゲート（事実6、タイプ別）.
	 *
	 * @param array<string,mixed> $raw 入力事実（上記一覧を参照）.
	 * @return array<string,mixed> `$raw` に導出済みの事実レイヤーと `state` をマージしたもの.
	 */
	public static function evaluate_item( array $raw ) {
		$toggle             = ! empty( $raw['toggle'] );
		$offer_autoupdate   = ! empty( $raw['offer_autoupdate'] );
		$type_enabled       = ! empty( $raw['type_enabled'] );
		$disable_autoupdate = ! empty( $raw['disable_autoupdate'] );
		$has_offer          = ! empty( $raw['has_offer'] );
		$php_ok             = array_key_exists( 'php_ok', $raw ) ? (bool) $raw['php_ok'] : true;
		$site_gate_blocked  = ! empty( $raw['site_gate_blocked'] );
		$filter_ran         = ! empty( $raw['filter_ran'] );
		$filter_error       = ! empty( $raw['filter_error'] );
		$filter_result      = array_key_exists( 'filter_result', $raw ) ? $raw['filter_result'] : null;

		// フィルター適用前の値。should_update() を disable_autoupdate の適用までは再現する
		// （事実1）.
		$pre_filter = $offer_autoupdate;
		if ( ! $pre_filter && $type_enabled ) {
			$pre_filter = $toggle;
		}
		if ( $disable_autoupdate ) {
			$pre_filter = false;
		}

		if ( $filter_error ) {
			// フィルターが例外を投げた場合のみ、フィルター適用前の値にフォールバックする
			// （クラス docblock の「フィルター実行の隔離」を参照）.
			$decision = $pre_filter;
		} else {
			// (bool) キャスト: フィルターの戻り値が null なら false になる。コアの
			// `if ( ! $update )` と同じ（事実3）— ここで $pre_filter にフォールバックすると
			// 結果が逆転してしまうので絶対にしない.
			$decision = (bool) $filter_result;
		}

		// 既知の限界（事実2）: フィルターが渡された値と同じ値を返した場合、実際には
		// コールバックが実行されていても「上書きなし」と読めてしまう。
		// site.filters_present（has_filter()）がこれを情報として別途保持する.
		$overridden = $filter_ran && ! $filter_error && ( $decision !== $pre_filter );

		// WP バージョン要件はゲートではない（事実1）。ファイルシステム資格情報のチェックも
		// 一切評価しない（クラス docblock を参照）— そのため意図的に「確実に自動更新が走る」
		// よりも狭い意味になっている.
		$effective = $decision && $php_ok;

		if ( $site_gate_blocked ) {
			$state = 'disabled_site';
		} elseif ( ! $has_offer ) {
			$state = 'no_update_source';
		} elseif ( $decision && ! $php_ok ) {
			$state = 'blocked_php';
		} elseif ( $overridden && $decision ) {
			$state = 'filter_forced_on';
		} elseif ( $overridden && ! $decision ) {
			$state = 'filter_forced_off';
		} elseif ( $decision ) {
			$state = 'on';
		} elseif ( ! $type_enabled && ! $offer_autoupdate && $toggle ) {
			$state = 'type_disabled';
		} else {
			$state = 'off';
		}

		return array_merge(
			$raw,
			array(
				'toggle'             => $toggle,
				'offer_autoupdate'   => $offer_autoupdate,
				'type_enabled'       => $type_enabled,
				'disable_autoupdate' => $disable_autoupdate,
				'pre_filter'         => $pre_filter,
				'filter_ran'         => $filter_ran,
				'filter_error'       => $filter_error,
				'filter_result'      => $filter_result,
				'decision'           => $decision,
				'overridden'         => $overridden,
				'php_ok'             => $php_ok,
				'effective'          => $effective,
				'has_offer'          => $has_offer,
				'site_gate_blocked'  => $site_gate_blocked,
				'state'              => $state,
			)
		);
	}

	/**
	 * コアの事実レイヤー全体 + 表示用 `state` を導出する.
	 *
	 * {@see self::evaluate_item()} と違い、保留中のオファーに対する実際の on/off の判定は
	 * ここでは再導出しない — `$raw['decision']` として計算済みの値
	 * （`Core_Upgrader::should_update_to_version()` の戻り値。理由はクラス docblock を参照）
	 * がそのまま渡ってくる。この関数の役目は、その判定結果とオファーのブランチ関係から
	 * 表示用の `state` を組み立てること、そして保留中のオファーがない場合は
	 * dev/minor/major オプションと `WP_AUTO_UPDATE_CORE` 定数を純粋に静的（フィルターなし）
	 * に読んだ結果へフォールバックすることである.
	 *
	 * state の優先順位（最初に一致したものが勝つ）:
	 *   1. site_gate_blocked                                  -> disabled_site
	 *   2. critical_failure                                   -> blocked_failure
	 *   3. has_offer && (! php_ok || ! mysql_ok)               -> blocked_requirements
	 *   4. has_offer && decision && branch_relation === major -> major（axes.major との差分を
	 *      overridden とする）
	 *   5. has_offer && decision && branch_relation === same  -> minor（axes.minor との差分を
	 *      overridden とする）
	 *   6. has_offer（decision が false、または branch_relation が none） -> disabled
	 *   7. ! has_offer && axes.major                           -> major
	 *   8. ! has_offer && axes.minor                            -> minor
	 *   9. ! has_offer && axes.dev                              -> dev_only
	 *  10. それ以外                                            -> disabled
	 *
	 * `$raw` から読む入力事実:
	 *   - site_gate_blocked      コアのサイト全体ゲート（事実6、タイプ別）.
	 *   - has_offer              `get_core_updates()` が保留中の更新を返したか.
	 *   - decision               `should_update_to_version()` の結果（has_offer のときのみ
	 *                            意味を持つ）.
	 *   - branch_relation        'major'|'same'|'none' — {@see self::core_branch_relation()}
	 *                            を参照.
	 *   - php_ok / mysql_ok      要件を満たしているか（has_offer のときのみ意味を持つ）.
	 *   - raw.options            ['dev'=>'enabled'|'disabled', 'minor'=>..., 'major'=>'enabled'|'unset'].
	 *   - raw.constant           {@see self::core_constant_code()} の出力.
	 *   - raw.critical_failure   直近の自動更新の試行が致命的に失敗したか.
	 *
	 * @param array<string,mixed> $raw 入力事実（上記一覧を参照）.
	 * @return array<string,mixed> `$raw` に `state`、`axes`、`overridden`、`source` を
	 *                              マージしたもの.
	 */
	public static function evaluate_core( array $raw ) {
		$raw_slice         = isset( $raw['raw'] ) && is_array( $raw['raw'] ) ? $raw['raw'] : array();
		$options           = isset( $raw_slice['options'] ) && is_array( $raw_slice['options'] ) ? $raw_slice['options'] : array();
		$constant          = isset( $raw_slice['constant'] ) ? (string) $raw_slice['constant'] : 'undefined';
		$critical_failure  = ! empty( $raw_slice['critical_failure'] );
		$site_gate_blocked = ! empty( $raw['site_gate_blocked'] );
		$has_offer         = ! empty( $raw['has_offer'] );
		$decision          = ! empty( $raw['decision'] );
		$branch_relation   = isset( $raw['branch_relation'] ) ? (string) $raw['branch_relation'] : 'none';
		$php_ok            = array_key_exists( 'php_ok', $raw ) ? (bool) $raw['php_ok'] : true;
		$mysql_ok          = array_key_exists( 'mysql_ok', $raw ) ? (bool) $raw['mysql_ok'] : true;

		// 静的な（フィルターを一切通さない）軸の読み取り: オプション、その上に
		// WP_AUTO_UPDATE_CORE 定数を重ねる（定数が優先 —
		// Core_Upgrader::should_update_to_version() の該当箇所を参照）。これは保留中の
		// オファーがなく、コアに直接尋ねられないときに表示される値である.
		$option_axes = array(
			'dev'   => 'enabled' === ( isset( $options['dev'] ) ? $options['dev'] : 'enabled' ),
			'minor' => 'enabled' === ( isset( $options['minor'] ) ? $options['minor'] : 'enabled' ),
			'major' => 'enabled' === ( isset( $options['major'] ) ? $options['major'] : 'unset' ),
		);

		switch ( $constant ) {
			case 'all':
				$axes = array(
					'dev'   => true,
					'minor' => true,
					'major' => true,
				);
				break;
			case 'minor':
				$axes = array(
					'dev'   => false,
					'minor' => true,
					'major' => false,
				);
				break;
			case 'false':
				$axes = array(
					'dev'   => false,
					'minor' => false,
					'major' => false,
				);
				break;
			default:
				$axes = $option_axes;
		}

		$source     = ( 'undefined' !== $constant ) ? 'constant' : 'option';
		$overridden = false;

		if ( $site_gate_blocked ) {
			$state = 'disabled_site';
		} elseif ( $critical_failure ) {
			$state = 'blocked_failure';
		} elseif ( $has_offer && ( ! $php_ok || ! $mysql_ok ) ) {
			$state = 'blocked_requirements';
		} elseif ( $has_offer && $decision && 'major' === $branch_relation ) {
			$state      = 'major';
			$overridden = ( $decision !== $axes['major'] );
		} elseif ( $has_offer && $decision && 'same' === $branch_relation ) {
			$state      = 'minor';
			$overridden = ( $decision !== $axes['minor'] );
		} elseif ( $has_offer ) {
			// オファーはあるが should_update_to_version() が NO と判定した
			// （フィルターが却下した、または branch_relation が 'none' — 例えば現在
			// インストール済みのバージョンを再度オファーしている場合など）.
			$state = 'disabled';
		} elseif ( $axes['major'] ) {
			$state = 'major';
		} elseif ( $axes['minor'] ) {
			$state = 'minor';
		} elseif ( $axes['dev'] ) {
			$state = 'dev_only';
		} else {
			$state = 'disabled';
		}

		if ( $overridden ) {
			$source = 'filter';
		}

		return array_merge(
			$raw,
			array(
				'state'      => $state,
				'axes'       => $axes,
				'overridden' => $overridden,
				'source'     => $source,
			)
		);
	}

	/**
	 * `is_disabled()` の4つの入力（事実5、ここでは1回だけ評価する — この関数に
	 * `is_disabled()` の呼び出し結果自体が渡されることはなく、その計算済みの入力だけが
	 * 渡される）と、タイプ別の VCS チェックアウトゲート（事実6。`should_update()` の中で
	 * `is_disabled()` とは別に並んで評価される）から、サイト全体の自動更新ゲートを解決する.
	 *
	 * タイプ別の理由の優先順位（最初に一致したものが勝つ）:
	 * file_mod > installing > constant > filter > vcs > ''。`automatic_updater_disabled`
	 * フィルターがブロックしていて、単に定数の値をそのまま通しただけの場合は、フィルターが
	 * 実質何もしていないので理由を 'filter' ではなく 'constant' として報告する.
	 *
	 * `$raw` から読む入力事実:
	 *   - file_mod_allowed  wp_is_file_mod_allowed( 'automatic_updater' ).
	 *   - installing        wp_installing().
	 *   - constant_disabled AUTOMATIC_UPDATER_DISABLED.
	 *   - filter_disabled   apply_filters( 'automatic_updater_disabled', $constant_disabled ).
	 *   - vcs_checkout      ['core'=>bool,'plugin'=>bool,'theme'=>bool].
	 *
	 * @param array<string,mixed> $raw 入力事実（上記一覧を参照）.
	 * @return array<string,mixed>
	 */
	public static function resolve_site_gate( array $raw ) {
		$file_mod_allowed  = array_key_exists( 'file_mod_allowed', $raw ) ? (bool) $raw['file_mod_allowed'] : true;
		$installing        = ! empty( $raw['installing'] );
		$constant_disabled = ! empty( $raw['constant_disabled'] );
		$filter_disabled   = ! empty( $raw['filter_disabled'] );
		$vcs_checkout      = isset( $raw['vcs_checkout'] ) && is_array( $raw['vcs_checkout'] ) ? $raw['vcs_checkout'] : array();

		$updater_disabled = ! $file_mod_allowed || $installing || $filter_disabled;

		$base_reason = '';
		if ( ! $file_mod_allowed ) {
			$base_reason = 'file_mod';
		} elseif ( $installing ) {
			$base_reason = 'installing';
		} elseif ( $filter_disabled ) {
			$base_reason = $constant_disabled ? 'constant' : 'filter';
		}

		$types             = array( 'core', 'plugin', 'theme' );
		$site_gate_blocked = array();
		$disabled_reason   = array();

		foreach ( $types as $type ) {
			$vcs                        = ! empty( $vcs_checkout[ $type ] );
			$site_gate_blocked[ $type ] = $updater_disabled || $vcs;

			if ( '' !== $base_reason ) {
				$disabled_reason[ $type ] = $base_reason;
			} elseif ( $vcs ) {
				$disabled_reason[ $type ] = 'vcs';
			} else {
				$disabled_reason[ $type ] = '';
			}
		}

		return array(
			'file_mod_allowed'  => $file_mod_allowed,
			'installing'        => $installing,
			'constant_disabled' => $constant_disabled,
			'filter_disabled'   => $filter_disabled,
			'updater_disabled'  => $updater_disabled,
			'vcs_checkout'      => $vcs_checkout,
			'site_gate_blocked' => $site_gate_blocked,
			'disabled_reason'   => $disabled_reason,
		);
	}

	/**
	 * WordPress のバージョン文字列を `major.minor` のブランチに正規化する。
	 * `Core_Upgrader::should_update_to_version()` と全く同じ方法
	 * （`preg_split( '/[.-]/', $version )` の後、最初の2セグメントを取る）で行うので、
	 * ここでのブランチ比較はコアの判定と一致する.
	 *
	 * @param string $version 例: '6.5.2'、'7.2-alpha-12345'、'7.2-RC1'.
	 * @return string 例: '6.5'、'7.2'。空文字列を渡せば空文字列を返す.
	 */
	public static function branch_of( $version ) {
		$version = (string) $version;
		if ( '' === $version ) {
			return '';
		}

		$parts = preg_split( '/[.-]/', $version );

		return implode( '.', array_slice( $parts, 0, 2 ) );
	}

	/**
	 * 保留中のコアオファーと現在インストール中のバージョンとの関係を分類する。
	 * `Core_Upgrader::should_update_to_version()` のブランチ判定ロジックをミラーする
	 * （判定そのものをこのメソッドに再導出させずコアのメソッドへ委譲した理由はクラス
	 * docblock を参照 — この分類はコアの判定結果が*どの軸*に対応するかのラベル付けのみに
	 * 使い、判定自体の計算には使わない）.
	 *
	 * @param string $current_version インストール済みの `$wp_version`.
	 * @param string $offered_version オファーされたバージョン（`$update->current`）.
	 * @param string $current_branch  `$current_version` の {@see self::branch_of()}.
	 * @param string $new_branch      `$offered_version` の {@see self::branch_of()}.
	 * @return string 'major'（新しいブランチ）、'same'（同一ブランチ内/マイナー）、
	 *                または 'none'（同一バージョン、またはオファーが実際には新しくない）.
	 */
	public static function core_branch_relation( $current_version, $offered_version, $current_branch, $new_branch ) {
		$current_version = (string) $current_version;
		$offered_version = (string) $offered_version;

		if ( '' === $offered_version || $offered_version === $current_version ) {
			return 'none';
		}
		if ( version_compare( $current_version, $offered_version, '>' ) ) {
			return 'none';
		}
		if ( $current_branch === $new_branch ) {
			return 'same';
		}
		if ( version_compare( $new_branch, $current_branch, '>' ) ) {
			return 'major';
		}

		return 'none';
	}

	/**
	 * `WP_AUTO_UPDATE_CORE` 定数（事実7）を4つのコードのいずれかに正規化する.
	 *
	 * @param bool  $defined 定数が定義されているか.
	 * @param mixed $value   定義されている場合の値（true|'beta'|'rc'|'development'|
	 *                       'branch-development'|'minor'|false）.
	 * @return string 'undefined'|'all'|'minor'|'false'.
	 */
	public static function core_constant_code( $defined, $value ) {
		if ( ! $defined ) {
			return 'undefined';
		}

		if ( true === $value || in_array( $value, array( 'beta', 'rc', 'development', 'branch-development' ), true ) ) {
			return 'all';
		}

		if ( 'minor' === $value ) {
			return 'minor';
		}

		if ( false === $value ) {
			return 'false';
		}

		return 'undefined';
	}

	/**
	 * 表示用に `WP_AUTO_UPDATE_CORE` の生の値をレンダリングする（レポートの
	 * 「この定数が制御している」という注記などに使う）。{@see self::core_constant_code()}
	 * の正規化されたバケットとは別物。`(string) true`/`(string) false` は PHP では
	 * '1'/'' を出力してしまい、運用者に見せるには分かりにくいため、真偽値は明示的に
	 * 綴りで表す.
	 *
	 * @param bool  $defined 定数が定義されているか.
	 * @param mixed $value   定義されている場合の値.
	 * @return string 未定義なら ''、それ以外は 'true'|'false'|生の文字列値.
	 */
	public static function format_core_constant_raw( $defined, $value ) {
		if ( ! $defined ) {
			return '';
		}

		if ( true === $value ) {
			return 'true';
		}

		if ( false === $value ) {
			return 'false';
		}

		return (string) $value;
	}

	/**
	 * 収集した `auto_updates` を、スナップショット用の「キー => 文字列」マップに畳み込む.
	 *
	 * スナップショットには、導出ラベルの `state` だけを記録する。トグルだけではフィルターの
	 * 導入を検出できず、`effective` だけでは理由が残らないため（プラン「スナップショット差分」）.
	 * 形式は次のとおり.
	 *
	 * - `_site`: `core:{state}|gate:{ok|blocked(種別,...)}|plugins:{on|off}|themes:{on|off}|overrides:{種別,...|none}`
	 * - `plugin:{basename}` / `theme:{stylesheet}`: その項目の `state`
	 *
	 * `overrides:` には、そのタイプで 1 件以上の項目に実際の上書き（`overridden === true`）が
	 * 起きた種別だけを、core・plugin・theme の固定の順で記録する。`has_filter()` が真なだけ
	 * （`site.filters_present`）では記録しない。無害なフィルターの追加でスナップショットの
	 * 差分が出るのを避けるため.
	 *
	 * WP API を呼ばない純関数（WordPress をロードしないテストで検証できる）.
	 *
	 * @param array<string,mixed> $auto_updates {@see self::collect()} の戻り値.
	 * @return array<string,string>
	 */
	public static function snapshot_map( array $auto_updates ) {
		$site = isset( $auto_updates['site'] ) && is_array( $auto_updates['site'] ) ? $auto_updates['site'] : array();
		$core = isset( $auto_updates['core'] ) && is_array( $auto_updates['core'] ) ? $auto_updates['core'] : array();

		$blocked = array();
		foreach ( array( 'core', 'plugin', 'theme' ) as $type ) {
			if ( ! empty( $site['site_gate_blocked'][ $type ] ) ) {
				$blocked[] = $type;
			}
		}
		$gate = empty( $blocked ) ? 'ok' : 'blocked(' . implode( ',', $blocked ) . ')';

		$overridden = array();
		if ( isset( $core['overridden'] ) && is_array( $core['overridden'] ) && in_array( true, $core['overridden'], true ) ) {
			$overridden[] = 'core';
		}

		$map = array();
		foreach ( array(
			'plugin' => 'plugins',
			'theme'  => 'themes',
		) as $type => $bucket ) {
			$rows = isset( $auto_updates[ $bucket ] ) && is_array( $auto_updates[ $bucket ] ) ? $auto_updates[ $bucket ] : array();

			foreach ( $rows as $key => $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				if ( ! empty( $row['overridden'] ) && ! in_array( $type, $overridden, true ) ) {
					$overridden[] = $type;
				}

				// キーは、プレビューの表を壊す `|` と制御文字を取り除く. 状態は小文字と `_` だけに絞る.
				$safe_key = preg_replace( '/[\x00-\x1f\x7f|]/', '', (string) $key );
				$state    = preg_replace( '/[^a-z_]/', '', strtolower( isset( $row['state'] ) ? (string) $row['state'] : '' ) );

				$map[ $type . ':' . $safe_key ] = (string) $state;
			}
		}

		ksort( $map );

		$core_state = preg_replace( '/[^a-z_]/', '', strtolower( isset( $core['state'] ) ? (string) $core['state'] : '' ) );

		$site_line = sprintf(
			'core:%1$s|gate:%2$s|plugins:%3$s|themes:%4$s|overrides:%5$s',
			(string) $core_state,
			$gate,
			! empty( $site['plugins_type_enabled'] ) ? 'on' : 'off',
			! empty( $site['themes_type_enabled'] ) ? 'on' : 'off',
			empty( $overridden ) ? 'none' : implode( ',', self::sort_override_types( $overridden ) )
		);

		return array_merge( array( '_site' => $site_line ), $map );
	}

	/**
	 * `overrides:` に並べる種別を、core・plugin・theme の固定の順にそろえる.
	 *
	 * @param array<int,string> $types 上書きが起きた種別.
	 * @return array<int,string>
	 */
	private static function sort_override_types( array $types ) {
		return array_values( array_intersect( array( 'core', 'plugin', 'theme' ), $types ) );
	}
}
