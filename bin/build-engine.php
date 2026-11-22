<?php
/**
 * Builds vendor-prefixed/ (the scoped PHPCS engine). Dev script, not shipped. Usage: php bin/build-engine.php
 *
 * Steps (spec §7.3):
 * 1. composer install --no-dev --prefer-dist --working-dir=engine
 * 2. PHP-Scoper (tools/php-scoper.phar, scoper.inc.php): engine/ → build/engine-scoped/, prefix Qpucg\Vendor
 * 3. composer dump-autoload --classmap-authoritative --no-dev --working-dir=build/engine-scoped
 * 4. Copy build/engine-scoped/vendor → build/vendor-prefixed.tmp/
 * 5. Strip unused PHPCS standards, tests, docs, bin, *.md (except licenses), phpunit*, .github, *.dist, CodeSniffer.conf*;
 *    check that no exec-family call / backtick / eval() is left and that every patch landed
 * 6. Write the BUILD-OK marker, swap the temp folder in as vendor-prefixed/, print size and package versions
 * vendor-prefixed/ is replaced only when every step passed (DECISIONS D47); a failed build leaves it as it was.
 *
 * @package QasimPHPUpgradeCheckGuard
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DiscouragedPHPFunctions -- CLI dev script, no WordPress loaded.

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

require __DIR__ . '/engine-inputs.php';

if ( PHP_VERSION_ID < 80200 ) {
	fwrite( STDERR, 'PHP-Scoper needs PHP 8.2+, this is ' . PHP_VERSION . ".\n" );
	exit( 1 );
}

$qpucg_root   = dirname( __DIR__ );
$qpucg_scoper = $qpucg_root . '/tools/php-scoper.phar';
$qpucg_scoped = $qpucg_root . '/build/engine-scoped';
$qpucg_final  = $qpucg_root . '/vendor-prefixed';
$qpucg_target = $qpucg_root . '/build/vendor-prefixed.tmp';
$qpucg_old    = $qpucg_root . '/build/vendor-prefixed.old';

if ( ! is_file( $qpucg_scoper ) ) {
	fwrite( STDERR, "Missing tools/php-scoper.phar.\n" );
	exit( 1 );
}

/**
 * Runs a shell command and stops the build when it fails.
 *
 * @param string $label   Step label.
 * @param string $command Command line.
 */
function qpucg_build_run( $label, $command ) {
	echo "\n== {$label}\n> {$command}\n";
	passthru( $command, $code );
	if ( 0 !== $code ) {
		fwrite( STDERR, "Step failed (exit {$code}): {$label}\n" );
		exit( 1 );
	}
}

/**
 * Stops the build.
 *
 * @param string $message Reason.
 */
function qpucg_build_fail( $message ) {
	fwrite( STDERR, $message . "\nBuild FAILED; vendor-prefixed/ was not changed.\n" );
	exit( 1 );
}

/**
 * Deletes a file or a directory tree; a failed delete (e.g. a file locked on Windows) stops the build.
 *
 * @param string $path Path.
 */
function qpucg_build_delete( $path ) {
	if ( is_link( $path ) || is_file( $path ) ) {
		if ( ! unlink( $path ) ) {
			qpucg_build_fail( "Cannot delete {$path}" );
		}
		return;
	}
	if ( ! is_dir( $path ) ) {
		return;
	}
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $items as $item ) {
		$ok = $item->isDir() && ! $item->isLink() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		if ( ! $ok ) {
			qpucg_build_fail( 'Cannot delete ' . $item->getPathname() );
		}
	}
	if ( ! rmdir( $path ) ) {
		qpucg_build_fail( "Cannot delete {$path}" );
	}
}

/**
 * Copies a directory tree; any failed mkdir/copy stops the build.
 *
 * @param string $from Source directory.
 * @param string $to   Destination directory (created).
 */
function qpucg_build_copy( $from, $to ) {
	if ( ! is_dir( $to ) && ! mkdir( $to, 0755, true ) ) {
		qpucg_build_fail( "Cannot create {$to}" );
	}
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $from, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);
	foreach ( $items as $item ) {
		$dest = $to . '/' . str_replace( '\\', '/', substr( $item->getPathname(), strlen( $from ) + 1 ) );
		if ( $item->isDir() ) {
			if ( ! is_dir( $dest ) && ! mkdir( $dest, 0755, true ) ) {
				qpucg_build_fail( "Cannot create {$dest}" );
			}
		} elseif ( ! copy( $item->getPathname(), $dest ) ) {
			qpucg_build_fail( "Cannot copy {$dest}" );
		}
	}
}

