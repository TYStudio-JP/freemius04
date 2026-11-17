<?php
/**
 * The DOM classes and the ZipArchive constant that PHP 8.0 removed (tranche
 * 8.0-bic-ext).
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Stmt;

defined( 'ABSPATH' ) || exit;

/**
 * Rule ids, both an Error from 8.0:
 *
 * - class.dom_removed_class          new DOMConfiguration(), or a class that extends
 *                                    DOMNameList, DomImplementationList,
 *                                    DOMConfiguration, DomErrorHandler,
 *                                    DOMImplementationSource, DOMLocator,
 *                                    DOMUserDataHandler or DOMTypeInfo. They
 *                                    existed (empty) on 7.4 and are gone from 8.0.
 *                                    DomError is listed in the notes but did not
 *                                    exist on the 7.4 runtime, so it is not reported.
 * - constant.ziparchive_opsys_z_cpm  ZipArchive::OPSYS_Z_CPM; the fix writes
 *                                    ZipArchive::OPSYS_CPM, the same value on 7.4.
 *
 * instanceof, type declarations and catch blocks that name these classes do
 * not fail on 8.0 and are not reported.
 */
final class Php80BicExtRemovedClasses implements Rule {

	/**
	 * Lower-case names of the removed DOM classes that 7.4 had.
	 */
	const DOM = array(
		'domnamelist'             => true,
		'domimplementationlist'   => true,
		'domconfiguration'        => true,
		'domerrorhandler'         => true,
		'domimplementationsource' => true,
		'domlocator'              => true,
		'domuserdatahandler'      => true,
		'domtypeinfo'             => true,
	);

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Expr\New_::class, Stmt\Class_::class, Expr\ClassConstFetch::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node    $node    New, class declaration or class constant.
	 * @param Context $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( $node instanceof Expr\ClassConstFetch ) {
			if ( $node->name instanceof Node\Identifier && 'OPSYS_Z_CPM' === $node->name->toString() && 'ziparchive' === Php80BicExtLocal::class_name( $node->class ) ) {
				$finding          = $context->report( 'constant.ziparchive_opsys_z_cpm', $node, array( 'breaks' => '8.0' ) );
				$finding->subject = 'ZipArchive::OPSYS_Z_CPM';
				$finding->instead = 'ZipArchive::OPSYS_CPM';
				$finding->fix     = array(
					'kind'  => 'replace',
					'start' => $node->name->getStartFilePos(),
					'end'   => $node->name->getEndFilePos(),
					'text'  => 'OPSYS_CPM',
				);
			}

			return;
		}

		$name = $node instanceof Expr\New_ ? $node->class : $node->extends;
		$dom  = Php80BicExtLocal::class_name( $name );

		if ( null === $dom || ! isset( self::DOM[ $dom ] ) ) {
			return;
		}

		$finding          = $context->report( 'class.dom_removed_class', $node instanceof Expr\New_ ? $node : $name, array( 'breaks' => '8.0' ) );
		$finding->subject = $name->getLast();
		$finding->instead = 'nothing: the class never did anything';
	}
}
