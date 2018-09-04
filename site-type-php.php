<?php

if ( ! defined ( 'SITE_PHP_TEMPLATE_ROOT' ) ) {
	define( 'SITE_PHP_TEMPLATE_ROOT', __DIR__ . '/templates' );
}

if ( ! class_exists( 'EE' ) ) {
	return;
}

$autoload = dirname( __FILE__ ) . '/vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
}

include_once __DIR__ . '/src/Site_PHP_Docker.php';
include_once __DIR__ . '/src/PHP.php';

Site_Command::add_site_type( 'php', 'EE\Site\Type\PHP' );
