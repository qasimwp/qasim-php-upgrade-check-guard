<?php
/**
 * Plain-English text for a finding: what is wrong, how to fix it, what it means for the site (DECISIONS D56).
 *
 * @package QasimPHPUpgradeCheckGuard
 */

namespace Qpucg\Report;

use Qpucg\Scan\Categories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps rule families to texts. Never loads the engine.
 */
class Explain {

	/**
	 * Rule family => pattern on the last two segments of the rule code (sniff.code), checked in this order.
	 */
	const PATTERNS = array(
		'implicit_nullable'  => '/^RemovedImplicitlyNullableParam\./',
		'trigger_error'      => '/^RemovedTriggerErrorLevel\./',
		'csv_escape'         => '/^RemovedProprietaryCSVEscaping\./',
		'e_strict'           => '/^RemovedConstants\.e_strict/',
		'each'               => '/^RemovedFunctions\.each(Deprecated|Removed)/',
		'create_function'    => '/^RemovedFunctions\.create_function/',
		'mysql'              => '/^(RemovedFunctions|RemovedExtensions)\.mysql_/',
		'money_format'       => '/^RemovedFunctions\.money_format/',
		'utf8'               => '/^RemovedFunctions\.utf8_(encode|decode)/',
		'strftime'           => '/^RemovedFunctions\.(gm)?strftime/',
		'curly_offset'       => '/^RemovedCurlyBraceArrayAccess\./',
		'php4_constructor'   => '/^RemovedPHP4StyleConstructors\./',
		'soft_required'      => '/\.[A-Za-z0-9_]*SoftRequired$/',
		'get_class'          => '/^RemovedGetClassNoArgs\./',
		'xml_set_object'     => '/^RemovedFunctions\.xml_set_object/',
		'mysqli_store'       => '/mysqli_store_result/',
		'html_flags'         => '/^NewHTMLEntitiesFlagsDefault\.NotSet$/',
		'dollar_brace'       => '/^RemovedDollarBraceStringEmbeds\./',
		'forbidden_resource' => '/^ForbiddenNames\.resourceFound$/',
		'optional_required'  => '/^RemovedOptionalBeforeRequiredParam\./',
		'libxml_loader'      => '/^RemovedFunctions\.libxml_disable_entity_loader/',
		'serializable'       => '/^RemovedSerializable\./',
		'mysqli_ping'        => '/^RemovedFunctions\.mysqli_ping/',
		'imagerotate_param'  => '/^RemovedFunctionParameters\.imagerotate_ignore_transparent/',
		'sunfuncs'           => '/^RemovedConstants\.sunfuncs_ret_/i',
		'ldap_exop_param'    => '/^RemovedFunctionParameters\.ldap_exop_response_/',
		'curlopt_binary'     => '/^RemovedConstants\.curlopt_binarytransfer/i',
	);

	/**
	 * Extension of a needs_extension rule => pattern on sniff.code.
	 */
	const EXTENSIONS = array(
		'ext_imap'   => '/\.imap_/',
		'ext_oci8'   => '/\.(oci|sqlt_)/',
		'ext_pspell' => '/\.pspell_/',
		'ext_xmlrpc' => '/\.xmlrpc/',
	);

	/**
	 * Texts, built on first use (translated once per request).
	 *
	 * @var array|null
	 */
	private static $texts = null;

	/**
	 * Rule family per "rule|category", so a long report runs the patterns once per rule.
	 *
	 * @var array
	 */
	private static $families = array();

