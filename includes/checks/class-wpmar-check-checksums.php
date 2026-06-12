<?php
/**
 * Core and plugin file checksum verification against wordpress.org manifests.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches official checksum manifests and compares on-disk MD5 hashes.
 */
class WPMAR_Check_Checksums {

	/**
	 * HTTP timeout for manifest requests.
	 */
	const HTTP_TIMEOUT = 30;

	/**
	 * Builds checksum audit payloads for the report dataset.
	 *
	 * @param array<string,mixed> $settings Merged {@see WPMAR_Settings::get_all()}.
	 * @param array<string,mixed> $dataset  Output of {@see WPMAR_Data_Collector::gather()}.
	 * @return array<string,mixed>
	 */
	public function collect( array $settings, array $dataset ) {
		$core_excludes = isset( $settings['checksums']['core_exclude_paths'] ) && is_array( $settings['checksums']['core_exclude_paths'] )
			? $settings['checksums']['core_exclude_paths']
			: array();

		$plugin_rules = isset( $settings['checksums']['plugin_exclude_rules'] ) && is_array( $settings['checksums']['plugin_exclude_rules'] )
			? $settings['checksums']['plugin_exclude_rules']
			: array();

		$core_version = isset( $dataset['core']['version'] ) ? sanitize_text_field( (string) $dataset['core']['version'] ) : '';
		$locale       = isset( $dataset['core']['locale'] ) ? sanitize_text_field( (string) $dataset['core']['locale'] ) : get_locale();

		return array(
			'core'    => $this->verify_core( $core_version, $locale, $core_excludes ),
			'plugins' => $this->verify_plugins( isset( $dataset['plugins']['inventory'] ) ? $dataset['plugins']['inventory'] : array(), $plugin_rules ),
		);
	}

	/**
	 * Verifies WordPress core files via api.wordpress.org checksums endpoint.
	 *
	 * @param string   $version   Installed core version.
	 * @param string   $locale    Locale slug (e.g. en_US, ja).
	 * @param string[] $excludes  Paths relative to ABSPATH to skip.
	 * @return array<string,mixed>
	 */
	protected function verify_core( $version, $locale, array $excludes ) {
		$out = array(
			'version'       => $version,
			'locale'        => $locale,
			'ok'            => true,
			'manifest_ok'   => false,
			'checked_files' => 0,
			'mismatches'    => array(),
			'skipped_files' => 0,
			'error'         => '',
		);

		if ( '' === $version ) {
			$out['ok']          = false;
			$out['error']       = __( 'コアバージョンが取得できませんでした。', 'wp-maintenance-audit-reporter' );
			$out['manifest_ok'] = false;

			return $out;
		}

		$requested_locale = $locale;
		$primary_url      = $this->core_checksums_api_url( $version, $requested_locale );
		$body             = $this->http_get_body( $primary_url );

		if ( null === $body ) {
			$out['ok']          = false;
			$out['error']       = __( 'コアのチェックサム API に接続できませんでした。', 'wp-maintenance-audit-reporter' );
			$out['manifest_ok'] = false;

			return $out;
		}

		$json             = json_decode( $body, true );
		$effective_locale = $requested_locale;

		if ( ! $this->core_checksum_manifest_is_valid( $json ) && ! $this->is_en_us_locale_slug( $requested_locale ) ) {
			$fallback_url = $this->core_checksums_api_url( $version, 'en_US' );
			$body_us      = $this->http_get_body( $fallback_url );
			if ( null !== $body_us ) {
				$json             = json_decode( $body_us, true );
				$effective_locale = 'en_US';
			}
		}

		if ( ! $this->core_checksum_manifest_is_valid( $json ) ) {
			$out['ok']          = false;
			$out['error']       = __( 'コアのチェックサム一覧を解釈できませんでした。', 'wp-maintenance-audit-reporter' );
			$out['manifest_ok'] = false;

			return $out;
		}

		$out['manifest_ok'] = true;
		$exclude_set        = $this->normalize_path_set( $excludes );

		foreach ( $json['checksums'] as $rel_path => $expected_md5 ) {
			if ( ! is_scalar( $rel_path ) || ! is_string( $expected_md5 ) ) {
				continue;
			}

			$key = $this->normalize_rel_path( (string) $rel_path );

			// wp core verify-checksums と同様に wp-content/ 以下はコア検証の対象外とする.
			if ( 0 === strpos( $key, 'wp-content/' ) ) {
				++$out['skipped_files'];
				continue;
			}

			if ( isset( $exclude_set[ $key ] ) ) {
				++$out['skipped_files'];
				continue;
			}

			$expected = strtolower( $expected_md5 );
			++$out['checked_files'];

			$abs = $this->absolute_core_path( (string) $rel_path );
			if ( ! file_exists( $abs ) || ! is_readable( $abs ) ) {
				$out['ok']           = false;
				$out['mismatches'][] = array(
					'file'   => $key,
					'reason' => 'missing_or_unreadable',
				);
				continue;
			}

			$actual = md5_file( $abs );
			if ( false === $actual ) {
				$out['ok']           = false;
				$out['mismatches'][] = array(
					'file'   => $key,
					'reason' => 'md5_failed',
				);
				continue;
			}

			if ( strtolower( $actual ) !== $expected ) {
				$out['ok']           = false;
				$out['mismatches'][] = array(
					'file'   => $key,
					'reason' => 'hash_mismatch',
				);
			}
		}

		$out['manifest_locale']          = $effective_locale;
		$out['manifest_locale_fallback'] = $this->locales_differ_for_checksums( $requested_locale, $effective_locale );

		/*
		 * Non-en locales often have no dedicated checksum manifest; we fall back to en_US.
		 * The installed pack then differs from that manifest in exactly one localized file — expected, not tampering.
		 */
		if ( ! empty( $out['manifest_locale_fallback'] ) && is_array( $out['mismatches'] ) && 1 === count( $out['mismatches'] ) ) {
			$out['mismatches'] = array();
			$out['ok']         = true;
		}

		return $out;
	}

