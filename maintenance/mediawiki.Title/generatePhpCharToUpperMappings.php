<?php

/**
 * Update list of upper case differences between JS and PHP
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
 * @ingroup Maintenance
 */

use MediaWiki\Shell\Shell;

require_once __DIR__ . '/../Maintenance.php';

/**
 * Update list of upper case differences between JS and PHP
 *
 * @ingroup Maintenance
 * @since 1.33
 */
class GeneratePhpCharToUpperMappings extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Update list of upper case differences between JS and PHP.' );
	}

	public function execute() {
		global $wgContLang;

		$data = [];

		$result = Shell::command( [ 'node', __DIR__ . '/generateJsToUpperCaseList.js' ] )
			// Node allocates lots of memory
			->limits( [ 'memory' => 1024 * 1024 ] )
			->execute();

		if ( $result->getExitcode() !== 0 ) {
			$this->output( $result->getStderr() );
			return;
		}

		$jsUpperChars = json_decode( $result->getStdout() );

		for ( $i = 0; $i <= 0x10ffff; $i++ ) {
			if ( $i >= 0xd800 && $i <= 0xdfff ) {
				// Skip surrogate pairs
				continue;
			}
			$char = \UtfNormal\Utils::codepointToUtf8( $i );
			$phpUpper = $wgContLang->ucfirst( $char );
			$jsUpper = $jsUpperChars[$i];
			if ( $jsUpper !== $phpUpper ) {
				$data[$char] = $phpUpper;
			}
		}

		$this->output( str_replace( '    ', "\t",
			json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE )
		) . "\n" );
	}
}

$maintClass = GeneratePhpCharToUpperMappings::class;
require_once RUN_MAINTENANCE_IF_MAIN;
