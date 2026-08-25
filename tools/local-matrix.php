<?php
/**
 * Run the local PHP and WordPress compatibility matrix in disposable containers.
 *
 * @package PressQueue\Tools
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions -- This is a CLI-only developer tool.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- The runner shells out to Docker by design.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI exceptions are plain text, not rendered HTML.

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This matrix runner must run from the command line.\n" );
	exit( 1 );
}

$pressqueue_matrix_options = getopt( '', array( 'dry-run', 'unit-only', 'php:', 'wordpress:', 'evidence-dir:' ) );
if ( ! is_array( $pressqueue_matrix_options ) ) {
	$pressqueue_matrix_options = array();
}

$pressqueue_matrix_root               = dirname( __DIR__ );
$pressqueue_matrix_php_versions       = pressqueue_matrix_parse_csv_option( $pressqueue_matrix_options['php'] ?? getenv( 'PRESSQUEUE_MATRIX_PHP' ), array( '8.1', '8.2', '8.3', '8.4' ) );
$pressqueue_matrix_wordpress_versions = pressqueue_matrix_parse_csv_option( $pressqueue_matrix_options['wordpress'] ?? getenv( 'PRESSQUEUE_MATRIX_WORDPRESS' ), array( '6.8.8', '7.1' ) );
$pressqueue_matrix_dry_run            = array_key_exists( 'dry-run', $pressqueue_matrix_options );
$pressqueue_matrix_unit_only          = array_key_exists( 'unit-only', $pressqueue_matrix_options );
$pressqueue_matrix_scratch            = pressqueue_matrix_scratch_dir();
$pressqueue_matrix_evidence_dir       = pressqueue_matrix_evidence_dir( $pressqueue_matrix_root, $pressqueue_matrix_options['evidence-dir'] ?? getenv( 'PRESSQUEUE_MATRIX_EVIDENCE_DIR' ) );

pressqueue_matrix_mkdir_checked( $pressqueue_matrix_evidence_dir );

if ( ! $pressqueue_matrix_dry_run ) {
	pressqueue_matrix_ensure_command_exists( 'docker' );
}

foreach ( $pressqueue_matrix_php_versions as $pressqueue_matrix_php_version ) {
	foreach ( $pressqueue_matrix_wordpress_versions as $pressqueue_matrix_wordpress_version ) {
		$pressqueue_matrix_label      = 'php-' . pressqueue_matrix_sanitize_label( $pressqueue_matrix_php_version ) . '-wp-' . pressqueue_matrix_sanitize_label( $pressqueue_matrix_wordpress_version );
		$pressqueue_matrix_stage_root = $pressqueue_matrix_scratch . '/' . $pressqueue_matrix_label;
		$pressqueue_matrix_network    = 'pressqueue-matrix-' . pressqueue_matrix_sanitize_label( $pressqueue_matrix_label ) . '-' . substr( hash( 'sha256', $pressqueue_matrix_stage_root ), 0, 8 );
		$pressqueue_matrix_db_name    = 'pressqueue-matrix-db-' . pressqueue_matrix_sanitize_label( $pressqueue_matrix_label ) . '-' . substr( hash( 'sha256', $pressqueue_matrix_stage_root ), 0, 8 );

		fwrite( STDOUT, "Matrix cell: PHP {$pressqueue_matrix_php_version}, WordPress {$pressqueue_matrix_wordpress_version}\n" );
		pressqueue_matrix_stage_source_tree( $pressqueue_matrix_root, $pressqueue_matrix_stage_root, $pressqueue_matrix_dry_run );

		$pressqueue_matrix_commands = pressqueue_matrix_commands( $pressqueue_matrix_wordpress_version, $pressqueue_matrix_unit_only );
		$pressqueue_matrix_script   = implode( ' && ', array_map( 'pressqueue_matrix_shell_step', $pressqueue_matrix_commands ) );

		$pressqueue_matrix_setup = 'apt-get update && apt-get install -y --no-install-recommends bash default-mysql-client git less libcurl4-openssl-dev libicu-dev libonig-dev libxml2-dev libzip-dev mariadb-client unzip zip zlib1g-dev && docker-php-ext-install bcmath curl dom intl mbstring mysqli zip && php -r "copy(\'https://getcomposer.org/installer\', \'/tmp/composer-setup.php\');" && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet';
		$pressqueue_matrix_body  = $pressqueue_matrix_setup . ' && ' . $pressqueue_matrix_script;

		$pressqueue_matrix_run_command = array(
			'docker',
			'run',
			'--rm',
			'--network',
			$pressqueue_matrix_network,
			'-v',
			$pressqueue_matrix_stage_root . ':/workspace',
			'-w',
			'/workspace',
			'-e',
			'WP_TESTS_DIR=/tmp/wordpress-tests-lib',
			'php:' . $pressqueue_matrix_php_version . '-cli-bookworm',
			'bash',
			'-lc',
			$pressqueue_matrix_body,
		);

		pressqueue_matrix_record_result(
			$pressqueue_matrix_evidence_dir,
			$pressqueue_matrix_label,
			$pressqueue_matrix_dry_run ? 'dry_run' : 'running',
			array(
				'commands'  => $pressqueue_matrix_commands,
				'php'       => $pressqueue_matrix_php_version,
				'wordpress' => $pressqueue_matrix_wordpress_version,
			)
		);

		if ( $pressqueue_matrix_unit_only ) {
			pressqueue_matrix_run_or_print( $pressqueue_matrix_run_command, $pressqueue_matrix_dry_run, $pressqueue_matrix_stage_root );
			pressqueue_matrix_record_result(
				$pressqueue_matrix_evidence_dir,
				$pressqueue_matrix_label,
				$pressqueue_matrix_dry_run ? 'dry_run' : 'passed',
				array(
					'commands'  => $pressqueue_matrix_commands,
					'php'       => $pressqueue_matrix_php_version,
					'wordpress' => $pressqueue_matrix_wordpress_version,
				)
			);
			continue;
		}

		pressqueue_matrix_run_or_print( array( 'docker', 'network', 'create', $pressqueue_matrix_network ), $pressqueue_matrix_dry_run, $pressqueue_matrix_stage_root );
		try {
			pressqueue_matrix_run_or_print(
				array(
					'docker',
					'run',
					'--rm',
					'--detach',
					'--name',
					$pressqueue_matrix_db_name,
					'--network',
					$pressqueue_matrix_network,
					'--network-alias',
					'db',
					'-e',
					'MYSQL_ALLOW_EMPTY_PASSWORD=yes',
					'-e',
					'MYSQL_DATABASE=pressqueue_tests',
					'mysql:8.4',
				),
				$pressqueue_matrix_dry_run,
				$pressqueue_matrix_stage_root
			);
			pressqueue_matrix_run_or_print( $pressqueue_matrix_run_command, $pressqueue_matrix_dry_run, $pressqueue_matrix_stage_root );
			pressqueue_matrix_record_result(
				$pressqueue_matrix_evidence_dir,
				$pressqueue_matrix_label,
				$pressqueue_matrix_dry_run ? 'dry_run' : 'passed',
				array(
					'commands'  => $pressqueue_matrix_commands,
					'php'       => $pressqueue_matrix_php_version,
					'wordpress' => $pressqueue_matrix_wordpress_version,
				)
			);
		} finally {
			pressqueue_matrix_run_or_print( array( 'docker', 'rm', '-f', $pressqueue_matrix_db_name ), $pressqueue_matrix_dry_run, $pressqueue_matrix_stage_root, true );
			pressqueue_matrix_run_or_print( array( 'docker', 'network', 'rm', $pressqueue_matrix_network ), $pressqueue_matrix_dry_run, $pressqueue_matrix_stage_root, true );
		}
	}
}

pressqueue_matrix_run_php_boundary_cell( $pressqueue_matrix_root, $pressqueue_matrix_scratch, $pressqueue_matrix_evidence_dir, $pressqueue_matrix_dry_run );

/**
 * Parse a comma-separated option.
 *
 * @param mixed             $value Value.
 * @param array<int,string> $fallback_values Default values.
 * @return array<int,string>
 */