/**
 * Total size of a directory tree in bytes, and its file count.
 *
 * @param string $path Directory.
 * @return array{0:int,1:int}
 */
function qpucg_build_size( $path ) {
	$bytes = 0;
	$files = 0;
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) ) as $item ) {
		if ( $item->isFile() ) {
			$bytes += $item->getSize();
			++$files;
		}
	}
	return array( $bytes, $files );
}

$qpucg_composer = 'composer';
$qpucg_php      = escapeshellarg( PHP_BINARY );

// 1. Install the engine packages (versions pinned in engine/composer.json, DECISIONS D3).
qpucg_build_run( '1/6 composer install (engine)', $qpucg_composer . ' install --no-dev --prefer-dist --no-interaction --working-dir=' . escapeshellarg( $qpucg_root . '/engine' ) );

// 2. Scope.
qpucg_build_delete( $qpucg_scoped );
qpucg_build_run(
	'2/6 PHP-Scoper',
	$qpucg_php . ' ' . escapeshellarg( $qpucg_scoper ) . ' add-prefix --no-interaction --force'
	. ' --config=' . escapeshellarg( $qpucg_root . '/scoper.inc.php' )
	. ' --output-dir=' . escapeshellarg( $qpucg_scoped )
);

// 3. Regenerate the Composer autoloader for the scoped class names.
qpucg_build_run( '3/6 composer dump-autoload (scoped)', $qpucg_composer . ' dump-autoload --classmap-authoritative --no-dev --no-interaction --working-dir=' . escapeshellarg( $qpucg_scoped ) );

// 4. Copy into a temp folder; vendor-prefixed/ is only replaced after every check passed (step 6).
echo "\n== 4/6 copy build/engine-scoped/vendor -> build/vendor-prefixed.tmp/\n";
qpucg_build_delete( $qpucg_target );
qpucg_build_copy( $qpucg_scoped . '/vendor', $qpucg_target );

// 5. Strip.
echo "\n== 5/6 strip\n";
$qpucg_standards_dir = $qpucg_target . '/squizlabs/php_codesniffer/src/Standards';
$qpucg_referenced    = array();
foreach ( array( 'phpcompatibility', 'phpcsstandards' ) as $qpucg_vendor ) {
	$qpucg_rulesets = new RegexIterator(
		new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $qpucg_target . '/' . $qpucg_vendor, FilesystemIterator::SKIP_DOTS ) ),
		'#[\\\\/]ruleset\.xml$#'
	);
	foreach ( $qpucg_rulesets as $qpucg_ruleset ) {
		preg_match_all( '#<rule\s+ref="([A-Za-z0-9_]+)#', (string) file_get_contents( $qpucg_ruleset->getPathname() ), $qpucg_m );
		foreach ( $qpucg_m[1] as $qpucg_ref ) {
			$qpucg_referenced[ $qpucg_ref ] = true;
		}
	}
}
echo 'Standards referenced by the PHPCompatibility* / PHPCSUtils rulesets: ' . implode( ', ', array_keys( $qpucg_referenced ) ) . "\n";
$qpucg_kept = array();
foreach ( (array) glob( $qpucg_standards_dir . '/*', GLOB_ONLYDIR ) as $qpucg_standard ) {
	$qpucg_name = basename( $qpucg_standard );
	if ( isset( $qpucg_referenced[ $qpucg_name ] ) ) {
		$qpucg_kept[] = $qpucg_name;
		continue;
	}
	qpucg_build_delete( $qpucg_standard );
}
echo 'PHPCS standards kept: ' . ( $qpucg_kept ? implode( ', ', $qpucg_kept ) : '(none)' ) . "\n";

$qpucg_strip_dirs = array( 'tests', 'Tests', 'docs', 'bin', '.github' );
$qpucg_removed    = 0;
$qpucg_items      = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $qpucg_target, FilesystemIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::SELF_FIRST
);
$qpucg_to_delete  = array();
foreach ( $qpucg_items as $qpucg_item ) {
	$qpucg_base = $qpucg_item->getFilename();
	if ( preg_match( '/^(LICEN[SC]E|COPYING)/i', $qpucg_base ) ) {
		continue;
	}
	if ( $qpucg_item->isDir() ) {
		if ( in_array( $qpucg_base, $qpucg_strip_dirs, true ) ) {
			$qpucg_to_delete[] = $qpucg_item->getPathname();
		}
		continue;
	}
	if ( preg_match( '/(\.md$|^phpunit|\.dist$|^CodeSniffer\.conf|^\.)/i', $qpucg_base ) ) {
		$qpucg_to_delete[] = $qpucg_item->getPathname();
	}
}
foreach ( $qpucg_to_delete as $qpucg_path ) {
	if ( file_exists( $qpucg_path ) ) {
		qpucg_build_delete( $qpucg_path );
		++$qpucg_removed;
	}
}
echo "Removed {$qpucg_removed} files/folders.\n";

