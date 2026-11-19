<?php declare(strict_types=1);

namespace TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\Cast;

use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Expr\Cast;

class Unset_ extends Cast {
    public function getType(): string {
        return 'Expr_Cast_Unset';
    }
}
