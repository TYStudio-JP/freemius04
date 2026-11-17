<?php
/**
 * Declarations of one file, for the PHP 8.0 rules (tranche 8.0-bic-core) that
 * have to compare a use with a declaration that may come later in the file.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\ErrorHandler\Collecting;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\NodeFinder;
use TYStudio\UpgradeWarden\Vendor\PhpParser\NodeTraverser;
use TYStudio\UpgradeWarden\Vendor\PhpParser\NodeVisitor\NameResolver;
use TYStudio\UpgradeWarden\Vendor\PhpParser\ParserFactory;
use TYStudio\UpgradeWarden\Vendor\PhpParser\PhpVersion;

defined( 'ABSPATH' ) || exit;

/**
 * Not a rule of its own: it reports nothing and asks for no nodes. It lives in
 * Rules/ only so that the 8.0-bic-core rules can share it without a change to
 * a shared file.
 *
 * The file is parsed a second time, the way the scanner parses it, the first
 * time a rule asks, and only for the file being scanned.
 */
final class Php80BicCoreIndex implements Rule {

	/**
	 * The file the index belongs to.
	 *
	 * @var Context|null
	 */
	private static $context = null;

	/**
	 * Index of that file.
	 *
	 * @var array
	 */
	private static $index = array();

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Node    $node    Never called.
	 * @param Context $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
	}

	/**
	 * Classes, interfaces and traits of the file by lower-case, fully
	 * qualified name, and functions the same way.
	 *
	 * @param Context $context Scan state of the file.
	 * @return array{classes: array<string,Node\Stmt\ClassLike>, functions: array<string,Node\Stmt\Function_>, constants: array<string,Node\Expr\ConstFetch[]>, silenced: int[]} silenced: start offsets of @ outside any function
	 */
	public static function of( Context $context ) {
		if ( self::$context === $context ) {
			return self::$index;
		}

		self::$context = $context;
		self::$index   = array(
			'classes'   => array(),
			'functions' => array(),
			'constants' => array(),
			'silenced'  => array(),
		);

		$ast = self::parse( $context->code );

		if ( null === $ast ) {
			return self::$index;
		}

		$finder = new NodeFinder();

		foreach ( $finder->findInstanceOf( $ast, Node\Stmt\ClassLike::class ) as $class ) {
			if ( null !== $class->name ) {
				$name = $class->namespacedName instanceof Node\Name ? $class->namespacedName->toString() : $class->name->toString();

				self::$index['classes'][ strtolower( $name ) ] = $class;
			}
		}

		foreach ( $finder->findInstanceOf( $ast, Node\Stmt\Function_::class ) as $function ) {
			$name = $function->namespacedName instanceof Node\Name ? $function->namespacedName->toString() : $function->name->toString();

			self::$index['functions'][ strtolower( $name ) ] = $function;
		}

		foreach ( $finder->findInstanceOf( $ast, Node\Expr\ConstFetch::class ) as $fetch ) {
			self::$index['constants'][ strtolower( $fetch->name->getLast() ) ][] = $fetch;
		}

		self::$index['silenced'] = self::top_level_silences( $ast );

		return self::$index;
	}

	/**
	 * Start offsets of the @ operators that are not inside a function,
	 * method or closure.
	 *
	 * @param Node[] $nodes Nodes to look through.
	 * @return int[]
	 */
	private static function top_level_silences( array $nodes ) {
		$found = array();

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof Node || $node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike ) {
				continue;
			}

			if ( $node instanceof Node\Expr\ErrorSuppress ) {
				$found[] = $node->getStartFilePos();
			}

			foreach ( $node->getSubNodeNames() as $name ) {
				$sub = $node->$name;

				if ( $sub instanceof Node ) {
					$sub = array( $sub );
				}

				if ( is_array( $sub ) ) {
					$found = array_merge( $found, self::top_level_silences( $sub ) );
				}
			}
		}

		return $found;
	}

	/**
	 * A cheap test before the index is built: whether the source text could
	 * declare a class, interface or trait whose fully qualified name ends in
	 * the last part of the given name.
	 *
	 * @param Context $context Scan state.
	 * @param string  $name    Class name, qualified or not.
	 * @return bool
	 */
	public static function may_declare( Context $context, $name ) {
		$parts = explode( '\\', $name );
		$short = end( $parts );

		return '' !== $short && 1 === preg_match( '/\\b(?:class|interface|trait)\\s+' . preg_quote( $short, '/' ) . '\\b/i', $context->code );
	}

	/**
	 * Lower-case, fully qualified name a class reference stands for, or null
	 * for self, parent and static.
	 *
	 * @param Node\Name $name Name as written.
	 * @return string|null
	 */
	public static function class_name( Node\Name $name ) {
		if ( $name->isSpecialClassName() ) {
			return null;
		}

		$resolved = $name->getAttribute( 'resolvedName' );

		return strtolower( $resolved instanceof Node\Name ? $resolved->toString() : $name->toString() );
	}

	/**
	 * A method a class-like declares itself, by name.
	 *
	 * @param Node\Stmt\ClassLike $class Class, interface or trait.
	 * @param string              $name  Method name.
	 * @return Node\Stmt\ClassMethod|null
	 */
	public static function method( Node\Stmt\ClassLike $class, $name ) {
		return $class->getMethod( strtolower( $name ) );
	}

	/**
	 * Why a method cannot stand in for another one, judged on the parameter
	 * list alone (count, defaults, variadics, by-reference), or null when
	 * nothing there conflicts. Types are not compared.
	 *
	 * @param Node\Stmt\ClassMethod $base Method that is overridden or implemented.
	 * @param Node\Stmt\ClassMethod $impl Method that overrides or implements it.
	 * @return string|null
	 */
	public static function arity_conflict( Node\Stmt\ClassMethod $base, Node\Stmt\ClassMethod $impl ) {
		$base_shape = self::shape( $base );
		$impl_shape = self::shape( $impl );

		if ( $impl_shape['required'] > $base_shape['required'] ) {
			return 'requires ' . $impl_shape['required'] . ' arguments where the original requires ' . $base_shape['required'];
		}

		if ( $base_shape['variadic'] && ! $impl_shape['variadic'] ) {
			return 'drops the variadic parameter';
		}

		if ( ! $impl_shape['variadic'] && $impl_shape['count'] < $base_shape['count'] ) {
			return 'accepts fewer parameters than the original';
		}

		$shared = min( count( $base->params ), count( $impl->params ) );

		for ( $at = 0; $at < $shared; $at++ ) {
			if ( $base->params[ $at ]->variadic || $impl->params[ $at ]->variadic ) {
				break;
			}

			if ( $base->params[ $at ]->byRef !== $impl->params[ $at ]->byRef ) {
				return 'differs in passing parameter ' . ( $at + 1 ) . ' by reference';
			}
		}

		return null;
	}

	/**
	 * Number of required parameters, number of parameters and whether the
	 * last one is variadic.
	 *
	 * @param Node\FunctionLike $function Function or method.
	 * @return array{required: int, count: int, variadic: bool}
	 */
	public static function shape( Node\FunctionLike $function ) {
		$required = 0;
		$variadic = false;
		$count    = 0;

		foreach ( $function->getParams() as $index => $param ) {
			if ( $param->variadic ) {
				$variadic = true;
				continue;
			}

			++$count;

			// A parameter with a default before a required one is required too.
			if ( null === $param->default ) {
				$required = $index + 1;
			}
		}

		return array(
			'required' => $required,
			'count'    => $count,
			'variadic' => $variadic,
		);
	}

	/**
	 * Parses the code the way the scanner does: newest grammar first, then PHP 7.4.
	 *
	 * @param string $code Source code.
	 * @return Node[]|null
	 */
	private static function parse( $code ) {
		try {
			$factory = new ParserFactory();
			$errors  = new Collecting();
			$ast     = $factory->createForNewestSupportedVersion()->parse( $code, $errors );

			if ( null === $ast || $errors->hasErrors() ) {
				$errors = new Collecting();
				$ast    = $factory->createForVersion( PhpVersion::fromString( '7.4' ) )->parse( $code, $errors );

				if ( null === $ast || $errors->hasErrors() ) {
					return null;
				}
			}

			$resolver = new NodeTraverser();
			$resolver->addVisitor( new NameResolver( null, array( 'replaceNodes' => false ) ) );

			return $resolver->traverse( $ast );
		} catch ( \Throwable $error ) {
			return null;
		}
	}
}
