<?php

use \WP_CLI\Utils;
use \WP_CLI\Dispatcher;

/**
 * Various utilities for WP-CLI commands.
 */
class WP_CLI {

	public static $configurator;
	public static $root;
	public static $runner;

	private static $logger;

	private static $man_dirs = array();

	/**
	 * Initialize WP_CLI static variables.
	 */
	static function init() {
		self::add_man_dir(
			WP_CLI_ROOT . "../man",
			WP_CLI_ROOT . "../man-src"
		);

		self::$configurator = new WP_CLI\Configurator( WP_CLI_ROOT . '/config-spec.php' );
		self::$root = new Dispatcher\RootCommand;
		self::$runner = new WP_CLI\Runner;
	}

	/**
	 * Set the logger instance.
	 *
	 * @param object $logger
	 */
	static function set_logger( $logger ) {
		self::$logger = $logger;
	}

	/**
	 * Add a command to the WP-CLI list of commands
	 *
	 * @param string $name The name of the command that will be used in the cli
	 * @param string $class The command implementation
	 */
	static function add_command( $name, $class ) {
		if ( in_array( $name, self::get_config('disabled_commands') ) )
			return;

		$reflection = new \ReflectionClass( $class );

		if ( $reflection->hasMethod( '__invoke' ) ) {
			$command = self::create_subcommand( self::$root, $name, $reflection->name,
				$reflection->getMethod( '__invoke' ) );
		} else {
			$command = self::create_composite_command( $name, $reflection );
		}

		self::$root->add_subcommand( $name, $command );
	}

	private static function create_subcommand( $parent, $name, $class_name, $method ) {
		$docparser = new \WP_CLI\DocParser( $method );

		if ( !$name )
			$name = $docparser->get_tag( 'subcommand' );

		if ( !$name )
			$name = $method->name;

		$method_name = $method->name;

		$when_invoked = function ( $args, $assoc_args ) use ( $class_name, $method_name ) {
			call_user_func( array( new $class_name, $method_name ), $args, $assoc_args );
		};

		return new Dispatcher\Subcommand( $parent, $name, $docparser, $when_invoked );
	}

	private static function create_composite_command( $name, $reflection ) {
		$docparser = new \WP_CLI\DocParser( $reflection );

		$container = new Dispatcher\CompositeCommand( self::$root, $name, $docparser );

		foreach ( $reflection->getMethods() as $method ) {
			if ( !self::_is_good_method( $method ) )
				continue;

			$subcommand = self::create_subcommand( $container, false, $reflection->name, $method );

			$subcommand_name = $subcommand->get_name();
			$full_name = self::get_full_name( $subcommand );

			if ( in_array( $full_name, self::get_config('disabled_commands') ) )
				continue;

			$container->add_subcommand( $subcommand_name, $subcommand );
		}

		return $container;
	}

	private static function get_full_name( $command ) {
		$path = Dispatcher\get_path( $command );
		array_shift( $path );

		return implode( ' ', $path );
	}

	private static function _is_good_method( $method ) {
		return $method->isPublic() && !$method->isConstructor() && !$method->isStatic();
	}

	static function add_man_dir( $dest_dir, $src_dir ) {
		self::$man_dirs[ $dest_dir ] = $src_dir;
	}

	static function get_man_dirs() {
		return self::$man_dirs;
	}

	/**
	 * Display a message in the CLI and end with a newline
	 *
	 * @param string $message
	 */
	static function line( $message = '' ) {
		self::$logger->line( $message );
	}

	/**
	 * Display a success in the CLI and end with a newline
	 *
	 * @param string $message
	 * @param string $label
	 */
	static function success( $message, $label = 'Success' ) {
		self::$logger->success( $message, $label );
	}

	/**
	 * Display a warning in the CLI and end with a newline
	 *
	 * @param string $message
	 * @param string $label
	 */
	static function warning( $message, $label = 'Warning' ) {
		self::$logger->warning( self::error_to_string( $message ), $label );
	}

