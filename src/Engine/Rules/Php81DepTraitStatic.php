<?php
/**
 * Static members used directly on a trait.
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\StaticCall;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\StaticPropertyFetch;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: call.static_on_trait. Deprecated in 8.1.
 *
 * Helpers::format() and Helpers::$cache, where Helpers is a trait. Whether a
 * name is a trait is known only when the trait is declared in the same file;
 * a trait from another file is not reported. A callable such as
 * array( 'Helpers', 'format' ) raises nothing (fixture
 * runtime.trait_static_callable) and is not reported.
 */
final class Php81DepTraitStatic implements Rule {

	/**
	 * The scan the trait names below belong to.
	 *
	 * @var Context|null
	 */
	private $context;

	/**
	 * Lower-case full names of the traits declared in the file being scanned.
	 *
	 * @var array<string,true>
	 */
	private $traits = array();

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( StaticCall::class, StaticPropertyFetch::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param StaticCall|StaticPropertyFetch $node    Static call or property.
	 * @param Context                        $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( ! $node->class instanceof Node\Name || $node->class->isSpecialClassName() ) {
			return;
		}

		if ( $this->context !== $context ) {
			$this->context = $context;
			$this->traits  = self::traits_in( $context->code );
		}

		if ( array() === $this->traits ) {
			return;
		}

		$resolved = $node->class->getAttribute( 'resolvedName' );
		$resolved = $resolved instanceof Node\Name ? $resolved : $node->class;

		if ( ! isset( $this->traits[ strtolower( $resolved->toString() ) ] ) ) {
			return;
		}

		$finding          = $context->report( 'call.static_on_trait', $node, array( 'deprecated' => '8.1' ) );
		$finding->subject = $resolved->toString();
		$finding->guarded = $context->function_is_guarded( Php81DepArguments::VERSION_GUARD );
	}

	/**
	 * Full names of the traits a file declares.
	 *
	 * The rules see one node at a time, and a trait may be declared below the
	 * place it is used, so the names are read from the tokens of the file.
	 *
	 * @param string $code Source of the file.
	 * @return array<string,true> Lower-case names, without a leading backslash.
	 */
	private static function traits_in( $code ) {
		$traits = array();

		if ( false === stripos( $code, 'trait' ) ) {
			return $traits;
		}

		$tokens    = token_get_all( $code );
		$count     = count( $tokens );
		$namespace = '';
		// PHP 8.0 reads Foo\Bar as one token, PHP 7.4 as three.
		$name_tokens = array( T_STRING, T_NS_SEPARATOR );

		if ( defined( 'T_NAME_QUALIFIED' ) ) {
			$name_tokens[] = T_NAME_QUALIFIED;
		}

		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! is_array( $tokens[ $i ] ) || ( T_TRAIT !== $tokens[ $i ][0] && T_NAMESPACE !== $tokens[ $i ][0] ) ) {
				continue;
			}

			$kind = $tokens[ $i ][0];
			$name = '';

			for ( $j = $i + 1; $j < $count; $j++ ) {
				if ( is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}

				if ( is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], $name_tokens, true ) ) {
					$name .= $tokens[ $j ][1];
					continue;
				}

				break;
			}

			$next = $j < $count ? $tokens[ $j ] : null;

			if ( T_NAMESPACE === $kind ) {
				// "namespace Foo;" or "namespace Foo {" or "namespace {". Anything
				// else is the namespace operator inside an expression.
				if ( ( ';' === $next || '{' === $next ) && '\\' !== substr( $name, 0, 1 ) ) {
					$namespace = $name;
				}
			} elseif ( '' !== $name && false === strpos( $name, '\\' ) ) {
				$traits[ strtolower( '' === $namespace ? $name : $namespace . '\\' . $name ) ] = true;
			}

			$i = $j - 1;
		}

		return $traits;
	}
}
