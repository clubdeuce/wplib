<?php

/**
 * Class WPLib_Commit_Reviser
 *
 * @since 0.10.0
 */
class WPLib_Commit_Reviser extends WPLib_Module_Base {

	const MISSING_COMMIT = '0000000';

	/**
	 *
	 */
	static function on_load() {

		self::add_class_action( 'wp_loaded' );

	}

	/**
	 * Inspect the LATEST_COMMIT for both WPLib and WPLib::app_class()
	 * and if changed call 'wplib_commit_revised' hook and update
	 * option in database.
	 */
	static function _wp_loaded() {

		foreach( array( 'WPLib', WPLib::app_class() ) as $class_name ) {

			$latest_commit = self::get_latest_commit( $class_name );

			if ( WPLib::is_development() ) {
				/**
				 * During development look at file LATEST_COMMIT
				 * that a git commit-hook will hopefully have added
				 */
				self::_maybe_update_class( $class_name );

				$loaded_commit = self::load_latest_commit( $class_name );

				if ( $loaded_commit !== $latest_commit ) {

					$latest_commit = $loaded_commit;

				}

			}

			$prefix = strtolower( $class_name );

			$previous_commit = get_option( $option_name = "{$prefix}_latest_commit" );

			if ( $latest_commit !== $previous_commit ) {

				do_action( 'wplib_commit_revised', $class_name, $latest_commit, $previous_commit );

				update_option( $option_name, $latest_commit );

			}

		}

	}

	/**
	 * Update the LATEST_COMMIT constant for WPLib or the App Class.
	 *
	 * The update does not affect the current value for LATEST_COMMIT until next page load.
	 *
	 * @param string $class_name
	 */
	private static function _maybe_update_class( $class_name ) {

		$latest_commit = self::get_latest_commit( $class_name, $defined );

		$not_exists = ! $defined || is_null( $latest_commit );

		$loaded_commit = self::load_latest_commit( $class_name );

		if ( $not_exists || ( ! is_null( $loaded_commit ) && $latest_commit !== $loaded_commit ) ) {

			$reflector = new ReflectionClass( $class_name );

			$source_file = $reflector->getFileName();

			$source_code = file_get_contents( $source_file );

			$source_size = strlen( $source_code );

			if ( preg_match( "#const\s+LATEST_COMMIT#", $source_code ) ) {

				$marker = "const\s+LATEST_COMMIT\s*=\s*'[^']*'\s*;\s*(//.*)?\s*\n";

				$replacer = "const LATEST_COMMIT = '{$loaded_commit}'; $1\n\n";

			} else {

				$marker = "class\s+{$class_name}\s+(extends\s+\w+)?\s*\{\s*\n";

				$replacer = "$0\tconst LATEST_COMMIT = '{$loaded_commit}';\n\n";

			}

			$new_code = preg_replace( "#{$marker}#", $replacer, $source_code );

			if ( $new_code && strlen( $new_code ) >= $source_size ) {

				file_put_contents( $source_file, $new_code );

			}

		}

	}

	/**
	 * @return null|string
	 */
	static function latest_commit() {

		return static::get_latest_commit( get_called_class() );

	}

	/**
	 * @param $class_name
	 * @param bool $defined
	 *
	 * @return mixed|null|string
	 */
	static function get_latest_commit( $class_name, &$defined = null ) {

		do {

			$latest_commit = $defined = null;

			if ( ! self::can_have_latest_commit( $class_name ) ) {
				break;
			}

			$const_ref = "{$class_name}::LATEST_COMMIT";

			if ( $defined = defined( $const_ref ) ) {

				$latest_commit = constant( $const_ref );
				break;

			}

		} while ( false );

		return substr( $latest_commit, 0, 7 );

	}

	/**
	 * Load 7 char abbreviated hash for commit from the system (file or exec).
	 *
	 * Look for a file LATEST_COMMIT if a Git post-commit hook exists and created it
	 * otherwise call Git using shell_exec().
	 *
	 * @param string $class_name
	 *
	 * @return null
	 */
	static function load_latest_commit( $class_name ) {

		$filepath = self::get_latest_commit_file( $class_name );

		$latest_commit = is_file( $filepath )
			? trim( file_get_contents( $filepath ) )
			: null;

		if ( is_null( $latest_commit ) && WPLib::is_development() ) {
			/**
			 * Call `git log` via exec()
			 */
			$root_dir = $class_name::root_dir();
			$command = "cd {$root_dir} && git log -1 --oneline && cd -";
			exec( $command, $output, $return_value );

			if ( 0 === $return_value && isset( $output[0] ) ) {
				/**
				 * If no git repo in dir, $return_value==127 and $output==array()
				 * If no git on system, $return_value==128 and $output==array()
				 * If good, first 7 chars of $output[0] has abbreviated hash for commit
				 */
				$latest_commit = substr( $output[0], 0, 7 );

			}

		}

		return $latest_commit;

	}

	/**
	 * @param string $class_name
	 *
	 * @return null
	 */
	static function get_latest_commit_file( $class_name ) {

		return self::can_have_latest_commit( $class_name )
			? $class_name::get_root_dir( 'LATEST_COMMIT' )
			: null;

	}

	/**
	 * @param string $class_name
	 *
	 * @return bool
	 */
	static function can_have_latest_commit( $class_name ) {

		return 'WPLib' === $class_name || is_subclass_of( $class_name, 'WPLib_App_Base' );

	}

}
WPLib_Commit_Reviser::on_load();