	/**
	 * Display an error in the CLI and end with a newline
	 *
	 * @param string $message
	 * @param string $label
	 */
	static function error( $message, $label = 'Error' ) {
		if ( ! isset( self::$runner->assoc_args[ 'completions' ] ) ) {
			self::$logger->error( self::error_to_string( $message ), $label );
		}

		exit(1);
	}

	/**
	 * Ask for confirmation before running a destructive operation.
	 */
	static function confirm( $question, $assoc_args ) {
		if ( !isset( $assoc_args['yes'] ) ) {
			echo $question . " [y/n] ";

			$answer = trim( fgets( STDIN ) );

			if ( 'y' != $answer )
				exit;
		}
	}

	/**
	 * Read a value, from various formats
	 *
	 * @param mixed $value
	 * @param array $assoc_args
	 */
	static function read_value( $value, $assoc_args = array() ) {
		if ( isset( $assoc_args['format'] ) && 'json' == $assoc_args['format'] ) {
			$value = json_decode( $value, true );
		}

		return $value;
	}

	/**
	 * Display a value, in various formats
	 *
	 * @param mixed $value
	 * @param array $assoc_args
	 */
	static function print_value( $value, $assoc_args = array() ) {
		if ( isset( $assoc_args['format'] ) && 'json' == $assoc_args['format'] ) {
			$value = json_encode( $value );
		} elseif ( is_array( $value ) || is_object( $value ) ) {
			$value = var_export( $value );
		}

		echo $value . "\n";
	}

	/**
	 * Convert a wp_error into a string
	 *
	 * @param mixed $errors
	 * @return string
	 */
	static function error_to_string( $errors ) {
		if ( is_string( $errors ) ) {
			return $errors;
		}

		if ( is_object( $errors ) && is_a( $errors, 'WP_Error' ) ) {
			foreach ( $errors->get_error_messages() as $message ) {
				if ( $errors->get_error_data() )
					return $message . ' ' . $errors->get_error_data();
				else
					return $message;
			}
		}
	}

	/**
	 * Launch an external process that takes over I/O.
	 *
	 * @param string Command to call
	 * @param bool Whether to exit if the command returns an error status
	 *
	 * @return int The command exit status
	 */
	static function launch( $command, $exit_on_error = true ) {
		$r = proc_close( proc_open( $command, array( STDIN, STDOUT, STDERR ), $pipes ) );

		if ( $r && $exit_on_error )
			exit($r);

		return $r;
	}

	static function get_config_path() {
		return self::$runner->config_path;
	}

	static function get_config( $key = null ) {
		if ( null === $key )
			return self::$runner->config;

		if ( !isset( self::$runner->config[ $key ] ) ) {
			self::warning( "Unknown config option '$key'." );
			return null;
		}

		return self::$runner->config[ $key ];
	}

	private static function find_command_to_run( $args ) {
		$command = \WP_CLI::$root;

		$cmd_path = array();

		while ( !empty( $args ) && $command->has_subcommands() ) {
			$cmd_path[] = $args[0];

			$subcommand = $command->find_subcommand( $args );

			if ( !$subcommand ) {
				\WP_CLI::error( sprintf( "'%s' is not a registered wp command. See 'wp help'.",
					implode( ' ', $cmd_path ) ) );
			}

			$command = $subcommand;
		}

		return array( $command, $args );
	}

	/**
	 * Run a given command.
	 *
	 * @param array
	 * @param array
	 */
	public static function run_command( $args, $assoc_args = array() ) {
		list( $command, $final_args ) = self::find_command_to_run( $args );

		$command->invoke( $final_args, $assoc_args );
	}

	// back-compat
	static function out( $str ) {
		echo $str;
	}

	// back-compat
	static function addCommand( $name, $class ) {
		trigger_error( sprintf( 'wp %s: %s is deprecated. use WP_CLI::add_command() instead.',
			$name, __FUNCTION__ ), E_USER_WARNING );
		self::add_command( $name, $class );
	}
}

