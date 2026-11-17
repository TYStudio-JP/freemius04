<?php
/**
 * is_resource() on what a GD, OpenSSL, XML, XMLWriter or Zlib function
 * returned (tranche 8.0-bic-ext).
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr;
use TYStudio\UpgradeWarden\Vendor\PhpParser\NodeFinder;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: call.is_resource_on_object. Changed in 8.0.
 *
 *     $canvas = imagecreatetruecolor( 10, 10 );
 *     if ( ! is_resource( $canvas ) ) { return; }   // always taken from 8.0
 *
 * From 8.0 these functions return objects (GdImage, OpenSSLAsymmetricKey,
 * OpenSSLCertificateSigningRequest, OpenSSLCertificate, XMLParser,
 * XMLWriter, InflateContext, DeflateContext), so is_resource() is false even
 * when they worked and the code silently takes the other branch.
 *
 * Reported when is_resource() is given one of these calls directly (with or
 * without @), or a local variable of a function, method or closure that is
 * only ever assigned such a call in that function, before the is_resource()
 * call (see Php80BicExtLocal). Passing the variable on is accepted only to
 * functions of the same extension that do not take it by reference, and to a
 * few functions that only look at it. A value kept in a property, a global or
 * a variable outside a function is not followed.
 *
 * Not reported when the same condition (or another condition of the same
 * if / elseif chain) also accepts the object: instanceof, is_object() or a
 * comparison with false on the same value, as code written for 7.4 and 8.x
 * at once does.
 *
 * The fix writes ( false !== ... ), the check the notes recommend; these
 * functions return false when they fail on every version.
 *
 * Sockets, Sysv* and Enchant made the same change but no runtime here has
 * those extensions, so they are not reported.
 */
final class Php80BicExtIsResource implements Rule {

	/**
	 * Functions whose result became an object in 8.0, with the prefix of the
	 * functions of their extension.
	 */
	const CREATORS = array(
		'imagecreate'                 => 'image',
		'imagecreatetruecolor'        => 'image',
		'imagecreatefromstring'       => 'image',
		'imagecreatefrompng'          => 'image',
		'imagecreatefromjpeg'         => 'image',
		'imagecreatefromgif'          => 'image',
		'imagecreatefromwebp'         => 'image',
		'imagecreatefrombmp'          => 'image',
		'imagecreatefromwbmp'         => 'image',
		'imagecreatefromxbm'          => 'image',
		'imagecreatefromxpm'          => 'image',
		'imagecreatefromgd'           => 'image',
		'imagecreatefromgd2'          => 'image',
		'imagecreatefromgd2part'      => 'image',
		'imagecreatefromtga'          => 'image',
		'imagecrop'                   => 'image',
		'imagecropauto'               => 'image',
		'imagescale'                  => 'image',
		'imagerotate'                 => 'image',
		'imageaffine'                 => 'image',
		'openssl_x509_read'           => 'openssl_',
		'openssl_csr_sign'            => 'openssl_',
		'openssl_csr_new'             => 'openssl_',
		'openssl_pkey_new'            => 'openssl_',
		'xml_parser_create'           => 'xml_',
		'xml_parser_create_ns'        => 'xml_',
		'xmlwriter_open_memory'       => 'xmlwriter_',
		'xmlwriter_open_uri'          => 'xmlwriter_',
		'inflate_init'                => 'inflate_',
		'deflate_init'                => 'deflate_',
	);

	/**
	 * Functions that may be given the value anywhere without changing it.
	 */
	const ONLY_LOOK = array(
		'is_resource'       => true,
		'is_object'         => true,
		'is_bool'           => true,
		'gettype'           => true,
		'get_resource_type' => true,
		'var_dump'          => true,
	);

	/**
	 * OpenSSL functions that only read every argument the value may be in.
	 * Other OpenSSL functions accept it only as their first argument, as
	 * several write to later ones by reference.
	 */
	const OPENSSL_READ_ANYWHERE = array(
		'openssl_csr_new'                => true,
		'openssl_csr_sign'               => true,
		'openssl_x509_check_private_key' => true,
		'openssl_x509_verify'            => true,
		'openssl_pkey_derive'            => true,
	);

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Expr\FuncCall::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Expr\FuncCall $node    Function call.
	 * @param Context       $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( 'is_resource' !== InternalFunctions::global_name( $node->name ) || isset( $context->declared_functions['is_resource'] ) ) {
			return;
		}

		if ( 1 !== count( $node->args ) || ! $node->args[0] instanceof Node\Arg || $node->args[0]->unpack || null !== $node->args[0]->name ) {
			return;
		}

		$value = $node->args[0]->value;

		if ( self::also_checks_object( $node, $value, $context ) ) {
			return;
		}

		$creator = Php80BicExtLocal::call_to( $value, self::CREATORS, $context );

		if ( null !== $creator ) {
			$this->report( $node, $value, $creator, $context );
			return;
		}

		if ( ! $value instanceof Expr\Variable ) {
			return;
		}

		$found = array();

		$is_source = static function ( $assigned ) use ( $context, &$found ) {
			$name = Php80BicExtLocal::call_to( $assigned, self::CREATORS, $context );

			if ( null === $name ) {
				return false;
			}

			$found[ self::CREATORS[ $name ] ] = $name;

			return true;
		};

