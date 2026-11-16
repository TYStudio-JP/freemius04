<?php declare(strict_types=1);

namespace TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\BinaryOp;

use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\BinaryOp;

class LogicalAnd extends BinaryOp {
    public function getOperatorSigil(): string {
        return 'and';
    }

    public function getType(): string {
        return 'Expr_BinaryOp_LogicalAnd';
    }
}
