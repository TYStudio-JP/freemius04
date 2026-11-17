<?php
/**
 * exit() and die() with a literal true, false, null, float or array: PHP 8.4
 * gives the argument the type string|int.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\Array_;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\ConstFetch;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\Exit_;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\UnaryMinus;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\UnaryPlus;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Scalar\Float_;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Scalar\Int_;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Stmt\Declare_;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: call.exit_argument_type.
 *
 * Up to 8.3 exit() printed any argument that was not an int, as a string.
 * From 8.4 exit behaves like a function taking string|int:
 *
 *     exit( true );      prints "1" up to 8.3; from 8.4 exits with status 1 and prints nothing  (changed 8.4)
 *     exit( 1.0 );       prints "1" up to 8.3; from 8.4 exits with status 1                    (changed 8.4)
 *     exit( null );      E_DEPRECATED from 8.4 (null to a non-nullable parameter)              (deprecated 8.4)
 *     exit( array() );   printed "Array"; a TypeError from 8.4                                 (breaks 8.4)
 *
 * In a file with declare( strict_types = 1 ) true, false, null and floats are
 * a TypeError from 8.4 as well (breaks 8.4). exit( false ) without strict
 * types prints nothing before and after, and is not reported.
 *
 * Only literals are judged: the type of a variable is not in the source.
 */
final class Php84BicCoreExitArgument implements Rule {

	/**
	 * The context of the file whose declare() asked for strict types. A
	 * declare( strict_types ) must be the first statement, so it is visited
	 * before any exit in the same file.
	 *
	 * @var Context|null
	 */
	private $strict_context = null;

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Declare_::class, Exit_::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Declare_|Exit_ $node    Declare statement or exit.
	 * @param Context        $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( $node instanceof Declare_ ) {
			foreach ( $node->declares as $declare ) {
				if ( 'strict_types' === strtolower( $declare->key->toString() ) && $declare->value instanceof Int_ && 1 === $declare->value->value ) {
					$this->strict_context = $context;
				}
			}

			return;
		}

		if ( null === $node->expr ) {
			return;
		}

		$kind = self::literal_kind( $node->expr );

		if ( null === $kind ) {
			return;
		}

		$strict = $this->strict_context === $context;

		if ( 'array' === $kind || $strict ) {
			$when = array( 'breaks' => '8.4' );
		} elseif ( 'true' === $kind || 'float' === $kind ) {
			$when = array( 'changed' => '8.4' );
		} elseif ( 'null' === $kind ) {
			$when = array( 'deprecated' => '8.4' );
		} else {
			// false without strict types: nothing changes.
			return;
		}

		$word = Exit_::KIND_DIE === $node->getAttribute( 'kind' ) ? 'die' : 'exit';

		$finding          = $context->report( 'call.exit_argument_type', $node, $when );
		$finding->subject = $word . '( ' . $kind . ' )' . ( $strict ? ' with strict_types' : '' );
		$finding->instead = $word . '() with an int status, or with a string to print';
		$finding->guarded = $context->function_is_guarded( '8.4-bic-core: version check only' );
	}

	/**
	 * "true", "false", "null", "float" or "array" for such a literal, or null.
	 *
	 * @param Node $expr Argument of exit.
	 * @return string|null
	 */
	private static function literal_kind( Node $expr ) {
		if ( $expr instanceof UnaryMinus || $expr instanceof UnaryPlus ) {
			return $expr->expr instanceof Float_ ? 'float' : null;
		}

		if ( $expr instanceof Float_ ) {
			return 'float';
		}

		if ( $expr instanceof Array_ ) {
			return 'array';
		}

		if ( $expr instanceof ConstFetch && 1 === count( $expr->name->getParts() ) ) {
			$name = strtolower( $expr->name->getLast() );

			if ( in_array( $name, array( 'true', 'false', 'null' ), true ) ) {
				return $name;
			}
		}

		return null;
	}
}