		$is_read = static function ( Expr\Variable $use, $parent ) use ( &$found ) {
			if ( Php80BicExtLocal::plain_read( $use, $parent ) ) {
				return true;
			}

			if ( ! $parent instanceof Node\Arg || $parent->unpack ) {
				return false;
			}

			$call = $parent->getAttribute( 'parent' );

			if ( ! $call instanceof Expr\FuncCall ) {
				return false;
			}

			$function = InternalFunctions::global_name( $call->name );

			if ( null === $function ) {
				return false;
			}

			if ( isset( self::ONLY_LOOK[ $function ] ) ) {
				return true;
			}

			$position = array_search( $parent, $call->args, true );

			foreach ( array_unique( self::CREATORS ) as $prefix ) {
				if ( 0 !== strpos( $function, $prefix ) ) {
					continue;
				}

				if ( 'openssl_' === $prefix ) {
					return 0 === $position || isset( self::OPENSSL_READ_ANYWHERE[ $function ] );
				}

				// xml_parse_into_struct() writes to its third and fourth arguments.
				if ( 'xml_' === $prefix && 'xml_parse_into_struct' === $function ) {
					return 0 === $position;
				}

				// xml_* functions are not xmlwriter_* ones and vice versa.
				if ( 'xml_' === $prefix && 0 === strpos( $function, 'xmlwriter_' ) ) {
					continue;
				}

				return true;
			}

			return false;
		};

		if ( ! Php80BicExtLocal::only_assigned( $value, $is_source, $is_read ) || 1 !== count( $found ) ) {
			return;
		}

		$this->report( $node, $value, reset( $found ), $context );
	}

	/**
	 * Whether the is_resource() call is part of a boolean expression that
	 * also accepts the object PHP 8 returns, as code written for both 7.4 and
	 * 8.x does: ( is_resource( $im ) || $im instanceof GdImage ), or
	 * is_object( $im ), or a comparison of it with false, in the same
	 * condition or in another condition of the same if / elseif chain.
	 *
	 * @param Expr\FuncCall $call    The is_resource() call.
	 * @param Expr          $value   Its argument.
	 * @param Context       $context Scan state.
	 * @return bool
	 */
	private static function also_checks_object( Expr\FuncCall $call, Expr $value, Context $context ) {
		$top     = $call;
		$current = $call->getAttribute( 'parent' );

		while ( $current instanceof Expr\BooleanNot || $current instanceof Expr\BinaryOp\BooleanOr || $current instanceof Expr\BinaryOp\BooleanAnd || $current instanceof Expr\BinaryOp\LogicalOr || $current instanceof Expr\BinaryOp\LogicalAnd ) {
			$top     = $current;
			$current = $current->getAttribute( 'parent' );
		}

		$search = array( $top );

		// if ( is_resource( $im ) ) { ... } elseif ( $im instanceof GdImage ) { ... }
		$if = $current instanceof Node\Stmt\ElseIf_ ? $current->getAttribute( 'parent' ) : $current;

		if ( $if instanceof Node\Stmt\If_ ) {
			$search = array( $if->cond );

			foreach ( $if->elseifs as $elseif ) {
				$search[] = $elseif->cond;
			}
		}

		if ( array( $call ) === $search ) {
			return false;
		}

		$text   = $context->text( $value->getStartFilePos(), $value->getEndFilePos() );
		$same   = static function ( $other ) use ( $context, $text ) {
			return $other instanceof Expr && $context->text( $other->getStartFilePos(), $other->getEndFilePos() ) === $text;
		};
		$finder = new NodeFinder();

		foreach ( $finder->find( $search, static function ( $node ) {
			return $node instanceof Expr\Instanceof_ || $node instanceof Expr\FuncCall || $node instanceof Expr\BinaryOp\Identical || $node instanceof Expr\BinaryOp\NotIdentical;
		} ) as $node ) {
			if ( $node instanceof Expr\Instanceof_ && $same( $node->expr ) ) {
				return true;
			}

			if ( $node instanceof Expr\FuncCall && in_array( InternalFunctions::global_name( $node->name ), array( 'is_object', 'is_a', 'get_class' ), true ) && isset( $node->args[0] ) && $node->args[0] instanceof Node\Arg && $same( $node->args[0]->value ) ) {
				return true;
			}

			if ( ( $node instanceof Expr\BinaryOp\Identical || $node instanceof Expr\BinaryOp\NotIdentical ) && ( $same( $node->left ) || $same( $node->right ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Records the finding and its fix.
	 *
	 * @param Expr\FuncCall $call    The is_resource() call.
	 * @param Expr          $value   Its argument.
	 * @param string        $creator Function that made the value.
	 * @param Context       $context Scan state.
	 */
	private function report( Expr\FuncCall $call, Expr $value, $creator, Context $context ) {
		$argument = $context->text( $value->getStartFilePos(), $value->getEndFilePos() );

		$finding          = $context->report( 'call.is_resource_on_object', $call, array( 'changed' => '8.0' ) );
		$finding->subject = 'is_resource( ' . $argument . ' ) on ' . $creator . '()';
		$finding->instead = 'false !== ' . $argument;
		$finding->fix     = array(
			'kind'  => 'replace',
			'start' => $call->getStartFilePos(),
			'end'   => $call->getEndFilePos(),
			'text'  => '( false !== ' . $argument . ' )',
		);
	}
}