	/**
	 * Builds the core checksum API URL.
	 *
	 * @param string $version WordPress version string.
	 * @param string $locale  Locale slug for the manifests package.
	 * @return string
	 */
	protected function core_checksums_api_url( $version, $locale ) {
		return sprintf(
			'https://api.wordpress.org/core/checksums/1.0/?version=%s&locale=%s',
			rawurlencode( (string) $version ),
			rawurlencode( (string) $locale )
		);
	}

	/**
	 * Whether the decoded API payload includes a usable checksum map.
	 *
	 * @param mixed $json json_decode() output.
	 * @return bool
	 */
	protected function core_checksum_manifest_is_valid( $json ) {
		if ( ! is_array( $json ) || ! isset( $json['checksums'] ) ) {
			return false;
		}

		$checksums = $json['checksums'];

		return is_array( $checksums ) && ! empty( $checksums );
	}

	/**
	 * True when the slug refers to the default English (US) packs.
	 *
	 * @param string $locale Raw locale from the site.
	 * @return bool
	 */
	protected function is_en_us_locale_slug( $locale ) {
		$slug = strtolower( str_replace( '-', '_', (string) $locale ) );

		return ( 'en_us' === $slug || 'en' === $slug );
	}

	/**
	 * Compares two locale slugs after light normalisation.
	 *
	 * @param string $a First locale.
	 * @param string $b Second locale (often en_US after fallback).
	 * @return bool True when they should be treated as different for UX flags.
	 */
	protected function locales_differ_for_checksums( $a, $b ) {
		return strtolower( str_replace( '-', '_', (string) $a ) )
			!== strtolower( str_replace( '-', '_', (string) $b ) );
	}

