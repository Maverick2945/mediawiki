<?php
/**
 * Class used for executing shell commands
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

namespace MediaWiki\Shell;

use MediaWiki\HookContainer\HookRunner;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use Shellbox\Shellbox;

/**
 * Executes shell commands
 *
 * @since 1.30
 *
 * Use call chaining with this class for expressiveness:
 *  $result = Shell::command( 'some command' )
 *       ->input( 'foo' )
 *       ->environment( [ 'ENVIRONMENT_VARIABLE' => 'VALUE' ] )
 *       ->limits( [ 'time' => 300 ] )
 *       ->execute();
 *
 *  ... = $result->getExitCode();
 *  ... = $result->getStdout();
 *  ... = $result->getStderr();
 */
class Shell {

	/**
	 * Disallow any root access. Any setuid binaries
	 * will be run without elevated access.
	 *
	 * @since 1.31
	 */
	public const NO_ROOT = 1;

	/**
	 * Use seccomp to block dangerous syscalls
	 * @see <https://en.wikipedia.org/wiki/seccomp>
	 *
	 * @since 1.31
	 */
	public const SECCOMP = 2;

	/**
	 * Create a private /dev
	 *
	 * @since 1.31
	 */
	public const PRIVATE_DEV = 4;

	/**
	 * Restrict the request to have no
	 * network access
	 *
	 * @since 1.31
	 */
	public const NO_NETWORK = 8;

	/**
	 * Deny execve syscall with seccomp
	 * @see <https://en.wikipedia.org/wiki/exec_(system_call)>
	 *
	 * @since 1.31
	 */
	public const NO_EXECVE = 16;

	/**
	 * Deny access to LocalSettings.php (MW_CONFIG_FILE)
	 *
	 * @since 1.31
	 */
	public const NO_LOCALSETTINGS = 32;

	/**
	 * Apply a default set of restrictions for improved
	 * security out of the box.
	 *
	 * @note This value will change over time to provide increased security
	 *       by default, and is not guaranteed to be backwards-compatible.
	 * @since 1.31
	 */
	public const RESTRICT_DEFAULT = self::NO_ROOT | self::SECCOMP | self::PRIVATE_DEV |
									self::NO_LOCALSETTINGS;

	/**
	 * Don't apply any restrictions
	 *
	 * @since 1.31
	 */
	public const RESTRICT_NONE = 0;

	/**
	 * Returns a new instance of Command class
	 *
	 * @note You should check Shell::isDisabled() before calling this
	 * @param string|string[] ...$commands String or array of strings representing the command to
	 * be executed, each value will be escaped.
	 *   Example:   [ 'convert', '-font', 'font name' ] would produce "'convert' '-font' 'font name'"
	 * @return Command
	 */
	public static function command( ...$commands ): Command {
		if ( count( $commands ) === 1 && is_array( reset( $commands ) ) ) {
			// If only one argument has been passed, and that argument is an array,
			// treat it as a list of arguments
			$commands = reset( $commands );
		}
		$command = MediaWikiServices::getInstance()
			->getShellCommandFactory()
			->create();

		return $command->params( $commands );
	}

	/**
	 * Check if this class is effectively disabled via php.ini config
	 *
	 * @return bool
	 */
	public static function isDisabled(): bool {
		static $disabled = null;

		if ( $disabled === null ) {
			if ( !function_exists( 'proc_open' ) ) {
				wfDebug( "proc_open() is disabled" );
				$disabled = true;
			} else {
				$disabled = false;
			}
		}

		return $disabled;
	}

	/**
	 * Locale-independent version of escapeshellarg()
	 *
	 * Originally, this fixed the incorrect use of single quotes on Windows
	 * (https://bugs.php.net/bug.php?id=26285) and the locale problems on Linux in
	 * PHP 5.2.6+ (https://bugs.php.net/bug.php?id=54391). The second bug is still
	 * open as of 2021.
	 *
	 * @param string|string[] ...$args strings to escape and glue together, or a single
	 *     array of strings parameter. Null values are ignored.
	 * @return string
	 */
	public static function escape( ...$args ): string {
		return Shellbox::escape( ...$args );
	}

	/**
	 * Generate a Command object to run a MediaWiki CLI script.
	 * Note that $parameters should be a flat array and an option with an argument
	 * should consist of two consecutive items in the array (do not use "--option value").
	 *
	 * @note You should check Shell::isDisabled() before calling this
	 * @param string $script MediaWiki CLI script with full path
	 * @param string[] $parameters Arguments and options to the script
	 * @param array $options Associative array of options:
	 *     'php': The path to the php executable
	 *     'wrapper': Path to a PHP wrapper to handle the maintenance script
	 * @phan-param array{php?:string,wrapper?:string} $options
	 * @return Command
	 */
	public static function makeScriptCommand(
		string $script, array $parameters, $options = []
	): Command {
		$services = MediaWikiServices::getInstance();
		$phpCli = $services->getMainConfig()->get( MainConfigNames::PhpCli );
		// Give site config file a chance to run the script in a wrapper.
		// The caller may likely want to call wfBasename() on $script.
		( new HookRunner( $services->getHookContainer() ) )->onWfShellWikiCmd( $script, $parameters, $options );
		$cmd = [ $options['php'] ?? $phpCli ];
		if ( isset( $options['wrapper'] ) ) {
			$cmd[] = $options['wrapper'];
		}
		$cmd[] = $script;

		return self::command( $cmd )
			->params( $parameters )
			->restrict( self::RESTRICT_DEFAULT & ~self::NO_LOCALSETTINGS );
	}
}
