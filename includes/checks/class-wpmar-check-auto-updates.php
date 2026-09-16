<?php
/**
 * Auto-update toggle-vs-effective-state facts for core, plugins, and themes.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects the `auto_updates` envelope for {@see WPMAR_Data_Collector}.
 *
 * This class exists to answer a question the rest of WPMAR cannot: "will this item's
 * auto-update actually run, or is the admin-screen toggle lying?" (see class docblock
 * history / plan for the `forced-auto-update-controller` real-world case this was built
 * to catch). Every design choice below traces back to the plan's numbered "facts"
 * (verified against WP core source, not memory) — see `~/.claude/plans/wp-checksum-verifier-dazzling-meteor.md`
 * for the full derivation. The headline ones, since they're easy to get wrong on a
 * later pass:
 *
 * - **`effective` is a limited concept.** WP_Automatic_Updater::should_update() also
 *   gates on `request_filesystem_credentials()` before it ever reaches the toggle/filter
 *   logic. We deliberately never call that (it has side effects: WP_Filesystem init,
 *   `request_filesystem_credentials` filter, hidden output). So `effective` here means
 *   "true per settings/filters/PHP(+MySQL for core) requirements", not "will definitely
 *   auto-update". `site.filesystem_credentials_ok` stays `null` (never evaluated) as a
 *   permanent reminder of that gap.
 * - **We never call `WP_Automatic_Updater::is_disabled()` or `wp_is_auto_update_enabled_for_type()`
 *   as-is.** Both internally re-run `wp_is_file_mod_allowed()` and the
 *   `automatic_updater_disabled` filter; calling both would fire those filters twice per
 *   request (WP filters are not guaranteed pure — see `resolve_site_gate()`). Instead we
 *   evaluate the four `is_disabled()` inputs once in `resolve_site_gate()` and derive
 *   `plugins_type_enabled` / `themes_type_enabled` from that same result, running only the
 *   `{$type}s_auto_update_enabled` filter on top (see `resolve_site_slice()`).
 * - **VCS checkout is tracked per target type** (core/plugin/theme), not as one flag.
 *   `WP_Automatic_Updater::is_vcs_checkout( $context )` always also checks ABSPATH, but
 *   `$context` differs per target (ABSPATH / WP_PLUGIN_DIR / get_theme_root()), so e.g. a
 *   `.git` under wp-content/plugins blocks plugin auto-updates without touching core.
 * - **Core's decision is delegated to `Core_Upgrader::should_update_to_version( $offered_ver )`**
 *   rather than re-derived from three independent dev/minor/major axes. Reading
 *   `class-core-upgrader.php` (`should_update_to_version()`) showed the three filters are
 *   NOT all evaluated independently — which one runs depends on whether the *currently
 *   installed* version is a dev build and whether the offered version is same-branch or a
 *   new branch. Re-deriving that branching ourselves would risk calling
 *   `allow_dev_auto_core_updates` when core never would, which is exactly the kind of
 *   3rd-party-filter-side-effect risk this class exists to avoid. `axes` therefore stays a
 *   *static* (filter-free) reading of options+constant, used only when there is no pending
 *   offer to ask core about directly.
 * - **Filter execution is isolated two ways, and only two.** `try/catch (Throwable)` covers
 *   exceptions; `ob_start()`/`ob_get_clean()` (in a `finally`, mirroring
 *   `Automatic_Upgrader_Skin::request_filesystem_credentials()`) covers stray output
 *   (`echo`, warnings/notices — `WP_DEBUG_DISPLAY` environments would otherwise leak this
 *   into WP-Cron's HTTP response or WP-CLI's stdout). Neither stops a 3rd-party filter from
 *   writing options, making HTTP calls, or calling `exit`/`die` — that trust boundary is
 *   inherent to running any WordPress filter, not specific to WPMAR.
 * - **No new transient/site_transient/caching layer.** The only added cost per run is a
 *   handful of filter calls plus already-cached `get_site_option()` reads; auto-update
 *   toggles are meant to take effect immediately, so caching them would trade a real (if
 *   small) cost for a "changed a setting, report is still stale" failure mode.
 *
 * ## Static (request-scoped) caching
 *
 * Only filesystem/constant-derived facts are cached in {@see self::$fs_gate_cache}:
 * `wp_is_file_mod_allowed()`, `wp_installing()`, the `AUTOMATIC_UPDATER_DISABLED` constant,
 * and the three `is_vcs_checkout()` probes. These don't change across `switch_to_blog()`
 * (shared filesystem, shared constants). Everything filter-derived —
 * `automatic_updater_disabled`, `{$type}s_auto_update_enabled`, each item's
 * `auto_update_{plugin,theme}` result — is re-evaluated every `collect()` call, because a
 * 3rd-party filter is free to branch on `get_current_blog_id()`.
 */
