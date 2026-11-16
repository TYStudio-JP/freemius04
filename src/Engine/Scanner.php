<?php
/**
 * Scans the source of one PHP file.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine;

use TYStudio\UpgradeWarden\Vendor\PhpParser\ErrorHandler\Collecting;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Name;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Stmt\Function_;
use TYStudio\UpgradeWarden\Vendor\PhpParser\NodeFinder;
use TYStudio\UpgradeWarden\Vendor\PhpParser\NodeTraverser;
use TYStudio\UpgradeWarden\Vendor\PhpParser\NodeVisitor\NameResolver;
use TYStudio\UpgradeWarden\Vendor\PhpParser\NodeVisitor\ParentConnectingVisitor;
use TYStudio\UpgradeWarden\Vendor\PhpParser\ParserFactory;
use TYStudio\UpgradeWarden\Vendor\PhpParser\PhpVersion;

defined( 'ABSPATH' ) || exit;

/**
 * The scanner works on a syntax tree, not on text patterns. It therefore tells
 * a call to each() from a method named each(), sees through namespaces and
 * imports, and knows when a call sits behind a function_exists() check.
 *
 * It has no WordPress calls and reads nothing but the string it is given.
 */
final class Scanner {

	/**
	 * The grammar used for files the newest grammar rejects. PHP 7.4 is the
	 * last version that accepts the syntax PHP 8.0 removed.
	 */
	const LEGACY_GRAMMAR = '7.4';

	/**
	 * Catalog of internal functions and constants.
	 *
	 * @var Catalog
	 */
	private $catalog;

	/**
	 * Rules by the node class they want to see.
	 *
	 * @var array<string,Rules\Rule[]>
	 */
	private $rules = array();

	/**
	 * Constructor.
	 *
	 * @param Catalog           $catalog Catalog.
	 * @param Rules\Rule[]|null $rules   Rules to apply; all of them by default.
	 */
	public function __construct( Catalog $catalog, ?array $rules = null ) {
		$this->catalog = $catalog;

		foreach ( null === $rules ? self::default_rules() : $rules as $rule ) {
			foreach ( $rule->node_types() as $type ) {
				$this->rules[ $type ][] = $rule;
			}
		}
	}

	/**
	 * Every rule the engine has.
	 *
	 * @return Rules\Rule[]
	 */
	public static function default_rules() {
		// Every class file in Rules/ except the interface is a rule. A new rule
		// only needs its own file, so no shared list has to be edited.
		$rules = array();
		$files = glob( __DIR__ . '/Rules/*.php' );

		sort( $files );

		foreach ( $files as $file ) {
			$name = basename( $file, '.php' );

			if ( 'Rule' === $name ) {
				continue;
			}

			$class   = __NAMESPACE__ . '\\Rules\\' . $name;
			$rules[] = new $class();
		}

		return $rules;
	}

	/**
	 * Scans PHP source code.
	 *
	 * @param string   $code               Contents of a PHP file.
	 * @param string[] $declared_elsewhere Lower-case names of global functions
	 *                                     the same plugin or theme declares in
	 *                                     its other files.
	 * @return ScanResult
	 */
	public function scan( $code, array $declared_elsewhere = array() ) {
		$result = new ScanResult();

		try {
			$factory = new ParserFactory();
			$errors  = new Collecting();
			$ast     = $factory->createForNewestSupportedVersion()->parse( $code, $errors );

			if ( null === $ast || $errors->hasErrors() ) {
				$rejected = $errors->hasErrors() ? $errors->getErrors()[0]->getMessage() : 'The file could not be parsed.';
				$errors   = new Collecting();
				$ast      = $factory->createForVersion( PhpVersion::fromString( self::LEGACY_GRAMMAR ) )->parse( $code, $errors );

				if ( null === $ast || $errors->hasErrors() ) {
					$result->grammar     = ScanResult::GRAMMAR_NONE;
					$result->parse_error = $rejected;

					return $result;
				}

				$result->grammar     = ScanResult::GRAMMAR_LEGACY;
				$result->parse_error = $rejected;
			}

			$resolver = new NodeTraverser();
			$resolver->addVisitor( new NameResolver( null, array( 'replaceNodes' => false ) ) );
			// Gives every node a "parent" attribute, so a rule can look at what
			// surrounds a node without the walker having to know about it.
			$resolver->addVisitor( new ParentConnectingVisitor() );
			$ast = $resolver->traverse( $ast );

			$context = new Context( $code, $this->catalog );
			$finder  = new NodeFinder();

			foreach ( $finder->findInstanceOf( $ast, Function_::class ) as $function ) {
				if ( isset( $function->namespacedName ) && $function->namespacedName instanceof Name && 1 === count( $function->namespacedName->getParts() ) ) {
					$context->declared_functions[ strtolower( $function->namespacedName->getLast() ) ] = true;
				}
			}

			$result->declared_functions = array_keys( $context->declared_functions );

			foreach ( $declared_elsewhere as $name ) {
				$context->declared_functions[ strtolower( $name ) ] = true;
			}

			$walker = new NodeTraverser();
			$walker->addVisitor( new Walker( $context, $this->rules ) );
			$walker->traverse( $ast );
		} catch ( \Throwable $error ) {
			$result->grammar     = ScanResult::GRAMMAR_NONE;
			$result->parse_error = $error->getMessage();

			return $result;
		}

		usort(
			$context->findings,
			static function ( Finding $a, Finding $b ) {
				return $a->start === $b->start ? strcmp( $a->rule, $b->rule ) : $a->start - $b->start;
			}
		);

		$result->findings = $context->findings;

		return $result;
	}
}
