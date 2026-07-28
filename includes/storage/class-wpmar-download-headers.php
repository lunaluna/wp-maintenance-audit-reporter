<?php
/**
 * Shared HTTP header helper for file-download responses.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralizes the header set every download endpoint must send, so `nosniff`
 * and no-cache behavior can't be forgotten on a new download path.
 */
class WPMAR_Download_Headers {

	/**
	 * Sends no-cache + content-type + nosniff + content-disposition headers.
	 *
	 * @param string    $content_type   MIME type, e.g. 'text/plain; charset=utf-8'.
	 * @param string    $filename       Filename offered to the browser (sanitized here).
	 * @param int|false $content_length Byte length, or false to omit Content-Length.
	 * @return void
	 */
	public static function send_attachment( $content_type, $filename, $content_length = false ) {
		nocache_headers();
		foreach ( self::attachment_headers( $content_type, $filename, $content_length ) as $header ) {
			header( $header );
		}
	}

	/**
	 * Builds the header lines for {@see self::send_attachment()} without sending them.
	 *
	 * Split out so tests can assert on the header set directly: PHP's CLI SAPI
	 * does not record `header()` calls in `headers_list()`, so the values must
	 * be verifiable independently of the actual `header()` dispatch.
	 *
	 * @param string    $content_type   MIME type, e.g. 'text/plain; charset=utf-8'.
	 * @param string    $filename       Filename offered to the browser (sanitized here).
	 * @param int|false $content_length Byte length, or false to omit Content-Length.
	 * @return array<int,string>
	 */
	public static function attachment_headers( $content_type, $filename, $content_length = false ) {
		$headers = array(
			'Content-Type: ' . $content_type,
			'X-Content-Type-Options: nosniff',
			'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"',
		);

		if ( false !== $content_length ) {
			$headers[] = 'Content-Length: ' . (string) $content_length;
		}

		return $headers;
	}
}
