<?php
/**
 * Build and inspect a private package smoke artifact.
 *
 * @package PressQueue\Tools
 */

declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This smoke check must run from the command line.\n" );
	exit( 1 );
}

$root        = dirname( __DIR__ );
$scratch     = $root . '/.vroni/wip/scratch/package-smoke';
$stage_root  = $scratch . '/stage';
$plugin_root = $stage_root . '/pressqueue';
$zip_path    = $scratch . '/pressqueue.zip';

remove_path( $scratch );
mkdir_checked( $plugin_root );

$entries = array(
	'pressqueue.php',
	'src',
	'languages',
	'readme.txt',
	'LICENSE',
	'composer.json',
	'composer.lock',
);

foreach ( $entries as $entry ) {
	copy_path( $root . '/' . $entry, $plugin_root . '/' . $entry );
}

run_command(
	array(
		'composer',
		'install',
		'--no-dev',
		'--classmap-authoritative',
		'--optimize-autoloader',
		'--no-interaction',
		'--no-progress',
	),
	$plugin_root
);

remove_path( $plugin_root . '/composer.lock' );

create_zip( $stage_root, $zip_path );
assert_zip_contents( $zip_path );

$wp_path = getenv( 'PACKAGE_SMOKE_WP_PATH' );

if ( false === $wp_path || '' === $wp_path ) {
	$wp_path = getenv( 'WP_PATH' );
}

if ( false === $wp_path || '' === $wp_path ) {
	throw new RuntimeException( 'PACKAGE_SMOKE_WP_PATH or WP_PATH must point to a disposable supported WordPress install.' );
}

assert_wordpress_activation( $zip_path, $wp_path, $scratch );

fwrite( STDOUT, "Package smoke artifact: " . $zip_path . "\n" );

/**
 * Create a directory or fail.
 *
 * @param string $path Directory path.
 */
function mkdir_checked( string $path ): void {
	if ( is_dir( $path ) ) {
		return;
	}

	if ( ! mkdir( $path, 0775, true ) && ! is_dir( $path ) ) {
		throw new RuntimeException( 'Unable to create directory: ' . $path );
	}
}

/**
 * Remove a path recursively.
 *
 * @param string $path Path.
 */
function remove_path( string $path ): void {
	if ( ! file_exists( $path ) ) {
		return;
	}

	if ( is_file( $path ) || is_link( $path ) ) {
		if ( ! unlink( $path ) ) {
			throw new RuntimeException( 'Unable to remove file: ' . $path );
		}

		return;
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $iterator as $item ) {
		$item_path = $item->getPathname();

		if ( $item->isDir() && ! $item->isLink() ) {
			if ( ! rmdir( $item_path ) ) {
				throw new RuntimeException( 'Unable to remove directory: ' . $item_path );
			}
		} elseif ( ! unlink( $item_path ) ) {
			throw new RuntimeException( 'Unable to remove file: ' . $item_path );
		}
	}

	if ( ! rmdir( $path ) ) {
		throw new RuntimeException( 'Unable to remove directory: ' . $path );
	}
}

/**
 * Copy a file or directory recursively.
 *
 * @param string $source Source path.
 * @param string $destination Destination path.
 */
function copy_path( string $source, string $destination ): void {
	if ( is_dir( $source ) ) {
		mkdir_checked( $destination );

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( '.gitkeep' === $item->getBasename() ) {
				continue;
			}

			$target = $destination . '/' . $iterator->getSubPathName();

			if ( $item->isDir() ) {
				mkdir_checked( $target );
			} else {
				copy_path( $item->getPathname(), $target );
			}
		}

		return;
	}

	mkdir_checked( dirname( $destination ) );

	if ( ! copy( $source, $destination ) ) {
		throw new RuntimeException( 'Unable to copy ' . $source . ' to ' . $destination );
	}
}

/**
 * Run a command.
 *
 * @param array<int,string> $command Command arguments.
 * @param string            $cwd Working directory.
 */
function run_command( array $command, string $cwd ): void {
	$escaped = array_map( 'escapeshellarg', $command );
	$process = proc_open(
		implode( ' ', $escaped ),
		array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes,
		$cwd
	);

	if ( ! is_resource( $process ) ) {
		throw new RuntimeException( 'Unable to start command: ' . implode( ' ', $command ) );
	}

	$output = stream_get_contents( $pipes[1] );
	$error  = stream_get_contents( $pipes[2] );

	fclose( $pipes[1] );
	fclose( $pipes[2] );

	$status = proc_close( $process );

	if ( 0 !== $status ) {
		throw new RuntimeException(
			"Command failed: " . implode( ' ', $command ) . "\n" . $output . $error
		);
	}
}