class WPMAR_Check_Auto_Updates {

	/**
	 * Filesystem/constant-only site-gate inputs, cached per request (see class docblock
	 * "Static (request-scoped) caching"). Deliberately excludes anything filter-derived.
	 *
	 * @var array<string,mixed>|null
	 */
	protected static $fs_gate_cache = null;

	/**
	 * Clears the static FS-gate cache. Needed by tests (each test should start clean) and
	 * safe to call across a `switch_to_blog()` boundary if a future multisite config ever
	 * makes per-site filesystem paths diverge (today wp-content, and therefore
	 * WP_PLUGIN_DIR/get_theme_root(), is shared network-wide, so this is precautionary).
	 *
	 * @return void
	 */
	public static function reset_site_gates_cache() {
		self::$fs_gate_cache = null;
	}

	/**
	 * Builds the `auto_updates` envelope for the dataset.
	 *
	 * @param array<string,mixed> $settings Plugin settings. Unused: no opt-out setting exists
	 *                                      for this check yet (see plan — HTTP-free and cheap,
	 *                                      so none was added). Kept in the signature to match
	 *                                      {@see WPMAR_Check_Security_Ops::collect()}.
	 * @param array<string,mixed> $dataset  In-progress dataset from {@see WPMAR_Data_Collector::gather()}.
	 *                                      Reads `plugins.inventory` / `themes.inventory`
	 *                                      (already built by the time this runs) so this class
	 *                                      never has to call `get_plugins()` / `wp_get_themes()`
	 *                                      itself. Defaults to empty so a caller without those
	 *                                      keys yields empty plugin/theme rows rather than a notice.
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
	// Site slice
	// -------------------------------------------------------------------------------

	/**
	 * Returns the filesystem/constant-only inputs to the site gate, from the static cache
	 * (see class docblock). Never touches anything filter-derived.
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
			// Per-type contexts (fact 6): a `.git` under WP_PLUGIN_DIR must not block core.
			// is_vcs_checkout() always also walks up from ABSPATH regardless of $context.
			'vcs_checkout'      => array(
				'core'   => $updater ? (bool) $updater->is_vcs_checkout( defined( 'ABSPATH' ) ? ABSPATH : '' ) : false,
				'plugin' => $updater ? (bool) $updater->is_vcs_checkout( defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '' ) : false,
				'theme'  => $updater ? (bool) $updater->is_vcs_checkout( function_exists( 'get_theme_root' ) ? get_theme_root() : '' ) : false,
			),
		);

		return self::$fs_gate_cache;
	}

	/**
	 * Builds the `site` slice: gate facts (re-derived from `is_disabled()`'s four inputs,
	 * never by calling `is_disabled()` itself — fact 5) plus per-type "is the admin toggle
	 * even consulted" facts.
	 *
	 * @return array<string,mixed>
	 */
	protected function resolve_site_slice() {
		$fs = $this->fs_gate_inputs();

		// automatic_updater_disabled is filter-derived (a 3rd party could branch on blog ID),
		// so unlike everything in $fs it is re-evaluated on every call, not cached.
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

		// wp_is_auto_update_enabled_for_type() is NOT called here: it internally re-runs
		// is_disabled() (wp-admin/includes/update.php), which would fire
		// wp_is_file_mod_allowed() and the automatic_updater_disabled filter a second time.
		// We already have $gate['updater_disabled'] from the single evaluation above, so we
		// only need to run the per-type filter on top of it.
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

		// Informational only (fact 2's known limitation): has_filter() is true even when a
		// registered callback returns the same value it was given, so this can be non-empty
		// while `overridden` stays false everywhere. Never used for state derivation.
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
			// Deliberately never evaluated — see class docblock "`effective` is a limited concept".
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
	// Plugins / themes
	// -------------------------------------------------------------------------------

	/**
	 * Collects per-item facts for either plugins or themes; the two share every rule in
	 * `should_update()` (fact 1), differing only in option/transient/filter names and which
	 * inventory field is the identity key (plugin basename vs. theme stylesheet).
	 *
	 * @param string              $type      'plugin' or 'theme'.
	 * @param array<int,array>    $inventory `$dataset['plugins']['inventory']` or `['themes']['inventory']`.
	 * @param array<string,mixed> $site      Output of {@see self::resolve_site_slice()}.
	 * @return array<string,array<string,mixed>> Keyed by identity (basename/stylesheet).
	 */
	protected function collect_items_for_type( $type, array $inventory, array $site ) {
		$is_plugin    = ( 'plugin' === $type );
		$option_key   = $is_plugin ? 'auto_update_plugins' : 'auto_update_themes';
		$hook         = $is_plugin ? 'auto_update_plugin' : 'auto_update_theme';
		$transient    = get_site_transient( $is_plugin ? 'update_plugins' : 'update_themes' );
		$type_enabled = $is_plugin ? $site['plugins_type_enabled'] : $site['themes_type_enabled'];
		$gate_blocked = $site['site_gate_blocked'][ $type ];

		// auto_update_themes can legitimately not exist as a site option (observed locally);
		// get_site_option()'s default covers plugins too, in case a similarly bare environment
		// never wrote auto_update_plugins either.
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
			// Neither bucket: this item isn't tracked by wp.org or any 3rd-party update
			// server (fact 8 — observed for 3 plugins in this environment). has_offer stays
			// false and no filter is invoked for it (see below).

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

			// Recorded only — fact 1: WP version requirements are not a `should_update()` gate.
			$wp_ok = true;
			if ( '' !== $requires_wp ) {
				$wp_current = isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '';
				$wp_ok      = ( '' === $wp_current ) || version_compare( $wp_current, $requires_wp, '>=' );
			}

			$filter_ran    = false;
			$filter_error  = false;
			$filter_result = null;

			if ( $has_offer ) {
				// Pre-filter value, mirroring should_update() up to (but not including) the
				// filter call (fact 1: disable_autoupdate applies before the filter, which
				// then has final say).
				$pre = $offer_autoupdate;
				if ( ! $pre && $type_enabled ) {
					$pre = $toggle;
				}
				if ( $disable_autoupdate ) {
					$pre = false;
				}

				// $item is only ever fabricated from a real offer, never for a no-offer item
				// (see "unknown patterns" note in the plan: a fake $item must not reach a
				// 3rd-party filter).
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
	// Core
	// -------------------------------------------------------------------------------

	/**
	 * Collects core facts. Unlike plugins/themes, the "is this item's auto-update on"
	 * question is delegated to `Core_Upgrader::should_update_to_version()` when there is a
	 * pending offer (see class docblock) rather than re-derived axis-by-axis.
	 *
	 * @param array<string,mixed> $site Output of {@see self::resolve_site_slice()}.
	 * @return array<string,mixed>
	 */
	protected function collect_core( array $site ) {
		$options = array(
			'dev'   => (string) get_site_option( 'auto_update_core_dev', 'enabled' ),
			'minor' => (string) get_site_option( 'auto_update_core_minor', 'enabled' ),
			'major' => (string) get_site_option( 'auto_update_core_major', 'unset' ),
		);

		$constant_defined = defined( 'WP_AUTO_UPDATE_CORE' );
		$constant_code    = self::core_constant_code( $constant_defined, $constant_defined ? WP_AUTO_UPDATE_CORE : null );

		// fact 7: only the `critical` flag is honored. The real failure_data structure also
		// has `current`/`attempted`/`retry` (a same-version-retry guard for *non*-critical
		// failures — see class-wp-automatic-updater.php / class-core-upgrader.php), which we
		// deliberately do not reproduce: it is a narrow, transient "don't retry this exact
		// A-to-B jump" rule that does not fit the on/off state model this class reports, and
		// re-deriving it would duplicate core's own bookkeeping for little report value.
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
					'critical_failure' => $critical_failure,
				),
			)
		);

		$evaluated['offer']     = $offer_out;
		$evaluated['effective'] = $has_offer ? ( $decision && $php_ok && $mysql_ok ) : null;

		return $evaluated;
	}