function pressqueue_matrix_parse_csv_option( $value, array $fallback_values ): array {
	if ( false === $value || null === $value || '' === $value ) {
		return $fallback_values;
	}

	if ( is_array( $value ) ) {
		$value = end( $value );
	}

	$parts = array_filter(
		array_map(
			static function ( string $part ): string {
				return trim( $part );
			},
			explode( ',', (string) $value )
		),
		static function ( string $part ): bool {
			return '' !== $part;
		}
	);

	return array_values( $parts );
}

/**
 * Return the scratch directory.
 *
 * @return string
 */
function pressqueue_matrix_scratch_dir(): string {
	$override = getenv( 'PRESSQUEUE_MATRIX_DIR' );

	if ( false !== $override && '' !== $override ) {
		return rtrim( $override, '/\\' );
	}

	return rtrim( sys_get_temp_dir(), '/\\' ) . '/pressqueue-local-matrix';
}

/**
 * Return the durable result directory.
 *
 * @param string $root Repository root.
 * @param mixed  $override Override value.
 * @return string
 */
function pressqueue_matrix_evidence_dir( string $root, $override ): string {
	if ( is_array( $override ) ) {
		$override = end( $override );
	}

	if ( false !== $override && null !== $override && '' !== $override ) {
		return rtrim( (string) $override, '/\\' );
	}

	return $root . '/.vroni/wip/qa/p04-private-rc';
}