// PHPCS files we never load that run shell commands (DECISIONS D28): VCS blame and notify reports, the
// diff report, the Git filters. The remaining calls in Config, Common and Fixer are patched in scoper.inc.php.
$qpucg_unused = array(
	'src/Reports/Gitblame.php',
	'src/Reports/Hgblame.php',
	'src/Reports/Svnblame.php',
	'src/Reports/VersionControl.php',
	'src/Reports/Notifysend.php',
	'src/Reports/Diff.php',
	'src/Filters/GitModified.php',
	'src/Filters/GitStaged.php',
);
foreach ( $qpucg_unused as $qpucg_path ) {
	$qpucg_path = $qpucg_target . '/squizlabs/php_codesniffer/' . $qpucg_path;
	if ( ! is_file( $qpucg_path ) ) {
		qpucg_build_fail( "Expected PHPCS file to strip is missing (PHPCS update?): {$qpucg_path}" );
	}
	qpucg_build_delete( $qpucg_path );
}
echo 'Removed ' . count( $qpucg_unused ) . " unused PHPCS files with shell calls.\n";

// Parts the plugin never loads, because it uses PHPCS as a library (no CLI, reports, doc generator, cache file or
// Composer metadata at run time) (DECISIONS D64). Proved by tests/engine-strip-check.php: the same findings before and
// after on a wide set of plugins. Each path must exist, so an upstream move fails the build instead of shipping it.
$qpucg_strip_trees = array(
	'phpcompatibility/php-compatibility/PHPCompatibility/Docs', // XML docs for `phpcs --generator`.
	'squizlabs/php_codesniffer/src/Generators',                 // The doc generator.
	'squizlabs/php_codesniffer/src/Reports',                    // CLI reports; the engine reads errors from the File object.
	'phpcsstandards/phpcsutils/PHPCSUtils/TestUtils',           // PHPUnit helpers.
);
$qpucg_strip_files = array(
	'squizlabs/php_codesniffer/requirements.php', // CLI requirement check.
	'squizlabs/php_codesniffer/phpcs.xsd',        // Ruleset schema for editors.
	'composer/installed.json',                    // Composer metadata (InstalledVersions uses installed.php).
	'squizlabs/php_codesniffer/composer.json',
	'phpcompatibility/php-compatibility/composer.json',
	'phpcompatibility/phpcompatibility-wp/composer.json',
	'phpcompatibility/phpcompatibility-paragonie/composer.json',
	'phpcsstandards/phpcsutils/composer.json',
);
// Removed when present. Whether Composer's download of a package contains these depends on the machine and Composer
// version (an archive built with export-ignore leaves them out), so a missing one is not an error. Without them in the
// strip list a build on one machine would ship them and a build on another would not.
$qpucg_strip_optional = array(
	'phpcompatibility/phpcompatibility-paragonie/Test', // Unit tests of the standard.
	'phpcompatibility/phpcompatibility-wp/Test',        // Unit tests of the standard.
	'squizlabs/php_codesniffer/scripts',                // Release and PHAR build scripts.
	'scoper-autoload.php',                              // PHP-Scoper's own generated loader; the plugin loads autoload.php.
);
foreach ( $qpucg_strip_optional as $qpucg_path ) {
	if ( file_exists( $qpucg_target . '/' . $qpucg_path ) ) {
		qpucg_build_delete( $qpucg_target . '/' . $qpucg_path );
	}
}
foreach ( array_merge( $qpucg_strip_trees, $qpucg_strip_files ) as $qpucg_path ) {
	if ( ! file_exists( $qpucg_target . '/' . $qpucg_path ) ) {
		qpucg_build_fail( "Expected engine path to strip is missing (package update?): {$qpucg_path}" );
	}
	qpucg_build_delete( $qpucg_target . '/' . $qpucg_path );
}
echo 'Removed ' . count( $qpucg_strip_trees ) . ' unused folders and ' . count( $qpucg_strip_files ) . " unused files (docs, generators, reports, test utils, Composer metadata).\n";