	/**
	 * Picks the first real upgrade/development offer from `get_core_updates()`, or null.
	 *
	 * Mirrors the response-code filtering in {@see WPMAR_Data_Collector::pending_core_upgrade_versions()}
	 * (a `latest`/empty response is a same-version reinstall prompt, not a pending upgrade —
	 * see wp-admin/update-core.php:list_core_update()), but returns the raw object rather
	 * than a version string since `should_update_to_version()` and the php/mysql requirement
	 * checks need the full offer.
	 *
	 * @param array<int,object>|false $raw_updates Return value of {@see get_core_updates()}.
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
	// Filter execution isolation
	// -------------------------------------------------------------------------------

	/**
	 * Runs `apply_filters()` isolated from exceptions and stray output (see class docblock
	 * "Filter execution is isolated two ways, and only two"). Output is logged by byte
	 * count only — the content itself is never surfaced (it is arbitrary 3rd-party output).
	 *
	 * @param string       $hook_name Hook name.
	 * @param mixed        $pre       Initial (pre-filter) value.
	 * @param array<mixed> $args      Extra args passed to apply_filters() after $pre.
	 * @param string       $log_key   Identifier for the WPMAR_Logger::step() entry.
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
			// finally (not just after the try) so a stray warning/echo is still captured
			// even when the callback throws — an unclosed buffer here would silently
			// swallow every subsequent output for the rest of the request.
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
	 * Logs a captured-output byte count (never the content — see class docblock). Split
	 * out from {@see self::guarded_filter()} / {@see self::guarded_core_decision()} purely
	 * so tests can override this one line without needing a real `WPMAR_Logger` (which
	 * pulls in `WPMAR_Private_Storage` / `WPMAR_Jobs_Repository` and writes to disk).
	 *
	 * @param string $log_key Identifier for the log entry.
	 * @param int    $bytes   Number of captured bytes.
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
	 * Runs `Core_Upgrader::should_update_to_version()` under the same isolation as
	 * {@see self::guarded_filter()} — it internally calls `apply_filters()` for the
	 * dev/minor/major gates, so the same 3rd-party trust boundary applies.
	 *
	 * @param string $offered_ver Offered core version.
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
	// Pure functions (no WP API calls; every branch below is independently unit-testable
	// without a WordPress bootstrap — see tests/CheckAutoUpdatesTest.php).
	// =================================================================================

	/**
	 * Normalizes a plugin/theme update-transient entry to a plain array.
	 *
	 * `update_plugins`' `response`/`no_update` entries are stdClass; `update_themes`' are
	 * plain arrays (WP casts them differently — see wp-includes/update.php). Both shapes
	 * are normalized to the same associative-array structure so the rest of this class
	 * never has to branch on the type.
	 *
	 * @param mixed  $entry Raw transient entry.
	 * @param string $type  'plugin' or 'theme'. Unused today (both shapes normalize
	 *                      identically) but kept in the signature in case a future WP
	 *                      version needs type-specific field handling.
	 * @return array<string,mixed>|null Null for anything that isn't a non-empty object/array.
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
	 * Whether `$key` is present in a `get_site_option( 'auto_update_{plugins,themes}', ... )`
	 * list.
	 *
	 * `auto_update_themes` has been observed absent as a site option entirely (returns
	 * `false`, not an empty array) in this environment, so the non-array case must be
	 * treated as "nothing toggled on" rather than triggering a PHP warning from
	 * `in_array()`.
	 *
	 * @param mixed  $raw_option Return value of `get_site_option()` for the toggle list.
	 * @param string $key        Plugin basename or theme stylesheet.
	 * @return bool
	 */
	public static function toggle_from_option( $raw_option, $key ) {
		$list = is_array( $raw_option ) ? $raw_option : array();

		return in_array( (string) $key, $list, true );
	}

