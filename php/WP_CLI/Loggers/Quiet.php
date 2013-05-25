<?php

namespace WP_CLI\Loggers;

class Quiet {

	private $colorize;

	function __construct( $colorize ) {
		$this->colorize = $colorize;
	}

	function info( $message ) {
		// nothing
	}

	function warning( $message, $label ) {
		// nothing
	}

	function error( $message, $label ) {
		$msg = '%R' . $label . ': %n' . $message;
		fwrite( STDERR, \cli\Colors::colorize( $msg . "\n", $this->colorize ) );
	}
}