	/**
	 * Verifies each .org-listed plugin when a checksum manifest exists.
	 *
	 * @param array<int,array<string,mixed>>  $inventory Plugin rows from gather().
	 * @param array<string,array<int,string>> $exclude_rules Map slug => relative paths.
	 * @return array<string,array<string,mixed>>
	 */
	protected function verify_plugins( array $inventory, array $exclude_rules ) {
		$results = array();

		foreach ( $inventory as $row ) {
			if ( ! isset( $row['slug'], $row['version'], $row['basename'] ) ) {
				continue;
			}

			$slug    = sanitize_key( (string) $row['slug'] );
			$version = sanitize_text_field( (string) $row['version'] );
			$base    = sanitize_text_field( (string) $row['basename'] );

			if ( '' === $slug || '' === $version || '' === $base ) {
				continue;
			}

			$plugin_root = $this->plugin_root_path( $base );
			if ( '' === $plugin_root || ! is_dir( $plugin_root ) ) {
				$results[ $slug ] = array(
					'version'        => $version,
					'status'         => 'error',
					'checked_files'  => 0,
					'mismatches'     => array(),
					'skipped_files'  => 0,
					'error'          => __( 'プラグインディレクトリを特定できませんでした。', 'wp-maintenance-audit-reporter' ),
					'manifest_found' => false,
				);

				continue;
			}

			$url  = sprintf( 'https://downloads.wordpress.org/plugin-checksums/%s/%s.json', rawurlencode( $slug ), rawurlencode( $version ) );
			$body = $this->http_get_body( $url );

			if ( null === $body ) {
				$results[ $slug ] = array(
					'version'        => $version,
					'status'         => 'no_checksums',
					'checked_files'  => 0,
					'mismatches'     => array(),
					'skipped_files'  => 0,
					'error'          => '',
					'manifest_found' => false,
				);

				continue;
			}

			$file_map = $this->parse_plugin_checksum_json( $body );
			if ( null === $file_map || empty( $file_map ) ) {
				$results[ $slug ] = array(
					'version'        => $version,
					'status'         => 'no_checksums',
					'checked_files'  => 0,
					'mismatches'     => array(),
					'skipped_files'  => 0,
					'error'          => __( 'プラグイン用チェックサム一覧を解釈できませんでした。', 'wp-maintenance-audit-reporter' ),
					'manifest_found' => true,
				);

				continue;
			}

			$slug_excludes = isset( $exclude_rules[ $slug ] ) && is_array( $exclude_rules[ $slug ] )
				? $exclude_rules[ $slug ]
				: array();
			$ex_set        = $this->normalize_path_set( $slug_excludes );

			$chunk = array(
				'version'        => $version,
				'status'         => 'ok',
				'checked_files'  => 0,
				'mismatches'     => array(),
				'skipped_files'  => 0,
				'error'          => '',
				'manifest_found' => true,
			);

			foreach ( $file_map as $rel => $expected_md5 ) {
				if ( ! is_scalar( $rel ) || ! is_string( $expected_md5 ) ) {
					continue;
				}

				$key = $this->normalize_rel_path( (string) $rel );
				if ( isset( $ex_set[ $key ] ) ) {
					++$chunk['skipped_files'];
					continue;
				}

				$expected = strtolower( trim( $expected_md5 ) );
				++$chunk['checked_files'];

				$abs = wp_normalize_path( trailingslashit( $plugin_root ) . str_replace( '/', DIRECTORY_SEPARATOR, $rel ) );
				if ( ! file_exists( $abs ) || ! is_readable( $abs ) ) {
					$chunk['status']       = 'mismatch';
					$chunk['mismatches'][] = array(
						'file'   => $key,
						'reason' => 'missing_or_unreadable',
					);
					continue;
				}

				$actual = md5_file( $abs );
				if ( false === $actual ) {
					$chunk['status']       = 'mismatch';
					$chunk['mismatches'][] = array(
						'file'   => $key,
						'reason' => 'md5_failed',
					);
					continue;
				}

				if ( strtolower( $actual ) !== $expected ) {
					$chunk['status']       = 'mismatch';
					$chunk['mismatches'][] = array(
						'file'   => $key,
						'reason' => 'hash_mismatch',
					);
				}
			}

			$results[ $slug ] = $chunk;
		}

		return $results;
	}

