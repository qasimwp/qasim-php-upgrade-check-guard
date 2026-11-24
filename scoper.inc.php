<?php
/**
 * PHP-Scoper config for the engine (spec §7.3). Dev only, not shipped.
 *
 * Input: engine/vendor (installed by bin/build-engine.php) + engine/composer.json.
 * Output: build/engine-scoped/ with everything under the prefix Qpucg\Vendor.
 * Every patcher is documented in docs/DECISIONS.md.
 *
 * @package QasimPHPUpgradeCheckGuard
 */

// phpcs:ignoreFile -- dev build config (PHP 8.2 only, runs inside php-scoper.phar), not runtime code.

use Isolated\Symfony\Component\Finder\Finder;

$engine = __DIR__ . '/engine';

return array(
	'prefix'            => 'Qpucg\\Vendor',

	'finders'           => array(
		Finder::create()
			->files()
			->ignoreVCS( true )
			->ignoreDotFiles( false )
			->exclude( array( 'tests', 'Tests', 'docs', '.github' ) )
			// Composer installer plugin (required by PHPCSUtils): build-time only, and
			// PHP-Scoper leaves its namespace unprefixed (DECISIONS D21).
			->notPath( '#^dealerdirect/#' )
			// PHPCSUtils test-helper exceptions: unused at runtime (PHPCSUtils/TestUtils is stripped).
			->notPath( '#^phpcsstandards/phpcsutils/PHPCSUtils/Exceptions/Test(File|Marker|Target)NotFound\.php$#' )
			->in( $engine . '/vendor' ),
		Finder::create()
			->files()
			->depth( 0 )
			->name( 'composer.json' )
			->in( $engine ),
	),

	// PHPCS defines its own global T_* token constants (Util/Tokens.php) and reads
	// PHP_CODESNIFFER_* constants that we define before loading it. PHP's own T_*
	// tokenizer constants must stay global too.
	'exclude-constants' => array(
		'/^T_/',
		'/^PHP_CODESNIFFER_/',
	),

	// Composer's ClassLoader is regenerated unprefixed by `composer dump-autoload` in step 3,
	// so PHPCS's `instanceof ClassLoader` check in autoload.php must see the real name.
	// IMAP and PSpell: PHP's own classes, named as array keys in PHPCompatibility's RemovedClasses table. Prefixed,
	// they would never match real code (DECISIONS D55).
	'exclude-namespaces' => array(
		'Composer',
		'IMAP',
		'PSpell',
	),

	'patchers'          => array(
		// DECISIONS D21. PHPCS's own autoloader matches class names by fixed-length
		// string checks and maps external standards by their unprefixed namespace.
		static function ( string $file_path, string $prefix, string $contents ): string {
			if ( ! preg_match( '#/squizlabs/php_codesniffer/autoload\.php$#', str_replace( '\\', '/', $file_path ) ) ) {
				return $contents;
			}
			$own   = $prefix . '\\PHP_CodeSniffer\\';
			$tests = $own . 'Tests\\';
			$map   = array(
				// Own classes: Qpucg\Vendor\PHP_CodeSniffer\X → src/X.php.
				"substr(\$className, 0, 16) === 'PHP_CodeSniffer\\\\'"           => 'substr($className, 0, ' . strlen( $own ) . ") === '" . str_replace( '\\', '\\\\', $own ) . "'",
				"substr(\$className, 0, 22) === 'PHP_CodeSniffer\\Tests\\\\'"    => 'substr($className, 0, ' . strlen( $tests ) . ") === '" . str_replace( '\\', '\\\\', $tests ) . "'",
				"substr(str_replace('\\\\', \$ds, \$className), 22)"             => "substr(str_replace('\\\\', \$ds, \$className), " . strlen( $tests ) . ')',
				"substr(str_replace('\\\\', \$ds, \$className), 16)"             => "substr(str_replace('\\\\', \$ds, \$className), " . strlen( $own ) . ')',
				// External standards (PHPCompatibility*): drop our prefix before matching the
				// standard's namespace, so Qpucg\Vendor\PHPCompatibility\X → <standard>/X.php.
				'$className = $className;'                                       => "\$className = strpos(\$className, '" . str_replace( '\\', '\\\\', $prefix ) . "\\\\') === 0 ? substr(\$className, " . ( strlen( $prefix ) + 1 ) . ') : $className;',
			);
			foreach ( $map as $search => $replace ) {
				if ( 1 !== substr_count( $contents, $search ) ) {
					throw new RuntimeException( 'Scoper patcher: expected exactly one "' . $search . '" in ' . $file_path );
				}
				$contents = str_replace( $search, $replace, $contents );
			}
			return $contents;
		},

		// DECISIONS D28. Removes the shell calls PHPCS still has in files we load (Config, Common, Fixer).
		// Each patch is a list of source lines (any whitespace between them) that must match exactly once.
		// The other callers (blame/notify reports, Git filters, Diff report) are deleted by bin/build-engine.php.
		static function ( string $file_path, string $prefix, string $contents ): string {
			$patches = array(
				'#/squizlabs/php_codesniffer/src/Util/Common\.php$#' => array(
					// isStdinATTY(), non-Windows CLI without posix: fall through to the fstat() check.
					array(
						array(
							'$tty = trim(shell_exec("{$which} tty 2> {$devnull}"));',
							'if (empty($tty) === \false) {',
							'exec("tty -s 2> {$devnull}", $output, $returnValue);',
							'$isTTY = $returnValue === 0;',
							'return $isTTY;',
							'}',
						),
						'// Qpucg (DECISIONS D28): no "which tty" / "tty -s" shell calls; fstat() below decides.',
					),
				),
				'#/squizlabs/php_codesniffer/src/Config\.php$#'      => array(
					// __get( 'reportWidth' ) with "auto": use the default width.
					array(
						array(
							"if (function_exists('shell_exec') === \\true) {",
							"\$dimensions = shell_exec('stty size 2>&1');",
							"if (is_string(\$dimensions) === \\true && preg_match('|\\d+ (\\d+)|', \$dimensions, \$matches) === 1) {",
							'$this->settings[$name] = (int) $matches[1];',
							'}',
							'}',
						),
						'// Qpucg (DECISIONS D28): no "stty size" shell call; the default width below applies.',
					),
					// getExecutablePath(): never look a tool up on the PATH.
					array(
						array( '$result = exec($cmd, $output, $retVal);' ),
						'$result = null; $retVal = 1; // Qpucg (DECISIONS D28): no "which" / "where" shell call.',
					),
					// processShortArgument( 'd' ): unreachable (-d is never passed), remove ini_set() for WP.org compliance.
					array(
						array( '$changed = ini_set($ini[0], $ini[1]);' ),
						'$changed = \false; // Qpucg (DECISIONS D69): -d CLI flag is unused; no ini_set() call.',
					),
				),
				'#/squizlabs/php_codesniffer/src/Runner\.php$#'       => array(
					// init(): PHPCS disables pcre.jit for parallel mode only; this plugin runs single-process and must not alter global ini.
					array(
						array( "ini_set('pcre.jit', \\false);" ),
						'// Qpucg (DECISIONS D69): keep host pcre.jit unchanged; plugin never uses PHPCS parallel mode.',
					),
					// handleErrors(): unreachable (Engine installs its own handler); remove error_reporting() call.
					array(
						array(
							'if ((error_reporting() & $code) === 0) {',
							'// This type of error is being muted.',
							'return \true;',
							'}',
						),
						"if (\\E_USER_ERROR !== \$code && \\E_RECOVERABLE_ERROR !== \$code) {\n            // Qpucg (DECISIONS D69): unreachable (Engine uses its own error handler); no error_reporting() call.\n            return \\true;\n        }",
					),
				),
				'#/squizlabs/php_codesniffer/src/Tokenizers/Tokenizer\.php$#' => array(
					// createPositionMap(): absorb iconv_strlen() invalid-byte warnings once per file without error_reporting().
					array(
						array(
							'$tabWidth = $this->config->tabWidth;',
							'$this->numTokens = count($this->tokens);',
							'for ($i = 0; $i < $this->numTokens; $i++) {',
						),
						"\$tabWidth = \$this->config->tabWidth;\n        \$this->numTokens = count(\$this->tokens);\n        // Qpucg (DECISIONS D69): absorb iconv_strlen() invalid-byte warnings once per file without error_reporting().\n        if (\$checkEncoding === \\true) {\n            set_error_handler(static function () {\n                return \\true;\n            });\n        }\n        try {\n            for (\$i = 0; \$i < \$this->numTokens; \$i++) {",
					),
					array(
						array(
							'$oldLevel = error_reporting();',
							'error_reporting(0);',
							'$length = iconv_strlen($this->tokens[$i][\'content\'], $encoding);',
							'error_reporting($oldLevel);',
						),
						"// Qpucg (DECISIONS D69): warnings absorbed by loop-level handler above; no error_reporting().\n                    \$length = iconv_strlen(\$this->tokens[\$i]['content'], \$encoding);",
					),
					array(
						array(
							'if ($ignoring !== null && isset($this->ignoredLines[$this->tokens[$i][\'line\']]) === \false) {',
							'$this->ignoredLines[$this->tokens[$i][\'line\']] = $ignoring;',
							'}',
							'}',
							'// If annotations are being ignored, we clear out all the ignore rules',
							'// but leave the annotations tokenized as normal.',
							'if ($checkAnnotations === \false) {',
						),
						"if (\$ignoring !== null && isset(\$this->ignoredLines[\$this->tokens[\$i]['line']]) === \\false) {\n                \$this->ignoredLines[\$this->tokens[\$i]['line']] = \$ignoring;\n            }\n        }\n        } finally {\n            if (\$checkEncoding === \\true) {\n                restore_error_handler();\n            }\n        }\n        // If annotations are being ignored, we clear out all the ignore rules\n        // but leave the annotations tokenized as normal.\n        if (\$checkAnnotations === \\false) {",
					),
					// replaceTabsInToken(): unreachable with tabWidth === 0; still use scoped handler instead of error_reporting().
					array(
						array(
							'$oldLevel = error_reporting();',
							'error_reporting(0);',
							'$contentLength = iconv_strlen($content, $this->config->encoding);',
							'error_reporting($oldLevel);',
						),
						"// Qpucg (DECISIONS D69): scoped handler instead of error_reporting().\n                        set_error_handler(static function () {\n                            return \\true;\n                        });\n                        try {\n                            \$contentLength = iconv_strlen(\$content, \$this->config->encoding);\n                        } finally {\n                            restore_error_handler();\n                        }",
					),
				),
				'#/squizlabs/php_codesniffer/src/Fixer\.php$#'       => array(
					// generateDiff() (diff report only): refuse before the temp file and the diff process.
					array(
						array( "\$tempName = tempnam(sys_get_temp_dir(), 'phpcs-fixer');" ),
						"throw new RuntimeException('Qpucg (DECISIONS D28): diff output is not available in this build.');",
					),
					array(
						array( '$process = proc_open($cmd, $descriptorspec, $pipes, $cwd, null, $options);' ),
						'$process = \false;',
					),
				),
				// DECISIONS D43: no eval() of scanned code.
				'#/PHPCompatibility/Sniffs/ControlStructures/DiscouragedSwitchContinueSniff\.php$#' => array(
					// A non-numeric `continue <expr>` level is unknown: skip that continue statement.
					array(
						array( '$level = eval("return ( {$codeString} );");' ),
						'continue; // Qpucg (DECISIONS D43): non-numeric level = unknown, no eval().',
					),
				),
				'#/PHPCSUtils/AbstractSniffs/AbstractArrayDeclarationSniff\.php$#' => array(
					// getActualArrayKey() (no sniff we load uses it): the key can't be determined.
					array(
						array( "\$key = @eval('return ' . \$content . ';' . \\PHP_EOL);" ),
						'return; // Qpucg (DECISIONS D43): no eval().',
					),
				),
			);
			$file = str_replace( '\\', '/', $file_path );
			foreach ( $patches as $file_pattern => $list ) {
				if ( ! preg_match( $file_pattern, $file ) ) {
					continue;
				}
				foreach ( $list as $patch ) {
					$regex    = '#' . implode( '\s*', array_map( static function ( $line ) {
						return preg_quote( $line, '#' );
					}, $patch[0] ) ) . '#';
					$replace  = $patch[1];
					$contents = preg_replace_callback( $regex, static function () use ( $replace ) {
						return $replace;
					}, $contents, -1, $count );
					if ( 1 !== $count ) {
						throw new RuntimeException( 'Scoper patcher: expected exactly one match for "' . $patch[0][0] . '" in ' . $file_path . ', got ' . (int) $count );
					}
				}
			}
			return $contents;
		},
	),
);
