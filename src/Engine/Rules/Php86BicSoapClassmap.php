<?php
/**
 * SoapClient / SoapServer "classmap" options that 8.6 rejects (tranche 8.6-bic).
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: argument.soap_classmap_integer_keys. Documented for 8.6 only.
 *
 * The PHP 8.6 upgrade notes, word for word:
 *
 *     The "classmap" option of SoapClient and SoapServer now rejects arrays
 *     containing integer keys. Previously, sparse integer-keyed and mixed-keyed
 *     arrays could be accepted. SoapClient now throws TypeError for non-array
 *     "classmap" options and ValueError for arrays containing integer keys, also
 *     when the "exceptions" option is disabled.
 *
 *     new SoapClient( null, array( ..., 'classmap' => array( 'Item' => 'Item', 'Other' ) ) );
 *
 * What the runtimes showed (fixtures argument.soap_classmap_integer_keys~8.6-bic-*):
 *
 * - a classmap with string and integer keys is accepted by SoapClient and
 *   SoapServer on 7.4 to 8.5: reported, breaks 8.6;
 * - a string as classmap is accepted by SoapClient on 7.4 to 8.5: reported,
 *   breaks 8.6 (the notes name SoapClient only, so SoapServer is not);
 * - a classmap with integer keys only (a list, or array( 5 => 'Foo' )) is
 *   already rejected from 8.2 on with "'classmap' option must be an
 *   associative array", so it is not an 8.6 change and is not reported here.
 *
 * Only literal option arrays with literal keys are read. No 8.6 runtime:
 * evidence "documented".
 */
final class Php86BicSoapClassmap implements Rule {

	const VERSION_GUARD = '8.6-bic: version check only';

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Node\Expr\New_::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node\Expr\New_ $node    Instantiation.
	 * @param Context        $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( ! $node->class instanceof Node\Name ) {
			return;
		}

		$resolved = $node->class->getAttribute( 'resolvedName' );
		$resolved = $resolved instanceof Node\Name ? $resolved : $node->class;
		$class    = strtolower( ltrim( $resolved->toString(), '\\' ) );

		if ( 'soapclient' !== $class && 'soapserver' !== $class ) {
			return;
		}

		$options = null;

		foreach ( $node->args as $index => $arg ) {
			if ( ! $arg instanceof Node\Arg || $arg->unpack ) {
				return;
			}

			if ( null === $arg->name ? 1 === $index : 'options' === strtolower( $arg->name->toString() ) ) {
				$options = $arg->value;
				break;
			}
		}

		if ( ! $options instanceof Node\Expr\Array_ ) {
			return;
		}

		$classmap = null;

		foreach ( $options->items as $item ) {
			if ( null !== $item && $item->key instanceof Node\Scalar\String_ && 'classmap' === $item->key->value ) {
				$classmap = $item->value;
			}
		}

		if ( null === $classmap ) {
			return;
		}

		$why = null;

		if ( $classmap instanceof Node\Expr\Array_ ) {
			$kinds = self::key_kinds( $classmap );

			if ( null !== $kinds && $kinds['int'] && $kinds['string'] ) {
				$why = 'string and integer keys (ValueError)';
			}
		} elseif ( 'soapclient' === $class && ( $classmap instanceof Node\Scalar\String_ || $classmap instanceof Node\Scalar\Int_ || $classmap instanceof Node\Scalar\Float_ ) ) {
			$why = 'a value that is not an array (TypeError)';
		}

		if ( null === $why ) {
			return;
		}

		$finding           = $context->report( 'argument.soap_classmap_integer_keys', $node, array( 'breaks' => '8.6' ) );
		$finding->subject  = $resolved->getLast() . ' "classmap" with ' . $why;
		$finding->evidence = 'documented';
		$finding->instead  = 'an array whose keys are all SOAP type names (strings); documented for PHP 8.6, not verified on an 8.6 runtime';
		$finding->guarded  = $context->function_is_guarded( self::VERSION_GUARD );
	}

	/**
	 * Whether the literal array has integer keys and string keys, or null when
	 * a key is not a literal.
	 *
	 * @param Node\Expr\Array_ $array Array literal.
	 * @return array|null Keys int and string, both bool.
	 */
	private static function key_kinds( Node\Expr\Array_ $array ) {
		$kinds = array(
			'int'    => false,
			'string' => false,
		);

		foreach ( $array->items as $item ) {
			if ( null === $item || $item->unpack ) {
				return null;
			}

			if ( null === $item->key || $item->key instanceof Node\Scalar\Int_ ) {
				$kinds['int'] = true;
			} elseif ( $item->key instanceof Node\Scalar\String_ ) {
				// PHP stores "5" as the integer key 5.
				if ( preg_match( '/^(0|-?[1-9][0-9]*)$/', $item->key->value ) ) {
					$kinds['int'] = true;
				} else {
					$kinds['string'] = true;
				}
			} else {
				return null;
			}
		}

		return $kinds;
	}
}
