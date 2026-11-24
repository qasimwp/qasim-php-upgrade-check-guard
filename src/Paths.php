<?php
/**
 * Path normalization, path keys and component detection (spec §7.1).
 *
 * The single source of truth for file identity: the usage recorder and the
 * scanner must produce the same key for the same file.
 *
 * @package QasimPHPUpgradeCheckGuard
 */

namespace Qpucg;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * File identity helpers.
 */
class Paths {

	/**
	 * Bases, longest first. Each: array( 'name', 'dir', 'dir_cmp', 'real', 'real_cmp' ).
	 * `dir`/`real` keep case (display), `*_cmp` are the comparison forms; all end with '/'.
	 *
	 * @var array|null
	 */
	private static $bases = null;

	/**
	 * Cached own component id.
	 *
	 * @var string|null
	 */
	private static $own = null;

	/**
	 * Cached drop-in file names.
	 *
	 * @var array|null
	 */
	private static $dropins = null;

	/**
	 * Normalized comparison form: forward slashes, lowercased on Windows.
	 *
	 * @param string $abs Absolute path.
	 * @return string
	 */
	public static function normalize( $abs ) {
		$path = wp_normalize_path( (string) $abs );
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$path = strtolower( $path );
		}
		return $path;
	}

	/**
	 * Path key, e.g. `plugins:woocommerce/woocommerce.php`, or null outside all bases.
	 * On Windows the key is lowercased (it is the identity used for hashing).
	 *
	 * @param string $abs Absolute path.
	 * @return string|null
	 */
	public static function key( $abs ) {
		$match = self::match( $abs );
		return null === $match ? null : $match[0] . ':' . $match[2];
	}

	/**
	 * Same as key() but with the original case kept. For display only.
	 *
	 * @param string $abs Absolute path.
	 * @return string|null
	 */
	public static function display_key( $abs ) {
		$match = self::match( $abs );
		return null === $match ? null : $match[0] . ':' . $match[1];
	}

	/**
	 * Hash of a path key.
	 *
	 * @param string $key Path key.
	 * @return string
	 */
	public static function hash( $key ) {
		return md5( $key );
	}

	/**
	 * Component id for a path key.
	 *
	 * @param string $key Path key.
	 * @return string
	 */
	public static function component( $key ) {
		$pos = strpos( (string) $key, ':' );
		if ( false === $pos ) {
			return 'other:content';
		}
		$base     = substr( $key, 0, $pos );
		$relative = substr( $key, $pos + 1 );
		$slash    = strpos( $relative, '/' );
		$first    = false === $slash ? $relative : substr( $relative, 0, $slash );

		switch ( $base ) {
			case 'plugins':
				return 'plugin:' . $first;
			case 'mu-plugins':
				return 'mu-plugins';
			case 'themes':
				return false === $slash ? 'other:content' : 'theme:' . $first;
			case 'content':
				if ( false === $slash && '.php' === substr( $relative, -4 ) && array_key_exists( $relative, self::dropins() ) ) {
					return 'dropin:' . substr( $relative, 0, -4 );
				}
				return 'other:content';
		}
		return 'other:content';
	}

	/**
	 * Whether a path key belongs to this plugin (excluded from usage and scans).
	 *
	 * @param string|null $key Path key.
	 * @return bool
	 */
	public static function is_own( $key ) {
		if ( null === $key ) {
			return false;
		}
		if ( null === self::$own ) {
			$own_key   = self::key( QPUCG_FILE );
			self::$own = null === $own_key ? '' : self::component( $own_key );
		}
		return '' !== self::$own && self::component( $key ) === self::$own;
	}

	/**
	 * Comparison-form prefixes of all bases and their real paths, each ending with '/'.
	 * Used by the usage recorder's hot-path filter (DECISIONS D13, D19).
	 *
	 * @return array prefix => base name (mu-plugins, plugins, themes, content).
	 */
	public static function base_prefixes() {
		$out = array();
		foreach ( self::bases() as $base ) {
			foreach ( array( 'dir_cmp', 'real_cmp' ) as $field ) {
				if ( '' !== $base[ $field ] && ! isset( $out[ $base[ $field ] ] ) ) {
					$out[ $base[ $field ] ] = $base['name'];
				}
			}
		}
		return $out;
	}

	/**
	 * Absolute path for a path key: the first base with that name whose dir + relative path is a file.
	 * Keys are lowercased on Windows, which its case-insensitive file system accepts.
	 *
	 * @param string $key Path key.
	 * @return string|null Absolute path (forward slashes), or null when no such file exists.
	 */
	public static function resolve( $key ) {
		$pos = strpos( (string) $key, ':' );
		if ( false === $pos ) {
			return null;
		}
		$name     = substr( $key, 0, $pos );
		$relative = substr( $key, $pos + 1 );
		if ( '' === $relative || false !== strpos( '/' . $relative . '/', '/../' ) ) {
			return null;
		}
		foreach ( self::bases() as $base ) {
			if ( $base['name'] === $name && is_file( $base['dir'] . $relative ) ) {
				return $base['dir'] . $relative;
			}
		}
		return null;
	}

	/**
	 * Clears cached bases (for tests).
	 */
	public static function reset() {
		self::$bases   = null;
		self::$own     = null;
		self::$dropins = null;
	}

	/**
	 * Finds the base for a file.
	 *
	 * @param string $abs Absolute path.
	 * @return array|null array( base name, relative with case, relative comparison form ).
	 */
	private static function match( $abs ) {
		$display = wp_normalize_path( (string) $abs );
		$found   = self::match_normalized( $display );
		if ( null !== $found ) {
			return $found;
		}

		// Symlinks: compare the file's real path too.
		$real = self::safe_realpath( (string) $abs );
		if ( '' !== $real ) {
			$real = wp_normalize_path( $real );
			if ( $real !== $display ) {
				return self::match_normalized( $real );
			}
		}
		return null;
	}

	/**
	 * Matches an already normalized (case kept) path against base dirs and their real paths.
	 *
	 * @param string $display Normalized path, case kept.
	 * @return array|null
	 */
	private static function match_normalized( $display ) {
		$cmp = '\\' === DIRECTORY_SEPARATOR ? strtolower( $display ) : $display;

		foreach ( array( 'dir', 'real' ) as $field ) {
			foreach ( self::bases() as $base ) {
				$prefix = $base[ $field . '_cmp' ];
				if ( '' === $prefix ) {
					continue;
				}
				$len = strlen( $prefix );
				if ( strlen( $cmp ) > $len && 0 === strncmp( $cmp, $prefix, $len ) ) {
					return array( $base['name'], substr( $display, $len ), substr( $cmp, $len ) );
				}
			}
		}
		return null;
	}

	/**
	 * Plugins directory for filesystem scanning of all plugins.
	 *
	 * WordPress has no function that returns this path for arbitrary scans of every
	 * installed plugin (plugin_dir_path() is per-file). WP_PLUGIN_DIR is the documented
	 * location WordPress itself uses.
	 *
	 * @return string
	 */
	public static function plugins_dir() {
		return WP_PLUGIN_DIR;
	}

	/**
	 * Must-use plugins directory for filesystem scanning.
	 *
	 * There is no WordPress function equivalent for scanning this directory.
	 *
	 * @return string
	 */
	public static function mu_plugins_dir() {
		return WPMU_PLUGIN_DIR;
	}

	/**
	 * Content directory (drop-ins and content-relative paths).
	 *
	 * There is no WordPress function that returns WP_CONTENT_DIR for filesystem scanning.
	 *
	 * @return string
	 */
	public static function content_dir() {
		return WP_CONTENT_DIR;
	}

	/**
	 * Resolves a path with realpath() after basic input validation (no @ error suppression).
	 *
	 * @param string $path File or directory path.
	 * @return string Resolved path, or '' when it cannot be resolved.
	 */
	public static function safe_realpath( $path ) {
		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}
		$real = realpath( $path );
		return false === $real ? '' : $real;
	}

	/**
	 * Site root prefixes for redacting absolute paths in stored error samples (display-only).
	 *
	 * @return array List of array( path, replacement ), wp-content first.
	 */
	public static function scrub_roots() {
		$content = self::content_dir();
		$abspath = wp_normalize_path( ABSPATH );
		return array(
			array( $content, 'wp-content/' ),
			array( self::safe_realpath( $content ), 'wp-content/' ),
			array( $abspath, '' ),
			array( self::safe_realpath( $abspath ), '' ),
		);
	}

	/**
	 * Resolves a CLI file argument (absolute, relative to cwd, or relative to WordPress root).
	 *
	 * @param string $arg Raw path argument.
	 * @return string Resolved real path, or '' when not found.
	 */
	public static function resolve_cli_file( $arg ) {
		$arg = (string) $arg;
		if ( '' === $arg ) {
			return '';
		}
		if ( path_is_absolute( $arg ) ) {
			$candidates = array( $arg );
		} else {
			$candidates = array();
			$cwd        = getcwd();
			if ( is_string( $cwd ) && '' !== $cwd ) {
				$candidates[] = trailingslashit( wp_normalize_path( $cwd ) ) . ltrim( wp_normalize_path( $arg ), '/' );
			}
			$candidates[] = trailingslashit( wp_normalize_path( ABSPATH ) ) . ltrim( wp_normalize_path( $arg ), '/' );
		}
		foreach ( $candidates as $candidate ) {
			if ( is_file( $candidate ) ) {
				$real = self::safe_realpath( $candidate );
				if ( '' !== $real ) {
					return $real;
				}
			}
		}
		return '';
	}

	/**
	 * Builds and caches the bases, longest first.
	 *
	 * @return array
	 */
	private static function bases() {
		if ( null !== self::$bases ) {
			return self::$bases;
		}

		$dirs = array(
			array( 'mu-plugins', self::mu_plugins_dir() ),
			array( 'plugins', self::plugins_dir() ),
		);
		foreach ( self::theme_dirs() as $theme_dir ) {
			$dirs[] = array( 'themes', $theme_dir );
		}
		$dirs[] = array( 'content', self::content_dir() );

		$bases = array();
		foreach ( $dirs as $pair ) {
			$dir     = trailingslashit( wp_normalize_path( $pair[1] ) );
			$real    = self::safe_realpath( $pair[1] );
			$real    = '' === $real ? '' : trailingslashit( wp_normalize_path( $real ) );
			$real    = $real === $dir ? '' : $real;
			$bases[] = array(
				'name'     => $pair[0],
				'dir'      => $dir,
				'dir_cmp'  => self::lower( $dir ),
				'real'     => $real,
				'real_cmp' => self::lower( $real ),
			);
		}

		usort(
			$bases,
			function ( $a, $b ) {
				return strlen( $b['dir_cmp'] ) - strlen( $a['dir_cmp'] );
			}
		);

		self::$bases = $bases;
		return $bases;
	}

	/**
	 * Absolute theme root directories, including custom roots from register_theme_directory().
	 *
	 * Uses the registered $wp_theme_directories list (same source WordPress uses internally).
	 * Falls back to get_theme_root() when that list is empty. Cached with the rest of Paths::$bases.
	 *
	 * @return string[]
	 */
	private static function theme_dirs() {
		global $wp_theme_directories;

		$out = array();
		if ( is_array( $wp_theme_directories ) ) {
			foreach ( $wp_theme_directories as $dir ) {
				if ( is_string( $dir ) && '' !== $dir ) {
					$out[] = $dir;
				}
			}
		}
		if ( empty( $out ) && function_exists( 'get_theme_root' ) ) {
			$root = get_theme_root();
			if ( is_string( $root ) && '' !== $root ) {
				$out[] = $root;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Known WordPress core drop-in file names. Uses _get_dropins() when already loaded in wp-admin,
	 * and falls back to the documented core drop-in list otherwise (avoiding loading wp-admin files on front-end requests).
	 *
	 * @return array
	 */
	private static function dropins() {
		if ( null === self::$dropins ) {
			if ( function_exists( '_get_dropins' ) ) {
				self::$dropins = _get_dropins();
			} else {
				self::$dropins = array(
					'advanced-cache.php'      => true,
					'db.php'                  => true,
					'db-error.php'            => true,
					'install.php'             => true,
					'maintenance.php'         => true,
					'object-cache.php'        => true,
					'php-error.php'           => true,
					'fatal-error-handler.php' => true,
					'sunrise.php'             => true,
					'blog-deleted.php'        => true,
					'blog-inactive.php'       => true,
					'blog-suspended.php'      => true,
				);
			}
		}
		return self::$dropins;
	}

	/**
	 * Lowercases on Windows only.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function lower( $path ) {
		return '\\' === DIRECTORY_SEPARATOR ? strtolower( $path ) : $path;
	}
}
