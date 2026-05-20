<?php
/**
 * Multisite helpers: blog lists, context switching, scheduling eligibility.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin façade around WordPress multisite APIs used by rollup runs.
 */
class WPMAR_Network {

	/**
	 * Main network site ID (storage anchor for rollup reports and cron).
	 *
	 * @return int
	 */
	public static function main_site_id() {
		if ( function_exists( 'get_main_site_id' ) ) {
			return (int) get_main_site_id();
		}

		return 1;
	}

	/**
	 * Whether cron/manual rollup should run in the current blog context.
	 *
	 * @return bool
	 */
	public static function should_run_network_scheduler_here() {
		if ( ! WPMAR_Network_Settings::is_network_audit_enabled() ) {
			return true;
		}

		return (int) get_current_blog_id() === self::main_site_id();
	}

	/**
	 * Whether per-site admin runs should be suppressed (rollup owns scheduling).
	 *
	 * @return bool
	 */
	public static function per_site_runs_disabled() {
		return WPMAR_Network_Settings::is_network_audit_enabled()
			&& (int) get_current_blog_id() !== self::main_site_id();
	}

	/**
	 * Resolves blog IDs included in a network rollup pass.
	 *
	 * @param array<string,mixed>|null $network_settings Optional preloaded network settings.
	 * @return array<int,int> Blog IDs keyed by themselves for fast isset checks.
	 */
	public static function target_blog_ids( $network_settings = null ) {
		if ( ! WPMAR_Network_Settings::is_multisite_available() ) {
			return array();
		}

		$settings = is_array( $network_settings ) ? $network_settings : WPMAR_Network_Settings::get_all();
		$sites    = isset( $settings['sites'] ) && is_array( $settings['sites'] ) ? $settings['sites'] : array();

		$args = array(
			'number'   => isset( $sites['max_sites'] ) ? absint( $sites['max_sites'] ) : 100,
			'archived' => ! empty( $sites['include_archived'] ) ? null : 0,
			'spam'     => ! empty( $sites['include_spam'] ) ? null : 0,
			'deleted'  => ! empty( $sites['include_deleted'] ) ? null : 0,
			'orderby'  => 'id',
			'order'    => 'ASC',
		);

		/**
		 * Filters {@see get_sites()} arguments before a network rollup audit enumerates blogs.
		 *
		 * @since 0.8.0
		 *
		 * @param array<string,mixed> $args     Query args.
		 * @param array<string,mixed> $settings Network settings envelope.
		 */
		$args = apply_filters( 'wpmar_network_get_sites_args', $args, $settings );

		$site_objects = get_sites( $args );
		$exclude      = array();
		if ( ! empty( $sites['exclude_blog_ids'] ) && is_array( $sites['exclude_blog_ids'] ) ) {
			foreach ( $sites['exclude_blog_ids'] as $id ) {
				$exclude[ absint( $id ) ] = true;
			}
		}

		$blog_ids = array();
		foreach ( $site_objects as $site ) {
			if ( ! is_object( $site ) || ! isset( $site->blog_id ) ) {
				continue;
			}
			$blog_id = absint( $site->blog_id );
			if ( $blog_id <= 0 || isset( $exclude[ $blog_id ] ) ) {
				continue;
			}

			/**
			 * Skip or include a blog in the rollup enumeration.
			 *
			 * @since 0.8.0
			 *
			 * @param bool $include  Default true when not excluded by settings.
			 * @param int  $blog_id  Candidate blog ID.
			 * @param object $site   Site row from {@see get_sites()}.
			 */
			if ( ! apply_filters( 'wpmar_network_include_blog', true, $blog_id, $site ) ) {
				continue;
			}

			$blog_ids[ $blog_id ] = $blog_id;
		}

		/**
		 * Filters the final blog ID list for a network rollup run.
		 *
		 * @since 0.8.0
		 *
		 * @param array<int,int>      $blog_ids Blog IDs.
		 * @param array<string,mixed> $settings Network settings envelope.
		 */
		$blog_ids = apply_filters( 'wpmar_network_target_blog_ids', $blog_ids, $settings );

		return array_values( array_map( 'absint', $blog_ids ) );
	}

	/**
	 * Executes a callback on the main site, restoring the prior blog afterward.
	 *
	 * @param callable():mixed $callback Callback receiving no args.
	 * @return mixed
	 */
	public static function on_main_site( callable $callback ) {
		$previous = get_current_blog_id();
		$main_id  = self::main_site_id();
		$switched = false;

		if ( $previous !== $main_id ) {
			switch_to_blog( $main_id );
			$switched = true;
		}

		try {
			return $callback();
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Executes a callback on a specific blog.
	 *
	 * @param int              $blog_id  Target blog.
	 * @param callable():mixed $callback Callback.
	 * @return mixed
	 */
	public static function on_blog( $blog_id, callable $callback ) {
		$blog_id  = absint( $blog_id );
		$previous = get_current_blog_id();
		$switched = false;

		if ( $blog_id > 0 && $previous !== $blog_id ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}

		try {
			return $callback();
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}
}
