<?php declare(strict_types=1);

namespace TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Scalar\MagicConst;

use TYStudio\UpgradeWarden\Vendor\PhpParser\Node\Scalar\MagicConst;

class Namespace_ extends MagicConst {
    public function getName(): string {
        return '__NAMESPACE__';
    }

    public function getType(): string {
        return 'Scalar_MagicConst_Namespace';
    }
}
