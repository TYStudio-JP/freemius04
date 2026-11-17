<?php
/**
 * file() with the FILE_APPEND flag, a ValueError from PHP 8.3.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: argument.file_flag_append.
 *
 * FILE_APPEND belongs to file_put_contents(); file() ignored it until 8.2 and
 * throws a ValueError from 8.3. Other flags file() does not know were already
 * a ValueError on 8.0 (seen on the runtimes), so only FILE_APPEND is reported,
 * and only when the flags are written as constants joined with "|" or "+".
 */
final class Php83FileFlags implements Rule {

	/**
	 * A name no function has, so that only a version check counts as a guard.
	 */
	const VERSION_GUARD = '8.3: version check only';

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Node\Expr\FuncCall::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node\Expr\FuncCall $node    Function call.
	 * @param Context            $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( 'file' !== InternalFunctions::global_name( $node->name ) || isset( $context->declared_functions['file'] ) ) {
			return;
		}

		$flags = Php83Range::argument( $node, 1, 'flags' );

		if ( ! $flags instanceof Node\Expr || ! self::has_append( $flags ) ) {
			return;
		}

		$finding          = $context->report( 'argument.file_flag_append', $node, array( 'breaks' => '8.3' ) );
		$finding->subject = 'file( ..., FILE_APPEND )';
		$finding->instead = 'leave FILE_APPEND out; file() never appended anything';
		$finding->guarded = $context->function_is_guarded( self::VERSION_GUARD );
	}

	/**
	 * Whether a flags expression made only of constants and "|" or "+"
	 * contains FILE_APPEND.
	 *
	 * @param Node\Expr $expr Flags.
	 * @return bool
	 */
	private static function has_append( Node\Expr $expr ) {
		$found = false;
		$todo  = array( $expr );

		while ( array() !== $todo ) {
			$part = array_pop( $todo );

			if ( $part instanceof Node\Expr\BinaryOp\BitwiseOr || $part instanceof Node\Expr\BinaryOp\Plus ) {
				$todo[] = $part->left;
				$todo[] = $part->right;
				continue;
			}

			if ( ! $part instanceof Node\Expr\ConstFetch ) {
				return false;
			}

			$resolved = $part->name->getAttribute( 'resolvedName' );
			$name     = $resolved instanceof Node\Name ? $resolved : $part->name;

			if ( 1 === count( $name->getParts() ) && 'FILE_APPEND' === $name->getLast() ) {
				$found = true;
			}
		}

		return $found;
	}
}