	/**
	 * Text for a finding. The engine's own message never appears here (only under Technical details, D60).
	 *
	 * @param string $rule     PHPCS rule code.
	 * @param string $category Category.
	 * @param string $severity error or warning.
	 * @return array problem, how_to_fix, impact (strings), specific (bool: false = the fallback).
	 */
	public static function of( $rule, $category, $severity ) {
		$family = self::rule_family( (string) $rule, (string) $category );
		$impact = self::impact( (string) $category, (string) $severity );
		if ( null === $family ) {
			return array(
				'problem'    => self::generic_problem( (string) $category ),
				'how_to_fix' => self::generic_fix( (string) $category ),
				'impact'     => $impact,
				'specific'   => false,
			);
		}
		$texts = self::texts();
		return array(
			'problem'    => $texts[ $family ][0],
			'how_to_fix' => $texts[ $family ][1],
			'impact'     => 'html_flags' === $family ? __( 'Quotes in some text may display a little differently; the site keeps working.', 'qasim-php-upgrade-check-guard' ) : $impact,
			'specific'   => true,
		);
	}

	/**
	 * Rule family of a rule code, null for none.
	 *
	 * @param string $rule     Rule code.
	 * @param string $category Category.
	 * @return string|null
	 */
	public static function rule_family( $rule, $category ) {
		$key = $rule . '|' . $category;
		if ( ! array_key_exists( $key, self::$families ) ) {
			self::$families[ $key ] = self::find_family( $rule, $category );
		}
		return self::$families[ $key ];
	}

	/**
	 * Runs the patterns for rule_family().
	 *
	 * @param string $rule     Rule code.
	 * @param string $category Category.
	 * @return string|null
	 */
	private static function find_family( $rule, $category ) {
		$tail = implode( '.', array_slice( explode( '.', $rule ), -2 ) );
		if ( Categories::NEEDS_EXTENSION === $category ) {
			foreach ( self::EXTENSIONS as $family => $pattern ) {
				if ( preg_match( $pattern, $tail ) ) {
					return $family;
				}
			}
			return 'ext_other';
		}
		foreach ( self::PATTERNS as $family => $pattern ) {
			if ( preg_match( $pattern, $tail ) ) {
				return $family;
			}
		}
		return null;
	}

