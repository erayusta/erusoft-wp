<?php
/**
 * PSR-4 style class loader for the Erusoft\CampaignEngine namespace.
 *
 * Maps Erusoft\CampaignEngine\Foo\Bar to <base>/Foo/Bar.php. The plugin ships no Composer
 * vendor directory, so this is the only loader it needs.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine;

final class Autoloader {

	public const PREFIX = 'Erusoft\\CampaignEngine\\';

	/** @var string|null Directory that holds the namespace root. */
	private static ?string $base = null;

	/** Registers the loader once; later calls are ignored. */
	public static function register( string $base_dir ): void {
		if ( null !== self::$base ) {
			return;
		}

		self::$base = rtrim( $base_dir, '/\\' ) . '/';
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/** Loads one class of this namespace; other names are left to other loaders. */
	public static function load( string $class ): void {
		if ( null === self::$base || 0 !== strncmp( $class, self::PREFIX, strlen( self::PREFIX ) ) ) {
			return;
		}

		$relative = substr( $class, strlen( self::PREFIX ) );

		// Only plain identifiers separated by backslashes; nothing that could walk out of the directory.
		if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $relative ) ) {
			return;
		}

		$file = self::$base . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_file( $file ) ) {
			require_once $file;
		}
	}
}