/**
 * Ensure a command exists.
 *
 * @param string $command Command name.
 */
function pressqueue_matrix_ensure_command_exists( string $command ): void {
	$process = proc_open(
		'command -v ' . escapeshellarg( $command ),
		array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes
	);

	if ( ! is_resource( $process ) ) {
		throw new RuntimeException( 'Unable to inspect local commands.' );
	}

	stream_get_contents( $pipes[1] );
	stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );

	if ( 0 !== proc_close( $process ) ) {
		throw new RuntimeException( 'Docker is required for the local matrix runner.' );
	}
}

/**
 * Return commands for a matrix container.
 *
 * @param string $pressqueue_matrix_wordpress_version WordPress version.
 * @param bool   $pressqueue_matrix_unit_only Whether to skip WordPress integration tests.
 * @return array<int,string>
 */
function pressqueue_matrix_commands( string $pressqueue_matrix_wordpress_version, bool $pressqueue_matrix_unit_only ): array {
	$wp_core_dir = '/tmp/wordpress-' . pressqueue_matrix_sanitize_label( $pressqueue_matrix_wordpress_version );

	$pressqueue_matrix_commands = array(
		'composer install --no-interaction --no-progress',
		'composer run test:unit',
		'composer run lint:phpcs',
		'composer run analyse',
		'composer audit --format=plain',
		'composer run smoke:runtime',
		'php tools/runtime-guard-smoke.php php',
	);

	if ( $pressqueue_matrix_unit_only ) {
		return $pressqueue_matrix_commands;
	}

	array_splice(
		$pressqueue_matrix_commands,
		2,
		0,
		array(
			'for i in $(seq 1 60); do mysqladmin ping -hdb -uroot --silent && break; sleep 2; done',
			'bash tools/install-wp-tests.sh pressqueue_tests root "" db ' . escapeshellarg( $pressqueue_matrix_wordpress_version ),
			'composer run test:integration',
			'PACKAGE_SMOKE_WP_PATH=' . escapeshellarg( $wp_core_dir ) . ' PACKAGE_SMOKE_DB_HOST=db PACKAGE_SMOKE_DB_NAME=pressqueue_tests PACKAGE_SMOKE_DB_USER=root PACKAGE_SMOKE_DB_PASS= composer run package:smoke',
			'WP_PATH=' . escapeshellarg( $wp_core_dir ) . ' PLUGIN_CHECK_TARGET=' . escapeshellarg( '/tmp/pressqueue-package-smoke/pressqueue.zip' ) . ' composer run plugin-check',
		)
	);

	return $pressqueue_matrix_commands;
}

