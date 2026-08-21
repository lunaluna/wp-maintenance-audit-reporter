<?php
/**
 * Unit tests for WPMAR_Notifier_Mail::send_pair().
 *
 * Uses the controllable wp_mail() stub (tests/wp-stubs.php) to assert both
 * send content and send order/count, without a real mailer.
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
}

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/storage/class-wpmar-pdf-writer.php';
require_once dirname( __DIR__ ) . '/includes/notify/class-wpmar-notifier-mail.php';

/**
 * Covers WPMAR_Notifier_Mail::send_pair().
 */
final class NotifierMailTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_wpmar_test_options']                  = array( 'blogname' => 'テスト保守サイト' );
		$GLOBALS['_wpmar_test_bloginfo']                  = array();
		$GLOBALS['_wpmar_test_mail_calls']                = array();
		$GLOBALS['_wpmar_test_filters']                   = array();
		$GLOBALS['_wpmar_test_apply_filters_functional']  = false;
		unset( $GLOBALS['_wpmar_test_mail_throw'], $GLOBALS['_wpmar_test_mail_results'] );
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['_wpmar_test_options'],
			$GLOBALS['_wpmar_test_bloginfo'],
			$GLOBALS['_wpmar_test_mail_calls'],
			$GLOBALS['_wpmar_test_filters'],
			$GLOBALS['_wpmar_test_apply_filters_functional'],
			$GLOBALS['_wpmar_test_mail_throw'],
			$GLOBALS['_wpmar_test_mail_results']
		);
		parent::tearDown();
	}

	/**
	 * Builds a settings envelope with a minimal enabled `mail` block.
	 *
	 * Deliberately a single-level array_merge (not array_replace_recursive):
	 * array_replace_recursive() can't *clear* a key — replacing an already
	 * populated 'client_to' with an empty array via that function leaves the
	 * original list untouched, since there is nothing in the (empty) override
	 * to recurse into. array_merge() at this one level replaces each named
	 * key's value outright, empty arrays included, which is what every test
	 * below needs.
	 *
	 * @param array<string,mixed> $mail_overrides Merged onto the default `mail` sub-array.
	 * @return array<string,mixed>
	 */
	private function settings( array $mail_overrides = array() ) {
		return array(
			'mail' => array_merge(
				array(
					'enabled'      => true,
					'client_to'    => array( 'client@example.test' ),
					'admin_to'     => array( 'admin@example.test' ),
					'from_address' => '',
					'from_name'    => '',
				),
				$mail_overrides
			),
		);
	}

	public function test_disabled_mail_returns_false_and_never_calls_wp_mail(): void {
		$result = \WPMAR_Notifier_Mail::send_pair(
			$this->settings( array( 'enabled' => false ) ),
			'client body',
			'admin body'
		);

		self::assertFalse( $result );
		self::assertCount( 0, $GLOBALS['_wpmar_test_mail_calls'] );
	}

	public function test_sends_two_distinct_mails_with_unswapped_bodies_and_subjects(): void {
		\WPMAR_Notifier_Mail::send_pair( $this->settings(), 'client body content', 'admin body content' );

		$calls = $GLOBALS['_wpmar_test_mail_calls'];
		self::assertCount( 2, $calls );

		$client_call = null;
		$admin_call  = null;
		foreach ( $calls as $call ) {
			if ( array( 'client@example.test' ) === $call['to'] ) {
				$client_call = $call;
			} elseif ( array( 'admin@example.test' ) === $call['to'] ) {
				$admin_call = $call;
			}
		}

		self::assertNotNull( $client_call, 'Expected a mail addressed to the client.' );
		self::assertNotNull( $admin_call, 'Expected a mail addressed to the admin.' );

		self::assertStringContainsString( 'client body content', $client_call['message'] );
		self::assertStringContainsString( 'admin body content', $admin_call['message'] );
		self::assertStringNotContainsString( 'admin body content', $client_call['message'] );
		self::assertStringNotContainsString( 'client body content', $admin_call['message'] );

		self::assertStringNotContainsString( $client_call['subject'], $admin_call['subject'] );
	}

	public function test_admin_mail_is_plain_text_and_client_mail_is_html(): void {
		\WPMAR_Notifier_Mail::send_pair( $this->settings(), '# クライアント本文', '管理者本文' );

		$calls = $GLOBALS['_wpmar_test_mail_calls'];

		$admin_call  = null;
		$client_call = null;
		foreach ( $calls as $call ) {
			if ( array( 'admin@example.test' ) === $call['to'] ) {
				$admin_call = $call;
			} elseif ( array( 'client@example.test' ) === $call['to'] ) {
				$client_call = $call;
			}
		}

		self::assertNotNull( $admin_call );
		self::assertNotNull( $client_call );

		self::assertStringContainsString( 'text/plain', implode( ' ', (array) $admin_call['headers'] ) );
		self::assertStringContainsString( 'text/html', implode( ' ', (array) $client_call['headers'] ) );
		self::assertStringContainsString( '<h1>', $client_call['message'], 'Client body should be Markdown converted to HTML.' );
	}

	public function test_invalid_and_duplicate_addresses_are_filtered_out(): void {
		\WPMAR_Notifier_Mail::send_pair(
			$this->settings(
				array(
					'client_to' => array( 'client@example.test', 'client@example.test', 'not-an-email', '' ),
					'admin_to'  => array( 'admin@example.test' ),
				)
			),
			'client body',
			'admin body'
		);

		$client_call = null;
		foreach ( $GLOBALS['_wpmar_test_mail_calls'] as $call ) {
			if ( in_array( 'client@example.test', (array) $call['to'], true ) ) {
				$client_call = $call;
			}
		}

		self::assertNotNull( $client_call );
		self::assertSame( array( 'client@example.test' ), $client_call['to'] );
	}

	public function test_qa_override_replaces_both_channels_with_a_single_string_address(): void {
		\WPMAR_Notifier_Mail::send_pair( $this->settings(), 'client body', 'admin body', 'qa@example.test' );

		self::assertCount( 2, $GLOBALS['_wpmar_test_mail_calls'] );
		foreach ( $GLOBALS['_wpmar_test_mail_calls'] as $call ) {
			self::assertSame( array( 'qa@example.test' ), $call['to'] );
		}
	}

	public function test_qa_override_replaces_both_channels_with_an_array_of_addresses(): void {
		\WPMAR_Notifier_Mail::send_pair( $this->settings(), 'client body', 'admin body', array( 'qa@example.test' ) );

		self::assertCount( 2, $GLOBALS['_wpmar_test_mail_calls'] );
		foreach ( $GLOBALS['_wpmar_test_mail_calls'] as $call ) {
			self::assertSame( array( 'qa@example.test' ), $call['to'] );
		}
	}

	public function test_mail_qa_extra_adds_one_send_per_channel_when_distinct(): void {
		\WPMAR_Notifier_Mail::send_pair( $this->settings(), 'client body', 'admin body', array(), 'extra@example.test' );

		// 1 client + 1 client-extra + 1 admin + 1 admin-extra.
		self::assertCount( 4, $GLOBALS['_wpmar_test_mail_calls'] );

		// The mail_qa_extra batch is sent as a bare string $to (a single address
		// needs no array wrapping), unlike the configured client_to/admin_to
		// lists above, which arrive as arrays even with one entry.
		$extra_recipients = array_filter(
			$GLOBALS['_wpmar_test_mail_calls'],
			static function ( $call ) {
				return 'extra@example.test' === $call['to'];
			}
		);
		self::assertCount( 2, $extra_recipients, 'mail_qa_extra must receive both a client-style and an admin-style send.' );
	}

	public function test_mail_qa_extra_is_not_duplicated_when_already_configured_on_both_channels(): void {
		// Dedup is checked per channel (against filtered_client / filtered_admin
		// independently) — an address that is only a configured *client*
		// recipient would still get an extra *admin* send. Use an address
		// present on both channels to exercise full suppression.
		\WPMAR_Notifier_Mail::send_pair(
			$this->settings(
				array(
					'client_to' => array( 'client@example.test', 'shared@example.test' ),
					'admin_to'  => array( 'admin@example.test', 'shared@example.test' ),
				)
			),
			'client body',
			'admin body',
			array(),
			'shared@example.test'
		);

		// Only the normal client + admin sends — no extra duplicate on either channel.
		self::assertCount( 2, $GLOBALS['_wpmar_test_mail_calls'] );
	}

	public function test_invalid_mail_qa_extra_is_silently_ignored(): void {
		\WPMAR_Notifier_Mail::send_pair( $this->settings(), 'client body', 'admin body', array(), 'not-an-email' );

		self::assertCount( 2, $GLOBALS['_wpmar_test_mail_calls'] );
	}

	public function test_no_valid_recipients_and_no_qa_extra_returns_false_without_sending(): void {
		$result = \WPMAR_Notifier_Mail::send_pair(
			$this->settings(
				array(
					'client_to' => array(),
					'admin_to'  => array(),
				)
			),
			'client body',
			'admin body'
		);

		self::assertFalse( $result );
		self::assertCount( 0, $GLOBALS['_wpmar_test_mail_calls'] );
	}

	public function test_from_address_and_name_fall_back_to_admin_email_and_blogname(): void {
		$GLOBALS['_wpmar_test_bloginfo']['admin_email']  = 'siteadmin@example.test';
		$GLOBALS['_wpmar_test_apply_filters_functional'] = true;

		\WPMAR_Notifier_Mail::send_pair(
			$this->settings(
				array(
					'from_address' => '',
					'from_name'    => '',
				)
			),
			'client body',
			'admin body'
		);

		$from_address = apply_filters( 'wp_mail_from', 'unused@example.test' );
		$from_name    = apply_filters( 'wp_mail_from_name', 'Unused' );

		self::assertSame( 'siteadmin@example.test', $from_address );
		self::assertSame( 'テスト保守サイト', $from_name );
	}

	public function test_return_value_is_true_only_when_every_attempted_send_succeeds(): void {
		$GLOBALS['_wpmar_test_mail_results'] = array( true, true );
		$all_ok                              = \WPMAR_Notifier_Mail::send_pair( $this->settings(), 'client body', 'admin body' );
		self::assertTrue( $all_ok );

		$GLOBALS['_wpmar_test_mail_calls']    = array();
		$GLOBALS['_wpmar_test_mail_results']  = array( true, false );
		$one_failed                           = \WPMAR_Notifier_Mail::send_pair( $this->settings(), 'client body', 'admin body' );
		self::assertFalse( $one_failed );
	}

	/**
	 * Step 9 fix verification: send_pair()'s remove_filter()/remove_action()
	 * cleanup now runs in a finally block (outer: wp_mail_from /
	 * wp_mail_from_name / wp_mail_failed; inner, per client batch:
	 * phpmailer_init + the static AltBody buffer), so none of it survives an
	 * exception thrown by wp_mail() mid-send. This test used to pin down the
	 * pre-fix "residue remains" behaviour (see git history); it now asserts
	 * the opposite as visible proof the fix works.
	 */
	public function test_exception_mid_send_leaves_no_filter_residue(): void {
		$GLOBALS['_wpmar_test_mail_throw'] = true;

		try {
			\WPMAR_Notifier_Mail::send_pair( $this->settings(), 'client body', 'admin body' );
			self::fail( 'Expected wp_mail() to throw and that exception to propagate.' );
		} catch ( \RuntimeException $e ) {
			// Expected.
		}

		foreach ( array( 'wp_mail_from', 'wp_mail_from_name', 'wp_mail_failed', 'phpmailer_init' ) as $hook ) {
			self::assertFalse( has_filter( $hook ), "{$hook} must not remain registered after send_pair() throws." );
		}

		$plain_alt = new \ReflectionProperty( \WPMAR_Notifier_Mail::class, 'client_mail_plain_alt' );
		$plain_alt->setAccessible( true );
		self::assertNull( $plain_alt->getValue(), 'The static AltBody buffer must be reset to null even after an exception.' );
	}
}
