<?php
/**
 * WP CLI Themecheck Command.
 *
 * @author    anhskohbo <anhskohbo@gmail.com>
 * @license   MIT
 * @link      https://github.com/anhskohbo/wp-cli-themecheck
 */

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

use WP_CLI\Utils as Utils;
use Symfony\Component\Finder\Finder;

if ( ! class_exists( 'WP_CLI_Themecheck_Command' ) ) :

	/**
	 * Themecheck_Command class.
	 */
	class WP_CLI_Themecheck_Command extends WP_CLI_Command {

		/**
		 * Run themecheck in CLI.
		 *
		 * ## OPTIONS
		 *
		 * [--theme=<theme-name>]
		 * : Theme name to check.
		 *
		 * [--skip-info]
		 * : Suppress INFO.
		 *
		 * [--skip-recommended]
		 * : Suppress RECOMMENDED.
		 *
		 * [--interactive]
		 * : Prompt user for input (default).
		 *
		 * ## EXAMPLES
		 *
		 *     $ wp themecheck --theme="twentysixteen"
		 *
		 * @when after_wp_load
		 */
		public function __invoke( $args, $assoc_args ) {

			$interactive = (bool) Utils\get_flag_value( $assoc_args, 'interactive', true );
			$this->before_themecheck( $interactive );

			require_once WP_PLUGIN_DIR . '/theme-check/checkbase.php';
			require_once WP_PLUGIN_DIR . '/theme-check/main.php';

			if ( Utils\get_flag_value( $assoc_args, 'theme' ) ) {
				$themename = Utils\get_flag_value( $assoc_args, 'theme' );
			} elseif ( ! $interactive ) {
				$themename = get_stylesheet();
			} else {
				$themename = $this->choices_theme();
			}

			// Find theme to check.
			$theme = wp_get_theme( $themename );
			if ( $theme->exists() ) {
				$themepath = trailingslashit( $theme->get_template_directory() );
			} elseif ( is_dir( $guest_path ) ) {
				$themepath = trailingslashit( $guest_path );
			} else {
				WP_CLI::error( 'Unable find theme named "' . $themename . '"' );
			}

			// Run themecheck.
			WP_CLI::line( "\n" );
			WP_CLI::line( sprintf( '| Checking %s...', $theme->get( 'Name' ) ) );
			WP_CLI::line( "\n" );

			$is_success = $this->themecheck( $themename, $themepath, $theme );

			// Display themecheck logs.
			foreach ( $this->stack_errors as $log_level => $errors ) {
				if ( Utils\get_flag_value( $assoc_args, 'skip-info' ) && 'INFO' === $log_level ) {
					continue;
				}

				if ( Utils\get_flag_value( $assoc_args, 'skip-recommended' ) && 'RECOMMENDED' === $log_level ) {
					continue;
				}

				foreach ( $errors as $error ) {
					WP_CLI::line( $error . "\n" );
					usleep( 50000 );
				}
			}

			$required_count = ! empty( $this->stack_errors['REQUIRED'] ) ? count( $this->stack_errors['REQUIRED'] ) : 0;
			$warning_count = ! empty( $this->stack_errors['WARNING'] ) ? count( $this->stack_errors['WARNING'] ) : 0;
			$total_errors = $required_count + $warning_count;

			if ( $is_success ) {
				WP_CLI::success( sprintf( 'Congratulations! %s passed the tests!', $theme->get( 'Name' ) ) );
			} else {
				WP_CLI::error( sprintf( '%d error(s) found for %s!', $total_errors, $theme->get( 'Name' ) ), $total_errors );
			}
		}

		/**
		 * Run themecheck.
		 *
		 * @param  string   $theme Theme name.
		 * @param  string   $path  Them absolute path.
		 * @param  WP_Theme $theme_object Theme object.
		 */
		private function themecheck( $theme, $path, $theme_object ) {
			global $themechecks, $data, $themename;

			$themename = $theme;
			$datafiles = array( 'php' => array(), 'css' => array(), 'other' => array() );

			// Find all files.
			$finder = new Finder();
			$finder->ignoreDotFiles( false )->ignoreVCS( false )->exclude( array( 'node_modules', 'tests' ) )->in( $path );

			foreach ( $finder as $node ) {
				$filename = $node->getRealPath();

				switch ( $node->getExtension() ) {
					case 'php':
						$datafiles['php'][ $filename ] = tc_strip_comments( file_get_contents( $filename ) );
						break;

					case 'css':
						$datafiles['css'][ $filename ] = file_get_contents( $filename );
						break;

					default:
						$datafiles['other'][ $filename ] = $node->isDir() ? '' : file_get_contents( $filename );
						break;
				}
			}

			// Run Themecheck.
			$data = array(
				'Name'             => $theme_object->get( 'Name' ),
				'URI'              => $theme_object->display( 'ThemeURI', true, false ),
				'Description'      => $theme_object->display( 'Description', true, false ),
				'Author'           => $theme_object->display( 'Author', true, false ),
				'AuthorURI'        => $theme_object->display( 'AuthorURI', true, false ),
				'Version'          => $theme_object->get( 'Version' ),
				'Template'         => $theme_object->get( 'Template' ),
				'Status'           => $theme_object->get( 'Status' ),
				'Tags'             => $theme_object->get( 'Tags' ),
				'Title'            => $theme_object->get( 'Name' ),
				'AuthorName'       => $theme_object->display( 'Author', false, false ),
				'License'          => $theme_object->display( 'License', false, false ),
				'License URI'      => $theme_object->display( 'License URI', false, false ),
				'Template Version' => $theme_object->display( 'Template Version', false, false ),
			);

			$success = run_themechecks( $datafiles['php'], $datafiles['css'], $datafiles['other'], array(
				'theme' => $theme_object,
				'slug'  => $themename,
			) );

			// Build logs report.
			$log_pattern = '/(<span\sclass=.*>(REQUIRED|WARNING|RECOMMENDED|INFO)<\/span>\s?:)/i';
			$stack_errors = array( 'REQUIRED' => array(), 'WARNING' => array(), 'RECOMMENDED' => array(), 'INFO' => array() );

			foreach ( $themechecks as $check ) {
				if ( ! $check instanceof Themecheck ) {
					continue;
				}

				$errors = (array) $check->getError();
				if ( empty( $errors ) ) {
					continue;
				}

				foreach ( $errors as $error ) {
					$log_level = '';

					if ( preg_match( $log_pattern, $error, $matches ) ) {
						$error = preg_replace( $log_pattern, '', $error );
						$log_level = strtoupper( $matches[2] );
					}

					$error = $this->format_themecheck_result( $error, $log_level );
					if ( ! in_array( $error, $stack_errors[ $log_level ] ) ) {
						$stack_errors[ $log_level ][] = $error;
					}
				}
			}

			$this->stack_errors = $stack_errors;

			return $success;
		}

		/**
		 * Show a list to choices theme.
		 *
		 * @return string
		 */
		private function choices_theme() {
			$themes = array();

			foreach ( wp_get_themes() as $id => $theme ) {
				$themes[ $id ] = $theme->get( 'Name' );
			}

			return cli\menu( $themes, get_stylesheet(), 'Choose a theme' );
		}

		/**
		 * Format themecheck result.
		 *
		 * @param  string $string    HTML report result.
		 * @param  string $log_level Log level.
		 * @return string
		 */
		private function format_themecheck_result( $string, $log_level ) {
			switch ( $log_level ) {
				case 'REQUIRED':
					$string = WP_CLI::colorize( '%R× ' . $log_level . ':%n' ) . $string;
					break;

				case 'WARNING':
					$string = WP_CLI::colorize( '%R* ' . $log_level . ':%n' ) . $string;
					break;

				case 'RECOMMENDED':
					$string = WP_CLI::colorize( '%G* ' . $log_level . ':%n' ) . $string;
					break;

				case 'INFO':
					$string = WP_CLI::colorize( '%C* ' . $log_level . ':%n' ) . $string;
					break;

				default:
					$string = $log_level ? ( '* ' . $log_level . $string ) : '* ' . $string;
					break;
			}

			$string = str_replace( array( '<span>', '<span class="tc-grep">', "<span class='tc-grep'>", '</span>' ), '', $string );
			$string = str_replace( array( '<br>', '<br />', '<br/>' ), "\n  ", $string );

			$string = str_replace( array( '<strong>', '<em>' ), WP_CLI::colorize( '%9"' ), $string );
			$string = str_replace( array( '</strong>', '</em>' ), WP_CLI::colorize( '"%n' ), $string );

			$string = str_replace( array( "<pre class='tc-grep'>", '<pre class="tc-grep">' ), WP_CLI::colorize( "\n  %5" ), $string );
			$string = str_replace( '</pre>', WP_CLI::colorize( '%n' ), $string );

			$string = preg_replace( '/(<a\s?href\s?=\s?[\'|"]([^"|\']*)[\'|"]>([^<]*)<\/a>)/i', "\033[1m\$3\033[0m (\$2)", $string );
			$string = str_replace( 'See See:', 'See:', $string ); // Correcly wrong spell.

			return htmlspecialchars_decode( $string );
		}

		/**
		 * Make sure the Theme Check plugin is installed.
		 *
		 * @param bool $interactive Prompt user before exiting.
		 */
		private function before_themecheck( $interactive ) {
			if ( class_exists( 'ThemeCheckMain' ) ) {
				return true;
			}

			require_once ABSPATH . 'wp-admin/includes/plugin.php';

			if ( array_key_exists( 'theme-check/theme-check.php', get_plugins() ) ) {
				$command = array( array( 'plugin', 'activate', 'theme-check' ), array() );
				$error_msg = "The Theme Check plugin must be activated.\n\n\tRun: wp plugin activate theme-check\n";
			} else {
				$command = array( array( 'plugin', 'install', 'theme-check' ), array( 'activate' => true ) );
				$error_msg = "The Theme Check plugin must be installed and activated.\n\n\tRun: wp plugin install theme-check --activate\n";
			}

			WP_CLI::error( $error_msg, ! $interactive );
			$choose = cli\choose( 'Do you want run above command right now' );

			if ( 'y' !== $choose ) {
				exit;
			}

			WP_CLI::line();
			WP_CLI::run_command( $command[0], $command[1] );
			WP_CLI::line();
		}
	}

	WP_CLI::add_command( 'themecheck', 'WP_CLI_Themecheck_Command' );
endif;
