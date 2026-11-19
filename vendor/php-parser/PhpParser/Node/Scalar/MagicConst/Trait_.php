<?php declare(strict_types=1);

namespace TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Scalar\MagicConst;

use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Scalar\MagicConst;

class Trait_ extends MagicConst {
    public function getName(): string {
        return '__TRAIT__';
    }

    public function getType(): string {
        return 'Scalar_MagicConst_Trait';
    }
}