/**
 * Run the unsupported PHP boundary cell.
 *
 * @param string $root Repository root.
 * @param string $scratch Scratch root.
 * @param string $evidence_dir Evidence directory.
 * @param bool   $dry_run Whether to print only.
 */
function pressqueue_matrix_run_php_boundary_cell( string $root, string $scratch, string $evidence_dir, bool $dry_run ): void {
	$label      = 'unsupported-php-8-0-runtime';
	$stage_root = $scratch . '/' . $label;
	$command    = array(
		'docker',
		'run',
		'--rm',
		'-v',
		$stage_root . ':/workspace',
		'-w',
		'/workspace',
		'php:8.0-cli-bookworm',
		'php',
		'tools/runtime-guard-smoke.php',
		'php',
	);

	pressqueue_matrix_stage_source_tree( $root, $stage_root, $dry_run );
	pressqueue_matrix_record_result(
		$evidence_dir,
		$label,
		$dry_run ? 'dry_run' : 'running',
		array(
			'commands' => array( pressqueue_matrix_shell_command_line( $command ) ),
			'php'      => '8.0',
			'purpose'  => 'unsupported_php_boundary',
		)
	);
	pressqueue_matrix_run_or_print( $command, $dry_run, $stage_root );
	pressqueue_matrix_record_result(
		$evidence_dir,
		$label,
		$dry_run ? 'dry_run' : 'passed',
		array(
			'commands' => array( pressqueue_matrix_shell_command_line( $command ) ),
			'php'      => '8.0',
			'purpose'  => 'unsupported_php_boundary',
		)
	);
}

/**
 * Write a durable matrix result.
 *
 * @param string              $evidence_dir Evidence directory.
 * @param string              $label Cell label.
 * @param string              $status Status.
 * @param array<string,mixed> $details Details.
 */
function pressqueue_matrix_record_result( string $evidence_dir, string $label, string $status, array $details ): void {
	$path = $evidence_dir . '/' . pressqueue_matrix_sanitize_label( $label ) . '.json';
	$data = array_merge(
		$details,
		array(
			'generated_at_utc' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'label'            => $label,
			'status'           => $status,
		)
	);
	$json = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

	if ( ! is_string( $json ) ) {
		throw new RuntimeException( 'Unable to encode matrix result.' );
	}

	if ( false === file_put_contents( $path, $json . "\n" ) ) {
		throw new RuntimeException( 'Unable to write matrix result: ' . $path );
	}
}

/**
 * Stage the source tree into a disposable directory.
 *
 * @param string $source Source directory.
 * @param string $destination Destination directory.
 * @param bool   $pressqueue_matrix_dry_run Whether to print only.
 */
function pressqueue_matrix_stage_source_tree( string $source, string $destination, bool $pressqueue_matrix_dry_run ): void {
	if ( $pressqueue_matrix_dry_run ) {
		fwrite( STDOUT, "Would stage {$source} to {$destination}\n" );
		return;
	}

	pressqueue_matrix_remove_path( $destination );
	pressqueue_matrix_mkdir_checked( $destination );

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ( $iterator as $item ) {
		$relative = str_replace( DIRECTORY_SEPARATOR, '/', substr( $item->getPathname(), strlen( $source ) + 1 ) );

		if ( pressqueue_matrix_should_skip_path( $relative ) ) {
			continue;
		}

		$target = $destination . '/' . $relative;

		if ( $item->isDir() ) {
			pressqueue_matrix_mkdir_checked( $target );
			continue;
		}

		pressqueue_matrix_mkdir_checked( dirname( $target ) );
		if ( ! copy( $item->getPathname(), $target ) ) {
			throw new RuntimeException( 'Unable to copy matrix file: ' . $relative );
		}
	}
}