	/**
	 * All specific texts, for tests/explain-check.php.
	 *
	 * @return array family => array( problem, how_to_fix ).
	 */
	public static function texts() {
		if ( null === self::$texts ) {
			self::$texts = array(
				'implicit_nullable'  => array(
					__( 'A function says a value has a set type but defaults to nothing. Newer PHP warns about this way of writing it.', 'qasim-php-upgrade-check-guard' ),
					__( 'Ask the developer to mark the type as optional by adding a question mark before it. Or update the plugin or theme.', 'qasim-php-upgrade-check-guard' ),
				),
				'trigger_error'      => array(
					__( 'The code stops the site with an old kind of error message that newer PHP is phasing out.', 'qasim-php-upgrade-check-guard' ),
					__( 'Ask the developer to replace it with an exception. Or update the plugin or theme.', 'qasim-php-upgrade-check-guard' ),
				),
				'csv_escape'         => array(
					__( 'The code reads or writes CSV files without saying how special characters are handled. Newer PHP wants this stated.', 'qasim-php-upgrade-check-guard' ),
					__( 'Ask the developer to pass the escape setting in the call. Or update the plugin or theme.', 'qasim-php-upgrade-check-guard' ),
				),
				'e_strict'           => array(
					__( 'The code refers to an old error level called E_STRICT that newer PHP no longer uses.', 'qasim-php-upgrade-check-guard' ),
					__( 'Ask the developer to remove that reference. It is safe to drop.', 'qasim-php-upgrade-check-guard' ),
				),
				'each'               => array(
					__( 'The code uses each() to walk through a list, and PHP removed that function.', 'qasim-php-upgrade-check-guard' ),
					__( 'Update the plugin or theme. A developer can replace it with a foreach loop.', 'qasim-php-upgrade-check-guard' ),
				),
				'create_function'    => array(
					__( 'The code builds a function from text with create_function(), which PHP removed.', 'qasim-php-upgrade-check-guard' ),
					__( 'Update the plugin or theme. A developer can replace it with a normal anonymous function.', 'qasim-php-upgrade-check-guard' ),
				),
				'mysql'              => array(
					__( 'The code talks to the database with the old mysql_ functions, which PHP removed.', 'qasim-php-upgrade-check-guard' ),
					__( 'Update the plugin or theme. A developer can switch it to the WordPress database functions.', 'qasim-php-upgrade-check-guard' ),
				),
				'money_format'       => array(
					__( 'The code formats prices with money_format(), which PHP removed.', 'qasim-php-upgrade-check-guard' ),
					__( 'Update the plugin or theme. A developer can use the NumberFormatter class instead.', 'qasim-php-upgrade-check-guard' ),
				),
				'utf8'               => array(
					__( 'The code converts text with utf8_encode() or utf8_decode(), which newer PHP is phasing out.', 'qasim-php-upgrade-check-guard' ),
					__( 'Update the plugin or theme. A developer can use mb_convert_encoding() instead.', 'qasim-php-upgrade-check-guard' ),
				),
				'strftime'           => array(
					__( 'The code formats dates with strftime(), which newer PHP is phasing out.', 'qasim-php-upgrade-check-guard' ),
					__( 'Update the plugin or theme. A developer can use wp_date() instead.', 'qasim-php-upgrade-check-guard' ),
				),
				'curly_offset'       => array(
					__( 'The code reads part of a text or list with curly braces, a way of writing that PHP removed. The whole file fails to load.', 'qasim-php-upgrade-check-guard' ),
					__( 'Update the plugin or theme. A developer can replace the curly braces with square brackets.', 'qasim-php-upgrade-check-guard' ),
				),
				'php4_constructor'   => array(
					__( 'A class sets itself up the very old way, with a function named after the class. PHP no longer runs that setup step.', 'qasim-php-upgrade-check-guard' ),
					__( 'Update the plugin or theme. A developer can rename that function to __construct.', 'qasim-php-upgrade-check-guard' ),
				),
				'soft_required'      => array(
					__( 'The code leaves out a value that PHP used to treat as optional. Newer PHP expects it.', 'qasim-php-upgrade-check-guard' ),
					__( 'Ask the developer to pass the missing value. Or update the plugin or theme.', 'qasim-php-upgrade-check-guard' ),
				),
				'get_class'          => array(
					__( 'The code calls get_class() or a similar function without saying which object it means. Newer PHP is phasing this out.', 'qasim-php-upgrade-check-guard' ),
					__( 'Ask the developer to pass the object, or to use self::class. Or update the plugin or theme.', 'qasim-php-upgrade-check-guard' ),
				),
				'xml_set_object'     => array(
					__( 'The code uses xml_set_object() to read XML, which newer PHP is phasing out.', 'qasim-php-upgrade-check-guard' ),
					__( 'Update the plugin or theme. A developer can pass the handlers as callbacks instead.', 'qasim-php-upgrade-check-guard' ),
				),
				'mysqli_store'       => array(
					__( 'The code passes an extra setting to mysqli_store_result() that newer PHP is phasing out.', 'qasim-php-upgrade-check-guard' ),
					__( 'Ask the developer to remove that setting. Or update the plugin or theme.', 'qasim-php-upgrade-check-guard' ),
				),
				'html_flags'         => array(
					__( 'The code converts special characters in text without saying how to treat quotes. Since PHP 8.1 single quotes are converted too.', 'qasim-php-upgrade-check-guard' ),
					__( 'Usually nothing to do. If quotes look wrong, ask the developer to set the flags in the call.', 'qasim-php-upgrade-check-guard' ),
				),
				'dollar_brace'       => array(
					__( 'The code puts a variable inside text using the ${name} form, which newer PHP is phasing out.', 'qasim-php-upgrade-check-guard' ),
					__( 'Update the plugin or theme. A developer can write it as {$name} instead.', 'qasim-php-upgrade-check-guard' ),
				),
				'forbidden_resource' => array(
					__( 'A class, function or constant is named "resource", a name PHP keeps for its own use.', 'qasim-php-upgrade-check-guard' ),
					__( 'Ask the developer to rename it. Or update the plugin or theme.', 'qasim-php-upgrade-check-guard' ),
				),
				'optional_required'  => array(
					__( 'A function lists an optional value before a required one. Newer PHP warns about this order.', 'qasim-php-upgrade-check-guard' ),
					__( 'Ask the developer to put the required values first. Or update the plugin or theme.', 'qasim-php-upgrade-check-guard' ),
				),
				'libxml_loader'      => array(
					__( 'The code calls libxml_disable_entity_loader(), which newer PHP no longer needs and is phasing out.', 'qasim-php-upgrade-check-guard' ),
					__( 'Update the plugin or theme. A developer can remove the call on PHP 8 and newer.', 'qasim-php-upgrade-check-guard' ),
				),
				'serializable'       => array(
					__( 'A class saves and restores itself the old Serializable way, which newer PHP is phasing out.', 'qasim-php-upgrade-check-guard' ),
					__( 'Ask the developer to add the __serialize and __unserialize methods. Or update the plugin or theme.', 'qasim-php-upgrade-check-guard' ),
				),
				'mysqli_ping'        => array(
					__( 'The code checks its database connection with mysqli_ping(), which newer PHP is phasing out.', 'qasim-php-upgrade-check-guard' ),
					__( 'Ask the developer to run a simple query instead, or to catch errors on normal queries. Or update the plugin or theme.', 'qasim-php-upgrade-check-guard' ),
				),
				'imagerotate_param'  => array(
					__( 'The code passes an extra setting to imagerotate() that PHP removed.', 'qasim-php-upgrade-check-guard' ),
					__( 'Ask the developer to remove the last value in that call. Or update the plugin or theme.', 'qasim-php-upgrade-check-guard' ),
				),
				'sunfuncs'           => array(
					__( 'The code works out sunrise or sunset times with an old setting named SUNFUNCS_RET, which newer PHP is phasing out.', 'qasim-php-upgrade-check-guard' ),
					__( 'Ask the developer to use date_sun_info() instead. Or update the plugin or theme.', 'qasim-php-upgrade-check-guard' ),
				),
				'ldap_exop_param'    => array(
					__( 'The code asks ldap_exop() to hand back its reply through extra values, which newer PHP is phasing out.', 'qasim-php-upgrade-check-guard' ),
					__( 'Ask the developer to use ldap_exop_sync() instead. Or update the plugin or theme.', 'qasim-php-upgrade-check-guard' ),
				),
				'curlopt_binary'     => array(
					__( 'The code uses a download setting named CURLOPT_BINARYTRANSFER, which no longer does anything and newer PHP is phasing out.', 'qasim-php-upgrade-check-guard' ),
					__( 'Ask the developer to remove that setting. It is safe to drop.', 'qasim-php-upgrade-check-guard' ),
				),
				'ext_imap'           => array(
					__( 'The code reads email with IMAP, which is no longer included with PHP 8.4.', 'qasim-php-upgrade-check-guard' ),
					__( 'Ask your host to install the IMAP extension. Or replace the plugin that needs it.', 'qasim-php-upgrade-check-guard' ),
				),
				'ext_oci8'           => array(
					__( 'The code connects to an Oracle database with OCI8, which is no longer included with PHP 8.4.', 'qasim-php-upgrade-check-guard' ),
					__( 'Ask your host to install the OCI8 extension. You only need it if the site uses Oracle.', 'qasim-php-upgrade-check-guard' ),
				),
				'ext_pspell'         => array(
					__( 'The code checks spelling with pspell, which is no longer included with PHP 8.4.', 'qasim-php-upgrade-check-guard' ),
					__( 'Ask your host to install the pspell extension. Or replace the plugin that needs it.', 'qasim-php-upgrade-check-guard' ),
				),
				'ext_xmlrpc'         => array(
					__( 'The code uses PHP\'s own XML-RPC tools, which are no longer included since PHP 8.0.', 'qasim-php-upgrade-check-guard' ),
					__( 'Ask your host to install the xmlrpc extension. Or update the plugin that needs it.', 'qasim-php-upgrade-check-guard' ),
				),
				'ext_other'          => array(
					__( 'The code needs a PHP add-on that is no longer included with PHP.', 'qasim-php-upgrade-check-guard' ),
					__( 'Ask your host to install that extension. Or replace the plugin that needs it.', 'qasim-php-upgrade-check-guard' ),
				),
			);
		}
		return self::$texts;
	}