	/**
	 * Extracts and sanitizes the update-source host from an update-offer `id` (e.g.
	 * `w.org/plugins/akismet`, `github.com/owner/repo`, or a 3rd-party update server URL).
	 *
	 * This is untrusted external input (any update server can set it) that flows into
	 * Markdown report bodies and snapshot diff lines. `sanitize_text_field()` alone is not
	 * enough here: it collapses newlines but does not remove `|` (which breaks GFM table
	 * rows in snapshot previews) and has no length limit. Order matters: the scheme must be
	 * stripped *before* splitting on '/', otherwise `https://evil.example/x` would yield
	 * `https` as the "host" once non-host characters are filtered out of `https:`.
	 *
	 * @param mixed $id Raw `id` field from an update offer.
	 * @return string Host-only, `[A-Za-z0-9.-]`, max 64 chars.
	 */
	public static function update_source_from_id( $id ) {
		$id = (string) $id;
		$id = preg_replace( '#^[a-z][a-z0-9+.\-]*://#i', '', $id );

		$head = explode( '/', (string) $id )[0];
		$head = preg_replace( '/[^A-Za-z0-9.\-]/', '', (string) $head );

		return substr( (string) $head, 0, 64 );
	}

	/**
	 * Sanitizes a version-like token (`new_version`, `requires_php`, `requires`) from an
	 * update offer before it reaches a report or snapshot. Same untrusted-input rationale
	 * as {@see self::update_source_from_id()}, restricted to characters valid in a version
	 * string.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_version_token( $value ) {
		$value = preg_replace( '/[^0-9A-Za-z.\-+]/', '', (string) $value );

		return substr( (string) $value, 0, 32 );
	}

	/**
	 * Derives the full fact layer + display `state` for one plugin or theme item.
	 *
	 * Reproduces `WP_Automatic_Updater::should_update()` (fact 1) up to the filter call,
	 * then applies the filter result exactly as core's `if ( ! $update )` does (a `null`
	 * filter return is `false`, not "no opinion" — fact 3), then layers the `state` label
	 * on top per the priority order below. `state` is a display label only; every input
	 * fact is preserved in the returned array for anything that needs the raw values
	 * (snapshot diff, extensibility filters, etc.).
	 *
	 * State priority (first match wins):
	 *   1. site_gate_blocked            -> disabled_site
	 *   2. ! has_offer                  -> no_update_source
	 *   3. decision && ! php_ok         -> blocked_php   (decision, not effective: effective
	 *                                      is already decision && php_ok, so gating on
	 *                                      effective here would make this branch unreachable)
	 *   4. overridden && decision       -> filter_forced_on
	 *   5. overridden && ! decision     -> filter_forced_off
	 *   6. decision                     -> on
	 *   7. ! type_enabled && ! offer_autoupdate && toggle -> type_disabled
	 *      (requires toggle=true: without it there is no admin-screen setting being
	 *      overridden by the site-wide type gate, so it is plain `off`)
	 *   8. else                         -> off
	 *
	 * Input facts read from `$raw` (all other keys are passed through untouched):
	 *   - key/slug/label            Identity (basename/stylesheet) and display label.
	 *   - toggle                    In the `auto_update_{plugins,themes}` list.
	 *   - offer_autoupdate          `$item->autoupdate` on the update offer.
	 *   - type_enabled              Site-wide "is the toggle even consulted" gate.
	 *   - disable_autoupdate        `$item->disable_autoupdate` on the update offer.
	 *   - filter_ran / filter_error Whether `auto_update_{plugin,theme}` was invoked / threw.
	 *   - filter_result             Raw filter return value (may be null).
	 *   - php_ok                    PHP requirement met (or no requirement).
	 *   - has_offer                 Present in either the `response` or `no_update` bucket.
	 *   - site_gate_blocked         This type's site-wide gate (fact 6, per-type).
	 *
	 * @param array<string,mixed> $raw Input facts (see list above).
	 * @return array<string,mixed> $raw merged with the derived fact layer and `state`.
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

		// Pre-filter value, mirroring should_update() through disable_autoupdate (fact 1).
		$pre_filter = $offer_autoupdate;
		if ( ! $pre_filter && $type_enabled ) {
			$pre_filter = $toggle;
		}
		if ( $disable_autoupdate ) {
			$pre_filter = false;
		}

		if ( $filter_error ) {
			// Only on a thrown exception do we fall back to the pre-filter value (see
			// "Filter execution is isolated" in the class docblock).
			$decision = $pre_filter;
		} else {
			// (bool) cast: a null filter return is false, same as core's `if ( ! $update )`
			// (fact 3) — do NOT fall back to $pre_filter here, that would invert the result.
			$decision = (bool) $filter_result;
		}

		// Known limitation (fact 2): if the filter returns the same value it was given,
		// this reads as "not overridden" even though a callback did run. site.filters_present
		// (has_filter()) carries that separately, for information only.
		$overridden = $filter_ran && ! $filter_error && ( $decision !== $pre_filter );

		// WP-version requirements are not a gate (fact 1); filesystem-credentials checks are
		// never evaluated (class docblock) — so this is deliberately narrower than "will
		// definitely auto-update".
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
	 * Derives the full fact layer + display `state` for core.
	 *
	 * Unlike {@see self::evaluate_item()}, the actual on/off decision for a pending offer is
	 * not re-derived here — it arrives pre-computed as `$raw['decision']`
	 * (`Core_Upgrader::should_update_to_version()`'s return value; see class docblock for
	 * why). This function's job is to turn that decision plus the offer's branch
	 * relationship into a display `state`, and — when there is no pending offer — fall back
	 * to a purely static (filter-free) reading of the dev/minor/major options and the
	 * `WP_AUTO_UPDATE_CORE` constant.
	 *
	 * State priority (first match wins):
	 *   1. site_gate_blocked                                  -> disabled_site
	 *   2. critical_failure                                   -> blocked_failure
	 *   3. has_offer && (! php_ok || ! mysql_ok)               -> blocked_requirements
	 *   4. has_offer && decision && branch_relation === major -> major   (overridden vs. axes.major)
	 *   5. has_offer && decision && branch_relation === same  -> minor   (overridden vs. axes.minor)
	 *   6. has_offer (decision false, or branch_relation none) -> disabled
	 *   7. ! has_offer && axes.major                           -> major
	 *   8. ! has_offer && axes.minor                            -> minor
	 *   9. ! has_offer && axes.dev                              -> dev_only
	 *  10. else                                                -> disabled
	 *
	 * Input facts read from `$raw`:
	 *   - site_gate_blocked      Core's site-wide gate (fact 6, per-type).
	 *   - has_offer              Whether `get_core_updates()` returned a pending upgrade.
	 *   - decision               `should_update_to_version()`'s result (only meaningful when has_offer).
	 *   - branch_relation        'major'|'same'|'none' — see {@see self::core_branch_relation()}.
	 *   - php_ok / mysql_ok      Requirement met (only meaningful when has_offer).
	 *   - raw.options            ['dev'=>'enabled'|'disabled', 'minor'=>..., 'major'=>'enabled'|'unset'].
	 *   - raw.constant           {@see self::core_constant_code()} output.
	 *   - raw.critical_failure   Whether the last auto-update attempt failed critically.
	 *
	 * @param array<string,mixed> $raw Input facts (see list above).
	 * @return array<string,mixed> $raw merged with `state`, `axes`, `overridden`, `source`.
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

		// Static (filter-free) axis reading: options, then the WP_AUTO_UPDATE_CORE constant
		// on top (constant wins — Core_Upgrader::should_update_to_version() @288-312). This
		// is what gets shown when there is no pending offer to ask core about directly.
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
			// Offer exists but should_update_to_version() said no (filter rejected it, or
			// branch_relation is 'none' — e.g. re-offering the currently installed version).
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
	 * Resolves the site-wide auto-update gate from `is_disabled()`'s four inputs
	 * (fact 5), evaluated exactly once — this function is never fed a second `is_disabled()`
	 * call, only its already-computed inputs — plus the per-type VCS-checkout gate (fact 6),
	 * which sits alongside `is_disabled()` in `should_update()` rather than inside it.
	 *
	 * Reason priority per type (first match wins): file_mod > installing > constant > filter > vcs > ''.
	 * When the `automatic_updater_disabled` filter is blocking and simply passed the
	 * constant's value through unchanged, the reason is reported as 'constant' (not 'filter')
	 * since the filter did not actually do anything.
	 *
	 * Input facts read from `$raw`:
	 *   - file_mod_allowed  wp_is_file_mod_allowed( 'automatic_updater' ).
	 *   - installing        wp_installing().
	 *   - constant_disabled AUTOMATIC_UPDATER_DISABLED.
	 *   - filter_disabled   apply_filters( 'automatic_updater_disabled', $constant_disabled ).
	 *   - vcs_checkout      ['core'=>bool,'plugin'=>bool,'theme'=>bool].
	 *
	 * @param array<string,mixed> $raw Input facts (see list above).
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
	 * Normalizes a WordPress version string to its `major.minor` branch, exactly as
	 * `Core_Upgrader::should_update_to_version()` does (`preg_split( '/[.-]/', $version )`
	 * then the first two segments) so branch comparisons here agree with core's.
	 *
	 * @param string $version E.g. '6.5.2', '7.2-alpha-12345', '7.2-RC1'.
	 * @return string E.g. '6.5', '7.2'. Empty string in, empty string out.
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
	 * Classifies a pending core offer's relationship to the currently installed version,
	 * mirroring the branch logic in `Core_Upgrader::should_update_to_version()` (fact —
	 * see class docblock on why the decision itself is delegated to that method rather than
	 * re-derived: this classification is only used to label *which* axis core's decision
	 * corresponds to, never to compute the decision).
	 *
	 * @param string $current_version Installed `$wp_version`.
	 * @param string $offered_version Offered version (`$update->current`).
	 * @param string $current_branch  {@see self::branch_of()} of `$current_version`.
	 * @param string $new_branch      {@see self::branch_of()} of `$offered_version`.
	 * @return string 'major' (new branch), 'same' (in-branch/minor), or 'none' (identical
	 *                version, or offered is not actually newer).
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
	 * Normalizes the `WP_AUTO_UPDATE_CORE` constant (fact 7) to one of four codes.
	 *
	 * @param bool  $defined Whether the constant is defined.
	 * @param mixed $value   The constant's value when defined (true|'beta'|'rc'|'development'|
	 *                       'branch-development'|'minor'|false).
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
}
