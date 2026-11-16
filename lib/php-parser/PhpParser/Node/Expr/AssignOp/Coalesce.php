<?php declare(strict_types=1);

namespace TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\AssignOp;

use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\AssignOp;

class Coalesce extends AssignOp {
    public function getType(): string {
        return 'Expr_AssignOp_Coalesce';
    }
}
