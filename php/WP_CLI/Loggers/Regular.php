<?php

namespace WP_CLI\Loggers;

class Regular {

	private $colorize;

	function __construct( $colorize ) {
		$this->colorize = $colorize;
	}

	private function _line( $message, $handle = STDOUT ) {
		fwrite( $handle, \cli\Colors::colorize( $message . "\n", $this->colorize ) );
	}

	function info( $message ) {
		$this->_line( $message );
	}

	function warning( $message, $label ) {
		$msg = '%C' . $label . ': %n' . $message;
		$this->_line( $msg, STDERR );
	}

	function error( $message, $label ) {
		$msg = '%R' . $label . ': %n' . $message;
		$this->_line( $msg, STDERR );
	}
}

