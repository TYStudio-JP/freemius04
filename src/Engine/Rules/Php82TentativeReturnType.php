<?php
/**
 * Overrides whose return type no longer fits the tentative return type that
 * PHP 8.2 gave four internal methods.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Stmt\Class_;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: signature.tentative_return_type_changed. Deprecated in 8.2.
 *
 *     class Local_Date extends DateTime {
 *         public static function createFromImmutable( $object ): DateTime { ... }
 *     }
 *
 * PHP 8.2 changed the tentative return type of
 *
 * - DateTime::createFromImmutable()        from DateTime to static
 * - DateTimeImmutable::createFromMutable() from DateTimeImmutable to static
 * - SplFileObject::hasChildren()           from bool to false
 * - SplFileObject::getChildren()           from ?RecursiveIterator to null
 *
 * An override that declares a return type which fitted the old one gets a
 * notice when the class is declared. Reported only when the class extends the
 * internal class directly (or SplTempFileObject, which has its own fixture),
 * and the method declares a return type other than the new one.
 *
 * Not reported: a method with no return type at all. That is deprecated since
 * 8.1 for every tentative return type and is no part of this change (fixture
 * runtime.tentative_return_type_missing). Nor a method that carries
 * #[\ReturnTypeWillChange] (fixture runtime.tentative_return_type_will_change).
 */
final class Php82TentativeReturnType implements Rule {

	/**
	 * Internal class, lower case => method, lower case => the return type
	 * that PHP 8.2 expects.
	 */
	const METHODS = array(
		'datetime'          => array( 'createfromimmutable' => 'static' ),
		'datetimeimmutable' => array( 'createfrommutable' => 'static' ),
		'splfileobject'     => array(
			'haschildren' => 'false',
			'getchildren' => 'null',
		),
		'spltempfileobject' => array(
			'haschildren' => 'false',
			'getchildren' => 'null',
		),
	);

	/**
	 * Passed to Context::function_is_guarded(): no function has this name, so
	 * only a PHP version check counts as a guard.
	 */
	const VERSION_GUARD = '8.2: version check only';

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Class_::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Class_  $node    Class declaration, named or anonymous.
	 * @param Context $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( ! $node->extends instanceof Node\Name ) {
			return;
		}

		$resolved = $node->extends->getAttribute( 'resolvedName' );
		$resolved = $resolved instanceof Node\Name ? $resolved : $node->extends;
		$parent   = strtolower( $resolved->toString() );

		if ( ! isset( self::METHODS[ $parent ] ) ) {
			return;
		}

		foreach ( $node->getMethods() as $method ) {
			$name = strtolower( $method->name->toString() );
			$type = $method->returnType;

			if ( ! isset( self::METHODS[ $parent ][ $name ] ) || null === $type || self::will_change( $method ) ) {
				continue;
			}

			$expected = self::METHODS[ $parent ][ $name ];

			// The expected type itself, and "never", which fits every type.
			if ( ( $type instanceof Node\Identifier || $type instanceof Node\Name ) && in_array( strtolower( $type->toString() ), array( $expected, 'never' ), true ) ) {
				continue;
			}

			$finding          = $context->report( 'signature.tentative_return_type_changed', $type, array( 'deprecated' => '8.2' ) );
			$finding->subject = $resolved->toString() . '::' . $method->name->toString() . '()';
			$finding->instead = ': ' . $expected . ' (PHP 8.0 and later), or #[\ReturnTypeWillChange] above the method';

			// A class at the top of a file is declared when the file is compiled.
			// One inside an "if" or a function is declared only when that code runs.
			$around = $node->getAttribute( 'parent' );

			if ( null !== $around && ! $around instanceof Node\Stmt\Namespace_ ) {
				$finding->guarded = $context->function_is_guarded( self::VERSION_GUARD );
			}
		}
	}

	/**
	 * Whether the method carries #[ReturnTypeWillChange].
	 *
	 * @param Node\Stmt\ClassMethod $method Method.
	 * @return bool
	 */
	private static function will_change( Node\Stmt\ClassMethod $method ) {
		foreach ( $method->attrGroups as $group ) {
			foreach ( $group->attrs as $attribute ) {
				if ( 'returntypewillchange' === strtolower( $attribute->name->getLast() ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