/**
 * Check whether a path should be skipped in the staged matrix tree.
 *
 * @param string $relative Relative path.
 * @return bool
 */
function pressqueue_matrix_should_skip_path( string $relative ): bool {
	$segments = explode( '/', $relative );
	$top      = $segments[0] ?? '';

	if ( '' !== $top && '.' === $top[0] ) {
		return true;
	}

	return 'vendor' === $top;
}

/**
 * Create a directory.
 *
 * @param string $path Path.
 */
function pressqueue_matrix_mkdir_checked( string $path ): void {
	if ( is_dir( $path ) ) {
		return;
	}

	if ( ! mkdir( $path, 0775, true ) && ! is_dir( $path ) ) {
		throw new RuntimeException( 'Unable to create matrix directory: ' . $path );
	}
}

/**
 * Remove a path recursively.
 *
 * @param string $path Path.
 */
function pressqueue_matrix_remove_path( string $path ): void {
	if ( ! file_exists( $path ) ) {
		return;
	}

	if ( is_file( $path ) || is_link( $path ) ) {
		if ( ! unlink( $path ) ) {
			throw new RuntimeException( 'Unable to remove matrix file: ' . $path );
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
				throw new RuntimeException( 'Unable to remove matrix directory: ' . $item_path );
			}
		} elseif ( ! unlink( $item_path ) ) {
			throw new RuntimeException( 'Unable to remove matrix file: ' . $item_path );
		}
	}

	if ( ! rmdir( $path ) ) {
		throw new RuntimeException( 'Unable to remove matrix directory: ' . $path );
	}
}

/**
 * Run or print a command.
 *
 * @param array<int,string> $command Command.
 * @param bool              $pressqueue_matrix_dry_run Whether to print only.
 * @param string            $cwd Working directory.
 * @param bool              $allow_failure Whether failures are allowed.
 */
function pressqueue_matrix_run_or_print( array $command, bool $pressqueue_matrix_dry_run, string $cwd, bool $allow_failure = false ): void {
	fwrite( STDOUT, pressqueue_matrix_shell_command_line( $command ) . "\n" );

	if ( $pressqueue_matrix_dry_run ) {
		return;
	}

	$process = proc_open(
		pressqueue_matrix_shell_command_line( $command ),
		array(
			1 => STDOUT,
			2 => STDERR,
		),
		$pipes,
		$cwd
	);

	if ( ! is_resource( $process ) ) {
		throw new RuntimeException( 'Unable to start matrix command.' );
	}

	$status = proc_close( $process );

	if ( 0 !== $status && ! $allow_failure ) {
		throw new RuntimeException( 'Matrix command failed with status ' . $status . ': ' . implode( ' ', $command ) );
	}
}

/**
 * Quote a command for bash -lc.
 *
 * @param string $command Command.
 * @return string
 */
function pressqueue_matrix_shell_step( string $command ): string {
	return '(' . $command . ')';
}

/**
 * Build a shell command line.
 *
 * @param array<int,string> $command Command.
 * @return string
 */
function pressqueue_matrix_shell_command_line( array $command ): string {
	return implode( ' ', array_map( 'escapeshellarg', $command ) );
}

/**
 * Sanitize a label for container names.
 *
 * @param string $value Value.
 * @return string
 */
function pressqueue_matrix_sanitize_label( string $value ): string {
	$value = strtolower( preg_replace( '/[^a-zA-Z0-9_.-]+/', '-', $value ) ?? '' );

	return trim( $value, '-.' );
}
