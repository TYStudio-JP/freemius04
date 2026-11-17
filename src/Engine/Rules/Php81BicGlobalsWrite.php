<?php
/**
 * Writes to the $GLOBALS array as a whole (tranche 8.1-bic).
 *
 * @package UpgradeWarden
 */

namespace TYStudio\UpgradeWarden\Engine\Rules;

use TYStudio\UpgradeWarden\Engine\Context;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr;

defined( 'ABSPATH' ) || exit;

/**
 * Rule id: syntax.globals_whole_write. Breaks in 8.1.
 *
 *     $GLOBALS = $saved;          compile error from 8.1
 *     $GLOBALS += $more;          compile error from 8.1
 *     $GLOBALS[] = $value;        compile error from 8.1 ("Cannot append to $GLOBALS")
 *     $all = &$GLOBALS;           compile error from 8.1
 *     unset( $GLOBALS );          compile error from 8.1
 *     list( $GLOBALS ) = $values; compile error from 8.1 (also [ $GLOBALS ] = ...)
 *     foreach ( $a as $GLOBALS )  compile error from 8.1
 *     $GLOBALS++;                 compile error from 8.1
 *     array_pop( $GLOBALS );      Error from 8.1: cannot be passed by reference
 *
 * Writing single elements ($GLOBALS['name'] = 1, unset( $GLOBALS['name'] )),
 * reading the whole array, copying it and iterating over it (even by
 * reference, which 8.1 still runs) are fine and are not reported.
 *
 * The compile errors stop the whole file, so no check around the code helps.
 * The by-reference error is thrown when the call runs, so a PHP version check
 * around the call marks it as guarded. Only internal functions are looked at
 * for that: BY_REFERENCE lists the parameters that every runtime from 8.1 to
 * 8.5 takes by reference only (Reflection, prefer-ref parameters left out).
 *
 * Not reported: copying $GLOBALS and writing to the copy. Before 8.1 that
 * changed the global variables, from 8.1 it does not (fixture
 * runtime.globals_copy_write), but whether the copy is written to is a
 * matter of what happens to the variable later.
 */
final class Php81BicGlobalsWrite implements Rule {

	/**
	 * Asked of Context::function_is_guarded() to learn about a version check
	 * only. Not a valid function name, so no function_exists() matches it.
	 */
	const VERSION_GUARD = '8.1-bic: version check only';

	/**
	 * Internal function => positions (from 0) of the parameters taken by
	 * reference; "*N" means the variadic parameter at N and all after it.
	 */
	const BY_REFERENCE = array(
		'array_pop'                   => '0',
		'array_push'                  => '0',
		'array_shift'                 => '0',
		'array_splice'                => '0',
		'array_unshift'               => '0',
		'array_walk'                  => '0',
		'array_walk_recursive'        => '0',
		'arsort'                      => '0',
		'asort'                       => '0',
		'curl_multi_exec'             => '1',
		'curl_multi_info_read'        => '1',
		'dns_get_mx'                  => '1 2',
		'dns_get_record'              => '2 3',
		'end'                         => '0',
		'exec'                        => '1 2',
		'exif_thumbnail'              => '1 2 3',
		'flock'                       => '2',
		'fscanf'                      => '*2',
		'fsockopen'                   => '2 3',
		'getimagesize'                => '1',
		'getimagesizefromstring'      => '1',
		'getmxrr'                     => '1 2',
		'getopt'                      => '2',
		'headers_sent'                => '0 1',
		'is_callable'                 => '2',
		'krsort'                      => '0',
		'ksort'                       => '0',
		'mb_convert_variables'        => '2 *3',
		'mb_ereg'                     => '2',
		'mb_eregi'                    => '2',
		'mb_parse_str'                => '1',
		'mysqli_poll'                 => '0 1 2',
		'mysqli_stmt_bind_param'      => '*2',
		'mysqli_stmt_bind_result'     => '*1',
		'natcasesort'                 => '0',
		'natsort'                     => '0',
		'next'                        => '0',
		'openssl_cms_read'            => '1',
		'openssl_csr_export'          => '1',
		'openssl_csr_new'             => '1',
		'openssl_encrypt'             => '5',
		'openssl_open'                => '1',
		'openssl_pkcs12_export'       => '1',
		'openssl_pkcs12_read'         => '1',
		'openssl_pkcs7_read'          => '1',
		'openssl_pkey_export'         => '1',
		'openssl_private_decrypt'     => '1',
		'openssl_private_encrypt'     => '1',
		'openssl_public_decrypt'      => '1',
		'openssl_public_encrypt'      => '1',
		'openssl_random_pseudo_bytes' => '1',
		'openssl_seal'                => '1 2 5',
		'openssl_sign'                => '1',
		'openssl_x509_export'         => '1',
		'parse_str'                   => '1',
		'passthru'                    => '1',
		'pfsockopen'                  => '2 3',
		'preg_filter'                 => '4',
		'preg_match'                  => '2',
		'preg_match_all'              => '2',
		'preg_replace'                => '4',
		'preg_replace_callback'       => '4',
		'preg_replace_callback_array' => '3',
		'prev'                        => '0',
		'proc_open'                   => '2',
		'reset'                       => '0',
		'rsort'                       => '0',
		'settype'                     => '0',
		'shuffle'                     => '0',
		'similar_text'                => '2',
		'sort'                        => '0',
		'sscanf'                      => '*2',
		'str_ireplace'                => '3',
		'str_replace'                 => '3',
		'stream_select'               => '0 1 2',
		'stream_socket_accept'        => '2',
		'stream_socket_client'        => '1 2',
		'stream_socket_recvfrom'      => '3',
		'stream_socket_server'        => '1 2',
		'system'                      => '1',
		'uasort'                      => '0',
		'uksort'                      => '0',
		'usort'                       => '0',
		'xml_parse_into_struct'       => '2 3',
	);

