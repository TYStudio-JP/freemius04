<?php declare(strict_types=1);

namespace TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Stmt;

use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\UseItem;

require __DIR__ . '/../UseItem.php';

if (false) {
    /**
     * For classmap-authoritative support.
     *
     * @deprecated use \TYStudio\UpgradeWarden\Vendor\PhpParser\Node\UseItem instead.
     */
    class UseUse extends UseItem {
    }
}
