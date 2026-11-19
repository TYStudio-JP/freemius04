<?php declare(strict_types=1);

namespace TYStudio\UpgradeWarden\Vendor\PhpParser\ErrorHandler;

use TYStudio\UpgradeWarden\Vendor\PhpParser\Error;
use TYStudio\UpgradeWarden\Vendor\PhpParser\ErrorHandler;

/**
 * Error handler that handles all errors by throwing them.
 *
 * This is the default strategy used by all components.
 */
class Throwing implements ErrorHandler {
    public function handleError(Error $error): void {
        throw $error;
    }
}
