<?php
/**
 * PHPUnit bootstrap for local development (`composer install` required).
 *
 * @package WPMAR
 */

$autoload = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( is_readable( $autoload ) ) {
	require_once $autoload;
}
