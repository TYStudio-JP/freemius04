<?php
/**
 * Overrides of internal methods whose signature PHP 8.5 widened.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Stmt;

defined( 'ABSPATH' ) || exit;

/**
 * Rule ids (tranche 8.5-bic), both break in 8.5:
 *
 * - class.soapclient_dorequest_signature
 *
 *       class My_Client extends SoapClient {
 *           public function __doRequest( $request, $location, $action, $version, $one_way = 0 ) {}
 *       }
 *
 *   PHP 8.5 added a sixth parameter, ?string $uriParserClass = null, to
 *   SoapClient::__doRequest(). An override with the five parameters that 7.4
 *   to 8.4 expected is now incompatible, and declaring the class is a fatal
 *   error (fixtures class.soapclient_dorequest_signature~8.5-bic-*). Reported
 *   for exactly five parameters without a variadic one; fewer than five was
 *   already refused by 8.0 and belongs to another change.
 *
 * - class.splfileobject_fwrite_signature
 *
 *       class My_File extends SplFileObject {
 *           public function fwrite( string $data, int $length = 0 ) {}
 *       }
 *
 *   SplFileObject::fwrite( string $data, ?int $length = null ) in 8.5; an
 *   override whose $length has a type that does not accept null is now
 *   incompatible (fixture class.splfileobject_fwrite_signature~8.5-bic).
 *   Untyped parameters, nullable types, mixed and a null default are fine.
 *
 * Only a class that extends the internal class directly is looked at: the
 * scanner cannot know what a user class further up extends.
 */
final class Php85BicInternalOverride implements Rule {

	const VERSION_GUARD = '8.5-bic: version check only';

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Stmt\Class_::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Stmt\Class_ $node    Class declaration, named or anonymous.
	 * @param Context     $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( ! $node->extends instanceof Node\Name ) {
			return;
		}

		$resolved = $node->extends->getAttribute( 'resolvedName' );
		$resolved = $resolved instanceof Node\Name ? $resolved : $node->extends;
		$parent   = strtolower( ltrim( $resolved->toString(), '\\' ) );

		if ( 'soapclient' === $parent ) {
			$method = $node->getMethod( '__dorequest' );

			if ( null === $method || ! self::five_plain_parameters( $method ) ) {
				return;
			}

			$finding          = $context->report( 'class.soapclient_dorequest_signature', $method, array( 'breaks' => '8.5' ) );
			$finding->subject = 'SoapClient::__doRequest() override with five parameters';
			$finding->instead = 'add a sixth parameter: ?string $uriParserClass = null (or $uri_parser_class = null without types)';
		} elseif ( 'splfileobject' === $parent || 'spltempfileobject' === $parent ) {
			$method = $node->getMethod( 'fwrite' );

			if ( null === $method || ! isset( $method->params[1] ) || ! self::refuses_null( $method->params[1] ) ) {
				return;
			}

			$finding          = $context->report( 'class.splfileobject_fwrite_signature', $method->params[1], array( 'breaks' => '8.5' ) );
			$finding->subject = $resolved->toString() . '::fwrite() override with a non-nullable $length';
			$finding->instead = '?int $length = null';
		} else {
			return;
		}

		// A class at the top of a file is declared when the file is compiled.
		// One inside an "if" or a function is declared only when that code runs.
		$around = $node->getAttribute( 'parent' );

		if ( null !== $around && ! $around instanceof Stmt\Namespace_ ) {
			$finding->guarded = $context->function_is_guarded( self::VERSION_GUARD );
		}
	}

	/**
	 * Whether a method has exactly five parameters and none is variadic.
	 *
	 * @param Stmt\ClassMethod $method Method.
	 * @return bool
	 */
	private static function five_plain_parameters( Stmt\ClassMethod $method ) {
		if ( 5 !== count( $method->params ) ) {
			return false;
		}

		foreach ( $method->params as $param ) {
			if ( $param->variadic ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a parameter declares a type that does not accept null.
	 *
	 * @param Node\Param $param Parameter.
	 * @return bool
	 */
	private static function refuses_null( Node\Param $param ) {
		$type = $param->type;

		if ( null === $type || $param->variadic || $type instanceof Node\NullableType ) {
			return false;
		}

		// "int $length = null" is implicitly nullable.
		if ( $param->default instanceof Node\Expr\ConstFetch && 'null' === strtolower( $param->default->name->toString() ) ) {
			return false;
		}

		if ( $type instanceof Node\UnionType ) {
			foreach ( $type->types as $member ) {
				if ( ( $member instanceof Node\Identifier || $member instanceof Node\Name ) && in_array( strtolower( $member->toString() ), array( 'null', 'mixed' ), true ) ) {
					return false;
				}
			}

			return true;
		}

		if ( $type instanceof Node\Identifier ) {
			return ! in_array( strtolower( $type->toString() ), array( 'mixed', 'null' ), true );
		}

		// A class name or an intersection type: also refuses null.
		return true;
	}
}