	/**
	 * {@inheritDoc}
	 */
	public function node_types() {
		return array( Expr\Variable::class );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Expr\Variable $node    Variable.
	 * @param Context       $context Scan state.
	 */
	public function inspect( Node $node, Context $context ) {
		if ( 'GLOBALS' !== $node->name ) {
			return;
		}

		$parent = $node->getAttribute( 'parent' );

		if ( $parent instanceof Expr\Assign || $parent instanceof Expr\AssignOp ) {
			if ( $parent->var === $node ) {
				$this->report( $parent, '$GLOBALS = ...', $context );
			}

			return;
		}

		if ( $parent instanceof Expr\AssignRef ) {
			$this->report( $parent, $parent->var === $node ? '$GLOBALS = &...' : '&$GLOBALS', $context );
			return;
		}

		if ( $parent instanceof Node\Stmt\Unset_ ) {
			$this->report( $parent, 'unset( $GLOBALS )', $context );
			return;
		}

		if ( $parent instanceof Expr\ArrayDimFetch && null === $parent->dim && $parent->var === $node ) {
			$write = $parent->getAttribute( 'parent' );

			if ( ( $write instanceof Expr\Assign || $write instanceof Expr\AssignOp || $write instanceof Expr\AssignRef ) && $write->var === $parent ) {
				$this->report( $write, '$GLOBALS[] = ...', $context );
			}

			return;
		}

		if ( $parent instanceof Expr\PreInc || $parent instanceof Expr\PostInc || $parent instanceof Expr\PreDec || $parent instanceof Expr\PostDec ) {
			$this->report( $parent, '$GLOBALS++', $context );
			return;
		}

		if ( $parent instanceof Node\Stmt\Foreach_ ) {
			if ( $parent->valueVar === $node || $parent->keyVar === $node ) {
				$this->report( $parent, 'foreach ( ... as $GLOBALS )', $context );
			}

			return;
		}

		if ( $parent instanceof Expr\ArrayItem && $parent->value === $node ) {
			$target = self::destructuring_target( $parent );

			if ( null !== $target ) {
				$this->report( $target, 'list( $GLOBALS ) = ...', $context );
			}

			return;
		}

		if ( $parent instanceof Node\Arg ) {
			$this->argument( $parent, $context );
		}
	}

	/**
	 * The assignment or foreach that writes into list( ... ) or [ ... ]
	 * holding this item, or null when the array is only read.
	 *
	 * @param Expr\ArrayItem $item Item whose value is $GLOBALS.
	 * @return Node|null
	 */
	private static function destructuring_target( Expr\ArrayItem $item ) {
		$current = $item;

		while ( true ) {
			$list = $current->getAttribute( 'parent' );

			if ( ! $list instanceof Expr\List_ && ! $list instanceof Expr\Array_ ) {
				return null;
			}

			$above = $list->getAttribute( 'parent' );

			if ( ( $above instanceof Expr\Assign || $above instanceof Expr\AssignRef ) && $above->var === $list ) {
				return $above;
			}

			if ( $above instanceof Node\Stmt\Foreach_ && $above->valueVar === $list ) {
				return $above;
			}

			if ( ! $above instanceof Expr\ArrayItem || $above->value !== $list ) {
				return null;
			}

			$current = $above;
		}
	}

	/**
	 * Reports $GLOBALS passed to a by-reference parameter of an internal function.
	 *
	 * @param Node\Arg $arg     The argument that is $GLOBALS.
	 * @param Context  $context Scan state.
	 */
	private function argument( Node\Arg $arg, Context $context ) {
		$call = $arg->getAttribute( 'parent' );

		if ( $arg->unpack || null !== $arg->name || ! $call instanceof Expr\FuncCall ) {
			return;
		}

		$name = InternalFunctions::global_name( $call->name );

		if ( null === $name || ! isset( self::BY_REFERENCE[ $name ] ) || isset( $context->declared_functions[ $name ] ) ) {
			return;
		}

		$position = null;

		foreach ( $call->args as $index => $candidate ) {
			if ( ! $candidate instanceof Node\Arg || $candidate->unpack || null !== $candidate->name ) {
				return;
			}

			if ( $candidate === $arg ) {
				$position = $index;
				break;
			}
		}

		if ( null === $position ) {
			return;
		}

		$by_reference = false;

		foreach ( explode( ' ', self::BY_REFERENCE[ $name ] ) as $entry ) {
			if ( '*' === $entry[0] ? $position >= (int) substr( $entry, 1 ) : $position === (int) $entry ) {
				$by_reference = true;
			}
		}

		if ( $by_reference ) {
			$finding          = $this->report( $call, $name . '( $GLOBALS )', $context );
			$finding->guarded = $context->function_is_guarded( self::VERSION_GUARD );
		}
	}

	/**
	 * Records a finding.
	 *
	 * @param Node    $node    Node to report.
	 * @param string  $subject What was written.
	 * @param Context $context Scan state.
	 * @return \TYStudio\UpgradeWarden\Engine\Finding
	 */
	private function report( Node $node, $subject, Context $context ) {
		$finding          = $context->report( 'syntax.globals_whole_write', $node, array( 'breaks' => '8.1' ) );
		$finding->subject = $subject;
		$finding->instead = '$GLOBALS[\'name\'] = $value (or unset( $GLOBALS[\'name\'] )) for each variable';

		return $finding;
	}
}
