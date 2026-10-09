<?php
/**
 * Build and inspect a private package smoke artifact.
 *
 * @package PressQueue\Tools
 */

declare(strict_types=1);

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- This is a CLI-only developer tool with local helpers.
// phpcs:disable Squiz.Strings.DoubleQuoteUsage -- Command diagnostics deliberately include shell-style fragments.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- This is an isolated CLI script, not plugin runtime code.
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive exposes numFiles.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- The runner shells out to Composer, WP-CLI, and Zip tooling by design.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI exceptions are plain text, not rendered HTML.
// phpcs:disable WordPress.WP.AlternativeFunctions -- This CLI tool manages a disposable local package tree directly.

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This smoke check must run from the command line.\n" );
	exit( 1 );
}

const PRESSQUEUE_WP_CLI_VERSION = '2.12.0';
const PRESSQUEUE_WP_CLI_SHA256  = 'ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c';

try {
	$root        = dirname( __DIR__ );
	$scratch     = package_smoke_scratch_dir();
	$stage_root  = $scratch . '/stage';
	$plugin_root = $stage_root . '/pressqueue';
	$zip_path    = $scratch . '/pressqueue.zip';

	remove_path( $scratch );
	mkdir_checked( $plugin_root );

	$entries = array(
		'pressqueue.php',
		'assets',
		'src',
		'languages',
		'readme.txt',
		'LICENSE',
		'THIRD_PARTY_NOTICES.md',
		'composer.json',
		'composer.lock',
		'uninstall.php',
	);

	foreach ( $entries as $entry ) {
		$destination = 'THIRD_PARTY_NOTICES.md' === $entry ? 'THIRD_PARTY_NOTICES.txt' : $entry;
		copy_path( $root . '/' . $entry, $plugin_root . '/' . $destination );
	}

	$plugin_source = file_get_contents( $plugin_root . '/pressqueue.php' );
	if ( false === $plugin_source || 1 !== preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $plugin_source, $version_match ) ) {
		throw new RuntimeException( 'The plugin version header is missing.' );
	}
	$package_composer = json_decode( (string) file_get_contents( $plugin_root . '/composer.json' ), true, 512, JSON_THROW_ON_ERROR );
	$package_composer['version'] = $version_match[1];
	if ( false === file_put_contents( $plugin_root . '/composer.json', json_encode( $package_composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n" ) ) {
		throw new RuntimeException( 'Unable to write the package version metadata.' );
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
	$zip_summary = assert_zip_contents( $zip_path );

	$wp_path = getenv( 'PACKAGE_SMOKE_WP_PATH' );

	if ( false === $wp_path || '' === $wp_path ) {
		$wp_path = getenv( 'WP_PATH' );
	}

	if ( false === $wp_path || '' === $wp_path ) {
		write_package_smoke_evidence(
			$root,
			$zip_path,
			$zip_summary,
			'not_run_environment_missing',
			'PACKAGE_SMOKE_WP_PATH or WP_PATH was not set to a disposable WordPress install.'
		);
		throw new RuntimeException( 'PACKAGE_SMOKE_WP_PATH or WP_PATH must point to a disposable supported WordPress install.' );
	}

	assert_wordpress_activation( $zip_path, $wp_path, $scratch );
	write_package_smoke_evidence( $root, $zip_path, $zip_summary, 'passed', null );

	fwrite( STDOUT, "Package smoke artifact: " . $zip_path . "\n" );
} catch ( RuntimeException $exception ) {
	fwrite( STDERR, $exception->getMessage() . "\n" );
	exit( 1 );
}

/**
 * Return the package smoke scratch directory.
 *
 * @return string
 */
function package_smoke_scratch_dir(): string {
	$override = getenv( 'PRESSQUEUE_PACKAGE_SMOKE_DIR' );

	if ( false !== $override && '' !== $override ) {
		return rtrim( $override, '/\\' );
	}

	return rtrim( sys_get_temp_dir(), '/\\' ) . '/pressqueue-package-smoke';
}

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
	$result = run_command_with_status( $command, $cwd );

	if ( 0 !== $result['status'] ) {
		throw new RuntimeException(
			"Command failed: " . implode( ' ', $command ) . "\n" . $result['output']
		);
	}
}

/**
 * Run a command and return output with status.
 *
 * @param array<int,string> $command Command arguments.
 * @param string            $cwd Working directory.
 * @return array{output:string,status:int}
 */
function run_command_with_status( array $command, string $cwd ): array {
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

	return array(
		'output' => $output . $error,
		'status' => $status,
	);
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
 * @return array{required_entries:array<int,string>,forbidden_fragments:array<int,string>,entries:array<int,string>,entry_count:int}
 */
function assert_zip_contents( string $zip_path ): array {
	$zip = new ZipArchive();

	if ( true !== $zip->open( $zip_path ) ) {
		throw new RuntimeException( 'Unable to inspect zip: ' . $zip_path );
	}

	$required = array(
		'pressqueue/pressqueue.php',
		'pressqueue/uninstall.php',
		'pressqueue/readme.txt',
		'pressqueue/THIRD_PARTY_NOTICES.txt',
		'pressqueue/languages/',
		'pressqueue/languages/pressqueue.pot',
		'pressqueue/assets/admin/condition-builder.js',
		'pressqueue/assets/editor/post-controls.js',
		'pressqueue/composer.json',
		'pressqueue/src/Plugin.php',
		'pressqueue/vendor/autoload.php',
		'pressqueue/vendor/composer/autoload_real.php',
		'pressqueue/vendor/woocommerce/action-scheduler/license.txt',
	);

	$forbidden_fragments = array(
		'/.git/',
		'/.github/',
		'/.',
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

	return array(
		'required_entries'    => $required,
		'forbidden_fragments' => $forbidden_fragments,
		'entries'             => $names,
		'entry_count'         => count( $names ),
	);
}

/**
 * Write durable package smoke evidence.
 *
 * @param string              $root Repository root.
 * @param string              $zip_path Zip path.
 * @param array<string,mixed> $zip_summary Zip summary.
 * @param string              $activation_status Activation status.
 * @param string|null         $activation_reason Activation limitation.
 */
function write_package_smoke_evidence( string $root, string $zip_path, array $zip_summary, string $activation_status, ?string $activation_reason ): void {
	$evidence_dir = package_smoke_evidence_dir( $root );

	mkdir_checked( $evidence_dir );

	$sha256  = hash_file( 'sha256', $zip_path );
	$size    = filesize( $zip_path );
	$now_utc = gmdate( 'Y-m-d\TH:i:s\Z' );

	if ( ! is_string( $sha256 ) || false === $size ) {
		throw new RuntimeException( 'Unable to inspect package smoke artifact.' );
	}

	$manifest = array(
		'generated_at_utc'    => $now_utc,
		'artifact_path'       => $zip_path,
		'artifact_sha256'     => $sha256,
		'artifact_size_bytes' => $size,
		'zip_root'            => 'pressqueue/',
		'zip_entry_count'     => $zip_summary['entry_count'],
		'required_entries'    => $zip_summary['required_entries'],
		'forbidden_fragments' => $zip_summary['forbidden_fragments'],
		'entries'             => $zip_summary['entries'],
	);
	$smoke    = array(
		'generated_at_utc'          => $now_utc,
		'artifact_path'             => $zip_path,
		'artifact_sha256'           => $sha256,
		'artifact_size_bytes'       => $size,
		'zip_entry_count'           => $zip_summary['entry_count'],
		'content_inspection_status' => 'passed',
		'activation_smoke_status'   => $activation_status,
		'activation_smoke_reason'   => $activation_reason,
	);

	write_json_file( $evidence_dir . '/manifest.json', $manifest );
	write_json_file( $evidence_dir . '/smoke.json', $smoke );

	if ( false === file_put_contents( $evidence_dir . '/checksum.sha256', $sha256 . '  ' . $zip_path . "\n" ) ) {
		throw new RuntimeException( 'Unable to write package checksum evidence.' );
	}
}

/**
 * Return the durable package smoke evidence directory.
 *
 * @param string $root Repository root.
 * @return string
 */
function package_smoke_evidence_dir( string $root ): string {
	$override = getenv( 'PRESSQUEUE_PACKAGE_EVIDENCE_DIR' );

	if ( false !== $override && '' !== $override ) {
		return rtrim( $override, '/\\' );
	}

	return $root . '/build/qa/package';
}

/**
 * Write JSON evidence.
 *
 * @param string              $path File path.
 * @param array<string,mixed> $data Data.
 */
function write_json_file( string $path, array $data ): void {
	$json = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

	if ( ! is_string( $json ) ) {
		throw new RuntimeException( 'Unable to encode package smoke evidence.' );
	}

	if ( false === file_put_contents( $path, $json . "\n" ) ) {
		throw new RuntimeException( 'Unable to write package smoke evidence: ' . $path );
	}
}

/**
 * Activate the package inside a disposable supported WordPress runtime.
 *
 * @param string $zip_path Package zip path.
 * @param string $wp_path WordPress path.
 * @param string $scratch Scratch directory.
 */
function assert_wordpress_activation( string $zip_path, string $wp_path, string $scratch ): void {
	$wp_command = resolve_wp_cli_command( $scratch );

	prepare_wordpress_site( $wp_command, $wp_path );
	install_package( $wp_command, $zip_path, $wp_path );
	activate_package( $wp_command, $wp_path );
	assert_scripts_enqueue( $wp_command, $wp_path );
	deactivate_package( $wp_command, $wp_path );
	activate_package( $wp_command, $wp_path );
	deactivate_package( $wp_command, $wp_path );
	assert_preserving_uninstall( $wp_command, $zip_path, $wp_path );
	install_package( $wp_command, $zip_path, $wp_path );
	activate_package( $wp_command, $wp_path );
	assert_deleting_uninstall( $wp_command, $wp_path );
	assert_multisite_behavior( $wp_command, $zip_path );
}

/**
 * Prepare a disposable WordPress site when the core tree is not installed.
 *
 * @param array<int,string> $wp_command WP-CLI command.
 * @param string            $wp_path WordPress path.
 */
function prepare_wordpress_site( array $wp_command, string $wp_path ): void {
	$installed = run_command_with_status(
		array_merge(
			$wp_command,
			array(
				'core',
				'is-installed',
				'--path=' . $wp_path,
			)
		),
		$wp_path
	);

	if ( 0 === $installed['status'] ) {
		return;
	}

	if ( ! file_exists( rtrim( $wp_path, '/\\' ) . '/wp-config.php' ) ) {
		run_command(
			array_merge(
				$wp_command,
				array(
					'config',
					'create',
					'--dbname=' . package_smoke_db_value( 'PACKAGE_SMOKE_DB_NAME', 'pressqueue_tests' ),
					'--dbuser=' . package_smoke_db_value( 'PACKAGE_SMOKE_DB_USER', 'root' ),
					'--dbpass=' . package_smoke_db_value( 'PACKAGE_SMOKE_DB_PASS', '' ),
					'--dbhost=' . package_smoke_db_value( 'PACKAGE_SMOKE_DB_HOST', 'db' ),
					'--skip-check',
					'--path=' . $wp_path,
				)
			),
			$wp_path
		);
	}

	run_command_with_status(
		array_merge(
			$wp_command,
			array(
				'db',
				'create',
				'--path=' . $wp_path,
			)
		),
		$wp_path
	);

	run_command(
		array_merge(
			$wp_command,
			array(
				'core',
				'install',
				'--url=' . package_smoke_db_value( 'PACKAGE_SMOKE_SITE_URL', 'https://pressqueue-smoke.test' ),
				'--title=PressQueue Smoke',
				'--admin_user=pressqueue',
				'--admin_password=pressqueue-password',
				'--admin_email=pressqueue@example.test',
				'--skip-email',
				'--path=' . $wp_path,
			)
		),
		$wp_path
	);
}

/**
 * Return a smoke environment value.
 *
 * @param string $key Environment key.
 * @param string $fallback Fallback.
 * @return string
 */
function package_smoke_db_value( string $key, string $fallback ): string {
	$value = getenv( $key );

	return false === $value ? $fallback : $value;
}

/**
 * Install the smoke package.
 *
 * @param array<int,string> $wp_command WP-CLI command.
 * @param string            $zip_path Package zip path.
 * @param string            $wp_path WordPress path.
 */
function install_package( array $wp_command, string $zip_path, string $wp_path ): void {
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
}

/**
 * Activate the smoke package.
 *
 * @param array<int,string> $wp_command WP-CLI command.
 * @param string            $wp_path WordPress path.
 */
function activate_package( array $wp_command, string $wp_path ): void {
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
}

/**
 * Deactivate the smoke package.
 *
 * @param array<int,string> $wp_command WP-CLI command.
 * @param string            $wp_path WordPress path.
 */
function deactivate_package( array $wp_command, string $wp_path ): void {
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
 * Assert admin and editor scripts enqueue when their hooks fire.
 *
 * @param array<int,string> $wp_command WP-CLI command.
 * @param string            $wp_path WordPress path.
 */
function assert_scripts_enqueue( array $wp_command, string $wp_path ): void {
	$code = '$_GET["page"]="pressqueue-rules"; do_action("admin_enqueue_scripts"); if (!wp_script_is("pressqueue-condition-builder", "enqueued")) { throw new RuntimeException("Condition builder script was not enqueued."); } require_once ABSPATH . "wp-admin/includes/screen.php"; set_current_screen("post"); get_current_screen()->post_type="post"; do_action("enqueue_block_editor_assets"); if (!wp_script_is("pressqueue-post-controls", "enqueued")) { throw new RuntimeException("Post controls script was not enqueued."); }';

	run_command(
		array_merge(
			$wp_command,
			array(
				'eval',
				$code,
				'--path=' . $wp_path,
			)
		),
		$wp_path
	);
}

/**
 * Assert the default uninstall path preserves data but removes capabilities.
 *
 * @param array<int,string> $wp_command WP-CLI command.
 * @param string            $zip_path Package zip path.
 * @param string            $wp_path WordPress path.
 */
function assert_preserving_uninstall( array $wp_command, string $zip_path, string $wp_path ): void {
	unset( $zip_path );

	uninstall_package( $wp_command, $wp_path );

	$code = '$settings = get_option("pressqueue_privacy_settings", null); if (null === $settings) { throw new RuntimeException("Preserving uninstall removed privacy settings."); } $role = get_role("administrator"); if ($role && $role->has_cap("export_pressqueue_data")) { throw new RuntimeException("Preserving uninstall left PressQueue capabilities."); }';

	run_command(
		array_merge(
			$wp_command,
			array(
				'eval',
				$code,
				'--path=' . $wp_path,
			)
		),
		$wp_path
	);
}

/**
 * Assert the opted-in uninstall path deletes plugin-owned data.
 *
 * @param array<int,string> $wp_command WP-CLI command.
 * @param string            $wp_path WordPress path.
 */
function assert_deleting_uninstall( array $wp_command, string $wp_path ): void {
	$enable_delete = '$settings = get_option("pressqueue_privacy_settings", array()); $settings["schema_version"] = 1; $settings["retention_enabled"] = false; $settings["retention_days"] = 365; $settings["delete_data_on_uninstall"] = true; update_option("pressqueue_privacy_settings", $settings, false);';

	run_command(
		array_merge(
			$wp_command,
			array(
				'eval',
				$enable_delete,
				'--path=' . $wp_path,
			)
		),
		$wp_path
	);

	uninstall_package( $wp_command, $wp_path );

	$code = 'if (false !== get_option("pressqueue_privacy_settings", false)) { throw new RuntimeException("Deleting uninstall left privacy settings."); } $role = get_role("administrator"); if ($role && $role->has_cap("export_pressqueue_data")) { throw new RuntimeException("Deleting uninstall left PressQueue capabilities."); }';

	run_command(
		array_merge(
			$wp_command,
			array(
				'eval',
				$code,
				'--path=' . $wp_path,
			)
		),
		$wp_path
	);
}

/**
 * Uninstall the smoke package.
 *
 * @param array<int,string> $wp_command WP-CLI command.
 * @param string            $wp_path WordPress path.
 */
function uninstall_package( array $wp_command, string $wp_path ): void {
	run_command(
		array_merge(
			$wp_command,
			array(
				'plugin',
				'uninstall',
				'pressqueue',
				'--deactivate',
				'--path=' . $wp_path,
			)
		),
		$wp_path
	);
}

/**
 * Assert multisite behavior when a disposable multisite install is provided.
 *
 * @param array<int,string> $wp_command WP-CLI command.
 * @param string            $zip_path Package zip path.
 */
function assert_multisite_behavior( array $wp_command, string $zip_path ): void {
	$multisite_path = getenv( 'PACKAGE_SMOKE_MULTISITE_PATH' );
	if ( false === $multisite_path || '' === $multisite_path ) {
		return;
	}

	install_package( $wp_command, $zip_path, $multisite_path );

	$result = run_command_with_status(
		array_merge(
			$wp_command,
			array(
				'plugin',
				'activate',
				'pressqueue',
				'--network',
				'--path=' . $multisite_path,
			)
		),
		$multisite_path
	);

	if ( 0 === $result['status'] || false === strpos( $result['output'], 'does not support network activation' ) ) {
		throw new RuntimeException( 'Network activation was not refused in the multisite package smoke.' );
	}

	uninstall_package( $wp_command, $multisite_path );
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

	$phar = $scratch . '/wp-cli-' . PRESSQUEUE_WP_CLI_VERSION . '.phar';

	if ( ! file_exists( $phar ) ) {
		$url = 'https://github.com/wp-cli/wp-cli/releases/download/v' . PRESSQUEUE_WP_CLI_VERSION . '/wp-cli-' . PRESSQUEUE_WP_CLI_VERSION . '.phar';

		if ( false === copy( $url, $phar ) ) {
			throw new RuntimeException( 'Unable to download WP-CLI Phar.' );
		}
	}

	verify_wp_cli_phar( $phar );

	return array( PHP_BINARY, $phar );
}

/**
 * Verify the pinned WP-CLI Phar checksum.
 *
 * @param string $phar Phar path.
 */
function verify_wp_cli_phar( string $phar ): void {
	$actual = hash_file( 'sha256', $phar );

	if ( PRESSQUEUE_WP_CLI_SHA256 === $actual ) {
		return;
	}

	unlink( $phar );

	throw new RuntimeException( 'WP-CLI Phar checksum did not match the pinned SHA-256.' );
}
