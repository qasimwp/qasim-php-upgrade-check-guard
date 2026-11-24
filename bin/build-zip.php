<?php
/**
 * Builds dist/qasim-php-upgrade-check-guard.zip for live sites (dev script, not shipped). Usage: php bin/build-zip.php [--no-engine]
 *
 * Includes (spec §8 T7): vendor-prefixed, standards, src, assets, languages, qasim-php-upgrade-check-guard.php,
 * uninstall.php, readme.txt, licenses. Optional folders that don't exist yet are skipped.
 * Everything else (tests, docs, engine, bin, build, tools, dist, dotfiles, composer.*,
 * scoper.inc.php, CLAUDE.md) is left out because it is not on the include list.
 * Refuses without a complete engine build (build/engine-build-ok.txt matching the current build inputs and file
 * count), unless --no-engine is passed, which builds a zip without the engine (DECISIONS D47).
 *
 * @package QasimPHPUpgradeCheckGuard
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DiscouragedPHPFunctions -- CLI dev script, no WordPress loaded.

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

require __DIR__ . '/engine-inputs.php';

if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "The zip extension is required.\n" );
	exit( 1 );
}

$qpucg_root      = dirname( __DIR__ );
$qpucg_no_engine = in_array( '--no-engine', array_slice( (array) $argv, 1 ), true );
$qpucg_dirs      = array( 'src', 'assets', 'languages', 'standards' );
$qpucg_files     = array_merge(
	array( 'qasim-php-upgrade-check-guard.php', 'uninstall.php', 'readme.txt' ),
	array_map( 'basename', array_merge( (array) glob( $qpucg_root . '/LICENSE*' ), (array) glob( $qpucg_root . '/COPYING*' ) ) )
);

// The engine must be a complete, checked build of the current inputs (bin/build-engine.php writes build/engine-build-ok.txt last).
$qpucg_engine = $qpucg_root . '/vendor-prefixed';
if ( $qpucg_no_engine ) {
	echo "--no-engine: building a zip without vendor-prefixed/ (scans will report the engine as missing).\n";
} else {
	$qpucg_marker = qpucg_engine_marker_path( $qpucg_root );
	if ( ! is_dir( $qpucg_engine ) ) {
		fwrite( STDERR, "Refusing to build: vendor-prefixed/ is missing. Run php bin/build-engine.php (or pass --no-engine).\n" );
		exit( 1 );
	}
	if ( file_exists( $qpucg_engine . '/BUILD-OK' ) ) {
		fwrite( STDERR, "Refusing to build: legacy vendor-prefixed/BUILD-OK marker must not exist inside vendor-prefixed/. Run php bin/build-engine.php.\n" );
		exit( 1 );
	}
	if ( ! is_file( $qpucg_marker ) ) {
		fwrite( STDERR, "Refusing to build: build/engine-build-ok.txt is missing, so the engine build did not finish its checks. Run php bin/build-engine.php.\n" );
		exit( 1 );
	}
	$qpucg_lines = array();
	foreach ( (array) file( $qpucg_marker, FILE_IGNORE_NEW_LINES ) as $qpucg_line ) {
		$qpucg_parts = explode( ' ', (string) $qpucg_line, 2 );
		if ( 2 === count( $qpucg_parts ) ) {
			$qpucg_lines[ $qpucg_parts[0] ] = $qpucg_parts[1];
		}
	}
	$qpucg_inputs = qpucg_engine_inputs_hash( $qpucg_root );
	if ( ! isset( $qpucg_lines['inputs'] ) || $qpucg_lines['inputs'] !== $qpucg_inputs ) {
		fwrite( STDERR, "Refusing to build: vendor-prefixed/ was built from other inputs (engine/composer.*, scoper.inc.php or bin/build-engine.php changed since). Run php bin/build-engine.php.\n" );
		exit( 1 );
	}
	$qpucg_count = qpucg_engine_file_count( $qpucg_engine );
	if ( ! isset( $qpucg_lines['files'] ) || (int) $qpucg_lines['files'] !== $qpucg_count ) {
		fwrite( STDERR, 'Refusing to build: vendor-prefixed/ has ' . $qpucg_count . ' files, the build wrote ' . ( isset( $qpucg_lines['files'] ) ? (int) $qpucg_lines['files'] : 0 ) . ". Run php bin/build-engine.php.\n" );
		exit( 1 );
	}
	$qpucg_dirs[] = 'vendor-prefixed';
	echo 'Engine: build/engine-build-ok.txt OK (' . $qpucg_count . ' files, built ' . ( isset( $qpucg_lines['built'] ) ? $qpucg_lines['built'] : '?' ) . ").\n";
}

$qpucg_dist = $qpucg_root . '/dist';
if ( ! is_dir( $qpucg_dist ) && ! mkdir( $qpucg_dist, 0755, true ) ) {
	fwrite( STDERR, "Cannot create dist/.\n" );
	exit( 1 );
}
$qpucg_zip_path = $qpucg_dist . '/qasim-php-upgrade-check-guard.zip';
if ( file_exists( $qpucg_zip_path ) && ! unlink( $qpucg_zip_path ) ) {
	fwrite( STDERR, "Cannot delete the old {$qpucg_zip_path}.\n" );
	exit( 1 );
}

$qpucg_zip = new ZipArchive();
if ( true !== $qpucg_zip->open( $qpucg_zip_path, ZipArchive::CREATE ) ) {
	fwrite( STDERR, "Cannot open {$qpucg_zip_path}.\n" );
	exit( 1 );
}

$qpucg_count   = 0;
$qpucg_skipped = array();
// Developer-only code (DECISIONS D64): the Developer tab's screens (without them Page::is_dev() is false even with
// QPUCG_DEV) and the loopback `simulate-traffic` command, the plugin's only HTTP request.
$qpucg_dev_only = array( 'src/Admin/Report.php', 'src/Cli/DevCommand.php' );
foreach ( $qpucg_dev_only as $qpucg_file ) {
	if ( ! is_file( $qpucg_root . '/' . $qpucg_file ) ) {
		fwrite( STDERR, "Refusing to build: the dev-only file {$qpucg_file} to leave out is missing (renamed?).\n" );
		exit( 1 );
	}
}

foreach ( $qpucg_files as $qpucg_file ) {
	if ( ! is_file( $qpucg_root . '/' . $qpucg_file ) ) {
		$qpucg_skipped[] = $qpucg_file;
		continue;
	}
	$qpucg_zip->addFile( $qpucg_root . '/' . $qpucg_file, 'qasim-php-upgrade-check-guard/' . $qpucg_file );
	++$qpucg_count;
}

foreach ( $qpucg_dirs as $qpucg_dir ) {
	$qpucg_base = $qpucg_root . '/' . $qpucg_dir;
	if ( ! is_dir( $qpucg_base ) ) {
		$qpucg_skipped[] = $qpucg_dir . '/';
		continue;
	}
	$qpucg_iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $qpucg_base, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::LEAVES_ONLY
	);
	foreach ( $qpucg_iterator as $qpucg_item ) {
		if ( ! $qpucg_item->isFile() ) {
			continue;
		}
		$qpucg_relative = $qpucg_dir . '/' . str_replace( '\\', '/', substr( $qpucg_item->getPathname(), strlen( $qpucg_base ) + 1 ) );
		// Skip dotfiles and anything inside dot-directories.
		if ( preg_match( '#(^|/)\.#', $qpucg_relative ) || in_array( $qpucg_relative, $qpucg_dev_only, true ) ) {
			continue;
		}
		if ( 'vendor-prefixed' === $qpucg_dir && preg_match( '#(/(BUILD-OK|composer\.json|installed\.json)$|\.md$|/(tests|Tests|Test|docs|Docs|bin|scripts)/)#', '/' . $qpucg_relative ) ) {
			fwrite( STDERR, "Refusing to build: unexpected artifact in vendor-prefixed/: {$qpucg_relative}\n" );
			exit( 1 );
		}
		$qpucg_zip->addFile( $qpucg_item->getPathname(), 'qasim-php-upgrade-check-guard/' . $qpucg_relative );
		++$qpucg_count;
	}
}

foreach ( array( 'qasim-php-upgrade-check-guard/vendor-prefixed/BUILD-OK', 'qasim-php-upgrade-check-guard/build.txt' ) as $qpucg_forbidden ) {
	if ( false !== $qpucg_zip->locateName( $qpucg_forbidden ) ) {
		fwrite( STDERR, "Refusing to build: forbidden file present in zip: {$qpucg_forbidden}\n" );
		exit( 1 );
	}
}

if ( ! $qpucg_zip->close() ) {
	fwrite( STDERR, "Failed to write {$qpucg_zip_path}.\n" );
	exit( 1 );
}

echo 'Built ' . $qpucg_zip_path . ': ' . $qpucg_count . ' files, ' . number_format( filesize( $qpucg_zip_path ) / 1024, 1 ) . " KB\n";
if ( $qpucg_skipped ) {
	echo 'Not present (skipped): ' . implode( ', ', $qpucg_skipped ) . "\n";
}
