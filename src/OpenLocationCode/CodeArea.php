<?php
declare(strict_types=1);

namespace Kinobiweb\OpenLocationCode;

use Kinobiweb\OpenLocationCode;

/**
 * Coordinates of a decoded Open Location Code.
 *
 * The coordinates include the latitude and longitude of the lower left and
 * upper right corners and the center of the bounding box for the area the
 * code represents.
 *
 * @property float $latitudeLo      The latitude of the SW corner in degrees.
 * @property float $longitudeLo     The longitude of the SW corner in degrees.
 * @property float $latitudeHi      The latitude of the NE corner in degrees.
 * @property float $longitudeHi     The longitude of the NE corner in degrees.
 * @property float $latitudeCenter  The latitude of the center in degrees.
 * @property float $longitudeCenter The longitude of the center in degrees.
 * @property int $codeLength        The number of significant characters that were in the code. Separator excluded.
 *
 * @package OpenLocationCode
 */
class CodeArea
{
    public $latitudeLo;
    public $longitudeLo;
    public $latitudeHi;
    public $longitudeHi;
    public $latitudeCenter;
    public $longitudeCenter;
    public $codeLength;

    public function __construct(float $latitudeLo, float $longitudeLo, float $latitudeHi, float $longitudeHi, int $codeLength)
    {
        $this->latitudeLo = $latitudeLo;
        $this->longitudeLo = $longitudeLo;
        $this->latitudeHi = $latitudeHi;
        $this->longitudeHi = $longitudeHi;
        $this->codeLength = $codeLength;
        $this->latitudeCenter = min($latitudeLo + ($latitudeHi - $latitudeLo) / 2, OpenLocationCode::LATITUDE_MAX);
        $this->longitudeCenter = min($longitudeLo + ($longitudeHi - $longitudeLo) / 2, OpenLocationCode::LONGITUDE_MAX);
    }
}