	/**
	 * One sentence on what a finding means for the site owner.
	 *
	 * @param string $category Category.
	 * @param string $severity error or warning.
	 * @return string
	 */
	public static function impact( $category, $severity ) {
		$error = 'error' === $severity;
		switch ( $category ) {
			case Categories::REMOVED:
				return $error ? __( 'This can stop the page from loading.', 'qasim-php-upgrade-check-guard' ) : __( 'This part of the site can stop working.', 'qasim-php-upgrade-check-guard' );
			case Categories::NEEDS_EXTENSION:
				return __( 'This feature stops working unless your host installs the missing add-on.', 'qasim-php-upgrade-check-guard' );
			case Categories::DEPRECATED:
				return $error ? __( 'Works now; stops working in a future PHP version.', 'qasim-php-upgrade-check-guard' ) : __( 'Your site keeps working; you\'ll only see warnings in the error log.', 'qasim-php-upgrade-check-guard' );
			case Categories::CHANGED:
				return $error ? __( 'This part of the site may stop working as it did.', 'qasim-php-upgrade-check-guard' ) : __( 'This part of the site may behave differently.', 'qasim-php-upgrade-check-guard' );
			case Categories::NEWER_THAN_CURRENT:
				return __( 'This uses a newer PHP feature, so it is not an upgrade problem.', 'qasim-php-upgrade-check-guard' );
			default:
				return __( 'This does not affect how your site runs.', 'qasim-php-upgrade-check-guard' );
		}
	}

