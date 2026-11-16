<?php
/**
 * Compiles a PHP file on this runtime without running any of it.
 *
 * PHP compiles a whole file before it executes the first statement. So a
 * "return;" placed as the first statement lets the compiler see everything,
 * with every compile-time error and notice, and then stops at once.
 *
 * The statement has to go after the things PHP insists on seeing first: the
 * declare() statements and a namespace declaration.
 *
 * Works the same on every PHP version, which opcache_compile_file() does not
 * in these runtimes.
 */

function upgw_compile_only( $path, $scratch ) {
	$code   = file_get_contents( $path );
	$tokens = token_get_all( $code );
	$offset = 0;
	$at     = null;
	$count  = count( $tokens );
	$index  = 0;

	// Offset just after the first opening tag.
	for ( ; $index < $count; $index++ ) {
		$token   = $tokens[ $index ];
		$text    = is_array( $token ) ? $token[1] : $token;
		$offset += strlen( $text );

		if ( is_array( $token ) && T_OPEN_TAG === $token[0] ) {
			$at = $offset;
			$index++;
			break;
		}

		if ( is_array( $token ) && T_OPEN_TAG_WITH_ECHO === $token[0] ) {
			// "<?=" first: stop before it instead.
			$code = '<?php return; ?>' . $code;
			$at   = -1;
			break;
		}
	}

	if ( null === $at ) {
		// No PHP in the file.
		return;
	}

	if ( $at >= 0 ) {
		$skip = array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT );

		while ( $index < $count ) {
			$token = $tokens[ $index ];

			if ( is_array( $token ) && in_array( $token[0], $skip, true ) ) {
				$offset += strlen( $token[1] );
				$index++;
				continue;
			}

			if ( ! is_array( $token ) || ( T_DECLARE !== $token[0] && T_NAMESPACE !== $token[0] ) ) {
				break;
			}

			$is_namespace = T_NAMESPACE === $token[0];
			$ended        = false;
			$scan         = $index;
			$length       = 0;

			// Up to and including the ";" or "{" that ends the declaration.
			for ( ; $scan < $count; $scan++ ) {
				$piece   = $tokens[ $scan ];
				$text    = is_array( $piece ) ? $piece[1] : $piece;
				$length += strlen( $text );

				if ( ';' === $piece || ( $is_namespace && '{' === $piece ) ) {
					$ended = true;
					break;
				}

				if ( '{' === $piece ) {
					// declare() with a block: leave the statement where it is.
					break;
				}
			}

			if ( ! $ended ) {
				break;
			}

			$offset += $length;
			$index   = $scan + 1;
			$at      = $offset;

			if ( $is_namespace ) {
				break;
			}
		}

		$code = substr( $code, 0, $at ) . ' return; ' . substr( $code, $at );
	}

	file_put_contents( $scratch, $code );

	include $scratch;
}