// No exec-family call, backtick operator or eval() may remain (CLAUDE.md, D7, D28, D43). Token-based, so rule
// messages that only mention a function name (PHPCompatibility sniffs) don't count.
$qpucg_banned = array( 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec' );
$qpucg_found  = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $qpucg_target, FilesystemIterator::SKIP_DOTS ) ) as $qpucg_item ) {
	if ( ! preg_match( '/\.(php|inc)$/', $qpucg_item->getFilename() ) ) {
		continue;
	}
	$qpucg_tokens = array_values(
		array_filter(
			token_get_all( (string) file_get_contents( $qpucg_item->getPathname() ) ),
			function ( $token ) {
				return ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
			}
		)
	);
	foreach ( $qpucg_tokens as $qpucg_i => $qpucg_token ) {
		if ( '`' === $qpucg_token ) {
			$qpucg_found[] = $qpucg_item->getPathname() . ': backtick operator';
			continue;
		}
		// eval() of scanned code (DECISIONS D43).
		if ( is_array( $qpucg_token ) && T_EVAL === $qpucg_token[0] ) {
			$qpucg_found[] = $qpucg_item->getPathname() . ':' . $qpucg_token[2] . ': eval()';
			continue;
		}
		if ( ! is_array( $qpucg_token ) || ! in_array( $qpucg_token[0], array( T_STRING, T_NAME_FULLY_QUALIFIED ), true ) ) { // phpcs:ignore PHPCompatibility.Constants.NewConstants -- dev script, exits below PHP 8.2.
			continue;
		}
		$qpucg_next = isset( $qpucg_tokens[ $qpucg_i + 1 ] ) ? $qpucg_tokens[ $qpucg_i + 1 ] : null;
		$qpucg_prev = $qpucg_i > 0 ? $qpucg_tokens[ $qpucg_i - 1 ] : null;
		if ( '(' !== $qpucg_next || ! in_array( strtolower( ltrim( $qpucg_token[1], '\\' ) ), $qpucg_banned, true ) ) {
			continue;
		}
		if ( is_array( $qpucg_prev ) && in_array( $qpucg_prev[0], array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) { // phpcs:ignore PHPCompatibility.Constants.NewConstants -- dev script, exits below PHP 8.2.
			continue;
		}
		$qpucg_found[] = $qpucg_item->getPathname() . ':' . $qpucg_token[2] . ': ' . $qpucg_token[1] . '()';
	}
}
if ( $qpucg_found ) {
	qpucg_build_fail( "exec-family or eval() calls left in the engine:\n  " . implode( "\n  ", $qpucg_found ) );
}
echo "exec-family / eval check: 0 calls (exec, shell_exec, system, passthru, proc_open, popen, pcntl_exec, backticks, eval).\n";

// Every scoper.inc.php patch must have landed. A patcher only runs when PHP-Scoper processes a file matching its
// path pattern, so a moved or renamed upstream file would skip its patch silently (review finding #10).
$qpucg_markers = array(
	'squizlabs/php_codesniffer/src/Util/Common.php' => 1,
	'squizlabs/php_codesniffer/src/Config.php'      => 2,
	'squizlabs/php_codesniffer/src/Fixer.php'       => 1,
	'phpcompatibility/php-compatibility/PHPCompatibility/Sniffs/ControlStructures/DiscouragedSwitchContinueSniff.php' => 1,
	'phpcsstandards/phpcsutils/PHPCSUtils/AbstractSniffs/AbstractArrayDeclarationSniff.php' => 1,
);
$qpucg_total   = 0;
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $qpucg_target, FilesystemIterator::SKIP_DOTS ) ) as $qpucg_item ) {
	if ( $qpucg_item->isFile() ) {
		$qpucg_total += substr_count( (string) file_get_contents( $qpucg_item->getPathname() ), 'Qpucg (DECISIONS' );
	}
}
if ( array_sum( $qpucg_markers ) !== $qpucg_total ) {
	qpucg_build_fail( 'Patch markers: expected ' . array_sum( $qpucg_markers ) . " \"Qpucg (DECISIONS\" markers in the engine, found {$qpucg_total}." );
}
foreach ( $qpucg_markers as $qpucg_file => $qpucg_expected ) {
	$qpucg_count = substr_count( (string) @file_get_contents( $qpucg_target . '/' . $qpucg_file ), 'Qpucg (DECISIONS' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file counts 0.
	if ( $qpucg_expected !== $qpucg_count ) {
		qpucg_build_fail( "Patch markers: expected {$qpucg_expected} in {$qpucg_file}, found {$qpucg_count}." );
	}
}
// Patches without a marker comment: Fixer's proc_open line (D28) and PHPCS's autoloader (D21).
$qpucg_fixer    = (string) file_get_contents( $qpucg_target . '/squizlabs/php_codesniffer/src/Fixer.php' );
$qpucg_autoload = (string) file_get_contents( $qpucg_target . '/squizlabs/php_codesniffer/autoload.php' );
$qpucg_prefix     = 'Qpucg\\\\Vendor\\\\'; // The scoper prefix as it appears inside PHP single-quoted strings (every backslash doubled).
$qpucg_own        = $qpucg_prefix . 'PHP_CodeSniffer\\\\';
// PHPCS's autoloader compares class names with fixed-length substr() calls. The lengths are computed from the
// prefix here, never typed in, so a prefix change cannot leave them stale (the engine would then not load).
$qpucg_len_own    = strlen( str_replace( '\\\\', '\\', $qpucg_own ) );
$qpucg_len_tests  = strlen( str_replace( '\\\\', '\\', $qpucg_own . 'Tests\\\\' ) );
$qpucg_len_prefix = strlen( str_replace( '\\\\', '\\', $qpucg_prefix ) );
$qpucg_needles    = array(
	array( $qpucg_fixer, '$process = \\false;', 'Fixer.php' ),
	array( $qpucg_autoload, 'substr($className, 0, ' . $qpucg_len_own . ') === \'' . $qpucg_own . '\'', 'autoload.php' ),
	array( $qpucg_autoload, 'substr($className, 0, ' . $qpucg_len_tests . ') === \'' . $qpucg_own . 'Tests\\\\\'', 'autoload.php' ),
	array( $qpucg_autoload, '$className = strpos($className, \'' . $qpucg_prefix . '\') === 0 ? substr($className, ' . $qpucg_len_prefix . ') : $className;', 'autoload.php' ),
);
foreach ( $qpucg_needles as $qpucg_needle ) {
	if ( 1 !== substr_count( $qpucg_needle[0], $qpucg_needle[1] ) ) {
		qpucg_build_fail( "Patch check: expected exactly one \"{$qpucg_needle[1]}\" in {$qpucg_needle[2]}." );
	}
}
echo 'Patch check: ' . $qpucg_total . " markers in the 5 patched files, Fixer proc_open line and 3 PHPCS autoloader patches present.\n";

// 6. Marker, swap, report.
echo "\n== 6/6 swap + result\n";
// Open directory iterators keep a handle on the temp folder, and Windows refuses to rename it ("Access is denied").
unset( $qpucg_items, $qpucg_rulesets, $qpucg_item );
$qpucg_count = qpucg_engine_file_count( $qpucg_target );
if ( false === file_put_contents( $qpucg_target . '/' . QPUCG_BUILD_MARKER, 'inputs ' . qpucg_engine_inputs_hash( $qpucg_root ) . "\nfiles " . $qpucg_count . "\nbuilt " . gmdate( 'c' ) . "\n" ) ) {
	qpucg_build_fail( 'Cannot write the ' . QPUCG_BUILD_MARKER . ' marker.' );
}
qpucg_build_delete( $qpucg_old );
if ( is_dir( $qpucg_final ) && ! rename( $qpucg_final, $qpucg_old ) ) {
	qpucg_build_fail( 'Cannot move the old vendor-prefixed/ aside (a file in it may be open).' );
}
if ( ! rename( $qpucg_target, $qpucg_final ) ) {
	if ( is_dir( $qpucg_old ) ) {
		rename( $qpucg_old, $qpucg_final );
	}
	qpucg_build_fail( 'Cannot move build/vendor-prefixed.tmp/ to vendor-prefixed/.' );
}
$qpucg_target = $qpucg_final;
echo 'vendor-prefixed/ replaced; marker ' . QPUCG_BUILD_MARKER . " written ({$qpucg_count} files). The previous engine stays in build/vendor-prefixed.old/ until the next build.\n";
list( $qpucg_bytes, $qpucg_files ) = qpucg_build_size( $qpucg_target );
printf( "vendor-prefixed/: %d files, %.2f MB (%s bytes)%s\n", $qpucg_files, $qpucg_bytes / 1048576, number_format( $qpucg_bytes ), $qpucg_bytes < 6 * 1048576 ? '' : '  OVER the 6 MB target' );

// installed.json is stripped from the engine (step 5); the scoped build still has it.
$qpucg_installed = json_decode( (string) file_get_contents( $qpucg_scoped . '/vendor/composer/installed.json' ), true );
$qpucg_packages  = isset( $qpucg_installed['packages'] ) ? $qpucg_installed['packages'] : (array) $qpucg_installed;
foreach ( $qpucg_packages as $qpucg_package ) {
	printf( "  %-46s %s%s\n", $qpucg_package['name'], $qpucg_package['version'], is_dir( $qpucg_target . '/' . $qpucg_package['name'] ) ? '' : '  (build-time only, not shipped)' );
}
