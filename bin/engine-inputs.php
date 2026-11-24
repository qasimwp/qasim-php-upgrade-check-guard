<?php
/**
 * Shared by bin/build-engine.php and bin/build-zip.php (dev scripts, not shipped): the engine's build inputs and
 * the gitignored build/engine-build-ok.txt marker (DECISIONS D47).
 *
 * @package QasimPHPUpgradeCheckGuard
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- CLI dev script, no WordPress loaded.

/**
 * Marker file path inside gitignored build/, written only after every build check passed.
 *
 * @param string $root Repo root.
 * @return string
 */
function qpucg_engine_marker_path( $root ) {
	return $root . '/build/engine-build-ok.txt';
}

/**
 * The md5 over the files that decide what the engine build produces (line endings normalized, so a Git checkout
 * with other line endings doesn't count as a change).
 *
 * @param string $root Repo root.
 * @return string
 */
function qpucg_engine_inputs_hash( $root ) {
	$data = '';
	foreach ( array( 'engine/composer.json', 'engine/composer.lock', 'scoper.inc.php', 'bin/build-engine.php' ) as $file ) {
		$data .= $file . "\n" . str_replace( "\r\n", "\n", (string) @file_get_contents( $root . '/' . $file ) ) . "\n"; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file hashes as empty.
	}
	return md5( $data );
}

/**
 * Number of files in a directory tree.
 *
 * @param string $path Directory.
 * @return int
 */
function qpucg_engine_file_count( $path ) {
	$files = 0;
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) ) as $item ) {
		if ( $item->isFile() ) {
			++$files;
		}
	}
	return $files;
}