	/**
	 * Impact sentence for a guarded finding (DECISIONS D56).
	 *
	 * @return string
	 */
	public static function guarded_impact() {
		return __( 'This code only runs where it is safe, so it should not cause a problem.', 'qasim-php-upgrade-check-guard' );
	}

	/**
	 * Generic sentence when a rule has no text of its own, so the engine's wording never reaches a normal screen.
	 *
	 * @param string $category Category.
	 * @return string
	 */
	private static function generic_problem( $category ) {
		switch ( $category ) {
			case Categories::REMOVED:
				return __( 'The code uses a PHP feature that has been removed.', 'qasim-php-upgrade-check-guard' );
			case Categories::DEPRECATED:
				return __( 'The code uses a PHP feature that newer PHP is phasing out.', 'qasim-php-upgrade-check-guard' );
			case Categories::CHANGED:
				return __( 'The code relies on PHP behavior that has changed.', 'qasim-php-upgrade-check-guard' );
			case Categories::NEWER_THAN_CURRENT:
				return __( 'The code uses a feature from a newer PHP version.', 'qasim-php-upgrade-check-guard' );
			default:
				return __( 'This is a note about how the code is written.', 'qasim-php-upgrade-check-guard' );
		}
	}

	/**
	 * Generic advice when a rule has no text of its own.
	 *
	 * @param string $category Category.
	 * @return string
	 */
	private static function generic_fix( $category ) {
		if ( Categories::NEWER_THAN_CURRENT === $category || Categories::ADVISORY === $category || Categories::IGNORED === $category ) {
			return __( 'Nothing to do before the upgrade.', 'qasim-php-upgrade-check-guard' );
		}
		return __( 'Update the plugin or theme to its latest version. If the problem stays, ask its developer for a fix.', 'qasim-php-upgrade-check-guard' );
	}
}