	/**
	 * Resolves the filesystem root directory for a plugin basename.
	 *
	 * @param string $basename Plugin basename relative to WP_PLUGIN_DIR.
	 * @return string Absolute normalized path or empty string.
	 */
	protected function plugin_root_path( $basename ) {
		$full = trailingslashit( WP_PLUGIN_DIR ) . $basename;
		$dir  = dirname( $full );

		if ( ! is_string( $dir ) || '' === $dir ) {
			return '';
		}

		return wp_normalize_path( $dir );
	}

	/**
	 * Joins ABSPATH with a manifest-relative path.
	 *
	 * @param string $rel_path Path key from the checksum API.
	 * @return string
	 */
	protected function absolute_core_path( $rel_path ) {
		$clean = str_replace( '\\', '/', (string) $rel_path );
		$clean = ltrim( $clean, '/' );

		if ( '' === $clean || false !== strpos( $clean, '..' ) ) {
			return '';
		}

		return wp_normalize_path( ABSPATH . $clean );
	}

	/**
	 * Parses plugin checksum JSON into a flat relative path => md5 map.
	 *
	 * @param string $body Raw JSON.
	 * @return array<string,string>|null
	 */
	protected function parse_plugin_checksum_json( $body ) {
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return null;
		}

		if ( isset( $data['files'] ) && is_array( $data['files'] ) ) {
			$out = array();
			foreach ( $data['files'] as $rel => $meta ) {
				if ( ! is_scalar( $rel ) ) {
					continue;
				}

				$rel_key = (string) $rel;
				$hash    = $this->extract_plugin_checksum_hash_string( $meta );

				if ( '' === $hash ) {
					continue;
				}

				$out[ $rel_key ] = $hash;
			}

			return $out;
		}

		if ( isset( $data['checksums'] ) && is_array( $data['checksums'] ) ) {
			$flat = array();
			foreach ( $data['checksums'] as $key => $hash ) {
				if ( ! is_scalar( $key ) || ! is_string( $hash ) ) {
					continue;
				}

				$flat[ (string) $key ] = strtolower( trim( $hash ) );
			}

			return $flat;
		}

		return null;
	}

	/**
	 * Normalises a plugin manifest file entry to a lowercase MD5 string.
	 *
	 * @param mixed $meta String hash or array containing an `md5` string.
	 * @return string Empty string when no usable hash is present.
	 */
	protected function extract_plugin_checksum_hash_string( $meta ) {
		if ( is_string( $meta ) ) {
			return strtolower( trim( $meta ) );
		}

		if ( is_array( $meta ) && isset( $meta['md5'] ) && is_string( $meta['md5'] ) ) {
			return strtolower( trim( $meta['md5'] ) );
		}

		return '';
	}

	/**
	 * GET helper with shared timeout + success guard.
	 *
	 * @param string $url Remote URL.
	 * @return string|null Body or null on failure.
	 */
	protected function http_get_body( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => self::HTTP_TIMEOUT,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );

		return is_string( $body ) ? $body : null;
	}

	/**
	 * Normalizes a relative path for comparisons / exclude lookups.
	 *
	 * @param string $path Raw relative fragment.
	 * @return string
	 */
	protected function normalize_rel_path( $path ) {
		$norm = wp_normalize_path( (string) $path );
		$norm = ltrim( $norm, '/' );

		return strtolower( $norm );
	}

	/**
	 * Converts a list of paths into a lookup map.
	 *
	 * @param string[] $paths Relative paths.
	 * @return array<string,bool>
	 */
	protected function normalize_path_set( array $paths ) {
		$set = array();
		foreach ( $paths as $path ) {
			$key = $this->normalize_rel_path( (string) $path );
			if ( '' !== $key ) {
				$set[ $key ] = true;
			}
		}

		return $set;
	}
}
