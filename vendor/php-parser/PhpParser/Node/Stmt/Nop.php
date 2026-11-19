<?php declare(strict_types=1);

namespace TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Stmt;

use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;

/** Nop/empty statement (;). */
class Nop extends Node\Stmt {
    public function getSubNodeNames(): array {
        return [];
    }

    public function getType(): string {
        return 'Stmt_Nop';
    }
}
