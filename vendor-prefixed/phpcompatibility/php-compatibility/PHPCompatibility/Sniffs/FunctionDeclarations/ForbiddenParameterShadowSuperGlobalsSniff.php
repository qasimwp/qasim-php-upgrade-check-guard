<?php

/**
 * PHPCompatibility, an external standard for PHP_CodeSniffer.
 *
 * @package   PHPCompatibility
 * @copyright 2012-2020 PHPCompatibility Contributors
 * @license   https://opensource.org/licenses/LGPL-3.0 LGPL3
 * @link      https://github.com/PHPCompatibility/PHPCompatibility
 */
namespace Qpucg\Vendor\PHPCompatibility\Sniffs\FunctionDeclarations;

use Qpucg\Vendor\PHPCompatibility\Helpers\ScannedCode;
use Qpucg\Vendor\PHPCompatibility\Sniff;
use Qpucg\Vendor\PHP_CodeSniffer\Files\File;
use Qpucg\Vendor\PHPCSUtils\Tokens\Collections;
use Qpucg\Vendor\PHPCSUtils\Utils\FunctionDeclarations;
use Qpucg\Vendor\PHPCSUtils\Utils\MessageHelper;
use Qpucg\Vendor\PHPCSUtils\Utils\Variables;
/**
 * Detect the use of superglobals as parameters for functions, support for which was removed in PHP 5.4.
 *
 * PHP version 5.4
 *
 * @link https://php-legacy-docs.zend.com/manual/php5/en/migration54.incompatible
 *
 * @since 7.0.0
 * @since 10.0.0 This class is now `final`.
 */
final class ForbiddenParameterShadowSuperGlobalsSniff extends Sniff
{
    /**
     * Register the tokens to listen for.
     *
     * @since 7.0.0
     * @since 7.1.3  Allows for closures.
     * @since 10.0.0 Allows for PHP 7.4+ arrow functions.
     *
     * @return array<int|string>
     */
    public function register()
    {
        return Collections::functionDeclarationTokens();
    }
    /**
     * Processes the test.
     *
     * @since 7.0.0
     *
     * @param \PHP_CodeSniffer\Files\File $phpcsFile The file being scanned.
     * @param int                         $stackPtr  The position of the current token.
     *
     * @return void
     */
    public function process(File $phpcsFile, $stackPtr)
    {
        if (ScannedCode::shouldRunOnOrAbove('5.4') === \false) {
            return;
        }
        // Get all parameters from the function signature.
        $parameters = FunctionDeclarations::getParameters($phpcsFile, $stackPtr);
        if (empty($parameters)) {
            return;
        }
        foreach ($parameters as $param) {
            if (Variables::isSuperglobalName($param['name']) === \true) {
                $error = 'Parameter shadowing super global (%s) causes a fatal error since PHP 5.4';
                $errorCode = MessageHelper::stringToErrorCode(\substr($param['name'], 1), \true) . 'Found';
                $data = [$param['name']];
                $phpcsFile->addError($error, $param['token'], $errorCode, $data);
            }
        }
    }
}