/**
 * Create the package zip.
 *
 * @param string $stage_root Stage root.
 * @param string $zip_path Zip path.
 */
function create_zip( string $stage_root, string $zip_path ): void {
	$zip = new ZipArchive();

	if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		throw new RuntimeException( 'Unable to create zip: ' . $zip_path );
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $stage_root, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ( $iterator as $item ) {
		$path     = $item->getPathname();
		$relative = str_replace( DIRECTORY_SEPARATOR, '/', substr( $path, strlen( $stage_root ) + 1 ) );

		if ( $item->isDir() ) {
			$zip->addEmptyDir( $relative . '/' );
			continue;
		}

		if ( ! $item->isFile() ) {
			continue;
		}

		$zip->addFile( $path, $relative );
	}

	$zip->close();
}

/**
 * Assert package contents.
 *
 * @param string $zip_path Zip path.
 */
function assert_zip_contents( string $zip_path ): void {
	$zip = new ZipArchive();

	if ( true !== $zip->open( $zip_path ) ) {
		throw new RuntimeException( 'Unable to inspect zip: ' . $zip_path );
	}

	$required = array(
		'pressqueue/pressqueue.php',
		'pressqueue/readme.txt',
		'pressqueue/languages/',
		'pressqueue/composer.json',
		'pressqueue/src/Plugin.php',
		'pressqueue/vendor/autoload.php',
		'pressqueue/vendor/composer/autoload_real.php',
	);

	$forbidden_fragments = array(
		'/.git/',
		'/.github/',
		'/.vroni/',
		'/tests/',
		'/tools/',
		'/build/',
		'/.phpunit.cache/',
		'/vendor/bin/',
		'/vendor/phpunit/',
		'/vendor/phpstan/',
		'/vendor/wp-coding-standards/',
		'/.gitkeep',
		'/composer.lock',
	);

	$names = array();

	for ( $index = 0; $index < $zip->numFiles; ++$index ) {
		$name    = $zip->getNameIndex( $index );
		$names[] = $name;

		if ( 0 !== strpos( $name, 'pressqueue/' ) ) {
			throw new RuntimeException( 'Zip entry is not rooted at pressqueue/: ' . $name );
		}

		foreach ( $forbidden_fragments as $fragment ) {
			if ( false !== strpos( $name, $fragment ) ) {
				throw new RuntimeException( 'Forbidden package entry: ' . $name );
			}
		}
	}

	foreach ( $required as $name ) {
		if ( ! in_array( $name, $names, true ) ) {
			throw new RuntimeException( 'Missing package entry: ' . $name );
		}
	}

	$zip->close();
}

/**
 * Activate the package inside a disposable supported WordPress runtime.
 *
 * @param string $zip_path Package zip path.
 * @param string $wp_path WordPress path.
 */
function assert_wordpress_activation( string $zip_path, string $wp_path, string $scratch ): void {
	$wp_command = resolve_wp_cli_command( $scratch );

	run_command(
		array_merge(
			$wp_command,
			array(
			'plugin',
			'install',
			$zip_path,
			'--force',
			'--path=' . $wp_path,
			)
		),
		$wp_path
	);

	run_command(
		array_merge(
			$wp_command,
			array(
			'plugin',
			'activate',
			'pressqueue',
			'--path=' . $wp_path,
			)
		),
		$wp_path
	);

	run_command(
		array_merge(
			$wp_command,
			array(
			'plugin',
			'deactivate',
			'pressqueue',
			'--path=' . $wp_path,
			)
		),
		$wp_path
	);
}

/**
 * Resolve a standalone WP-CLI command.
 *
 * @param string $scratch Scratch directory.
 * @return array<int,string>
 */
function resolve_wp_cli_command( string $scratch ): array {
	$wp_binary = getenv( 'WP_CLI_BIN' );

	if ( false !== $wp_binary && '' !== $wp_binary ) {
		if ( ! file_exists( $wp_binary ) ) {
			throw new RuntimeException( 'WP-CLI binary not found at ' . $wp_binary );
		}

		return array( $wp_binary );
	}

	$phar = $scratch . '/wp-cli-2.12.0.phar';

	if ( ! file_exists( $phar ) ) {
		$url = 'https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar';

		if ( false === copy( $url, $phar ) ) {
			throw new RuntimeException( 'Unable to download WP-CLI Phar.' );
		}
	}

	return array( PHP_BINARY, $phar );
}
