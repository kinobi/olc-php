<?php
declare(strict_types=1);


namespace Kinobiweb;

/**
 * Convert locations to and from short codes.
 *
 * Open Location Codes are short, 10-11 character codes that can be used instead
 * of street addresses. The codes can be generated and decoded offline, and use
 * a reduced character set that minimises the chance of codes including words.
 *
 * Codes are able to be shortened relative to a nearby location. This means that
 * in many cases, only four to seven characters of the code are needed.
 * To recover the original code, the same location is not required, as long as
 * a nearby location is provided.
 *
 * Codes represent rectangular areas rather than points, and the longer the
 * code, the smaller the area. A 10 character code represents a 13.5x13.5
 * meter area (at the equator. An 11 character code represents approximately
 * a 2.8x3.5 meter area.
 *
 * Two encoding algorithms are used. The first 10 characters are pairs of
 * characters, one for latitude and one for latitude, using base 20. Each pair
 * reduces the area of the code by a factor of 400. Only even code lengths are
 * sensible, since an odd-numbered length would have sides in a ratio of 20:1.
 *
 * At position 11, the algorithm changes so that each character selects one
 * position from a 4x5 grid. This allows single-character refinements.
 *
 * Examples:
 *
 * Encode a location, default accuracy:
 * $code = (Kinobiweb\OpenLocationCode::encode(47.365590, 8.524997))->getCode();
 *
 * Encode a location using one stage of additional refinement:
 * $code = (Kinobiweb\OpenLocationCode::encode(47.365590, 8.524997, 11))->getCode();
 *
 * Decode a full code:
 * $coord = (new Kinobiweb\OpenLocationCode)->setCode($code->getCode());
 * $msg = 'Center is ' . $coord->getLatitudeCenter() . ',' . $coord->getLongitudeCenter();
 *
 * Attempt to trim the first characters from a code:
 * $shortCode = (new Kinobiweb\OpenLocationCode('8FVC9G8F+6X'))->shorten(47.5, 8.5);
 *
 * Recover the full code from a short code:
 * $code = (new Kinobiweb\OpenLocationCode('9G8F+6X'))->recoverNearest(47.4, 8.6)->getCode();
 * $code = (new Kinobiweb\OpenLocationCode('8F+6X'))->recoverNearest(47.4, 8.6)->getCode();
 */
class OpenLocationCode
{
    /**
     * A separator used to break the code into two parts to aid memorability.
     */
    const SEPARATOR = '+';

    /**
     * The number of characters to place before the separator.
     */
    const SEPARATOR_POSITION = 8;

    /**
     * The character used to pad codes.
     */
    const PADDING_CHARACTER = '0';

    /**
     * The character set used to encode the values.
     */
    const CODE_ALPHABET = '23456789CFGHJMPQRVWX';

    /**
     * The base to use to convert numbers to/from.
     */
    const ENCODING_BASE = 20;

    /**
     * The maximum value for latitude in degrees.
     */
    const LATITUDE_MAX = 90;

    /**
     * The maximum value for longitude in degrees.
     */
    const LONGITUDE_MAX = 180;

    /**
     * Maximum code length using lat/lng pair encoding. The area of such a
     * code is approximately 13x13 meters (at the equator), and should be suitable
     * for identifying buildings. This excludes prefix and separator characters.
     */
    const PAIR_CODE_LENGTH = 10;

    /**
     * The resolution values in degrees for each position in the lat/lng pair
     * encoding. These give the place value of each position, and therefore the
     * dimensions of the resulting area.
     */
    const PAIR_RESOLUTIONS = [20.0, 1.0, .05, .0025, .000125];

    /**
     * Number of columns in the grid refinement method.
     */
    const GRID_COLUMNS = 4;

    /**
     * Number of rows in the grid refinement method.
     */
    const GRID_ROWS = 5;

    /**
     * Size of the initial grid in degrees.
     */
    const GRID_SIZE_DEGREES = 0.000125;

    /**
     * Minimum length of a code that can be shortened.
     */
    const MIN_TRIMMABLE_CODE_LEN = 6;

    /**
     * @var string  Open Location Code
     */
    protected $code;

    /**
     * OpenLocationCode constructor.
     *
     * @param string|null $code
     */
    public function __construct(string $code = null)
    {
        if (!is_null($code)) {
            $this->code = trim($code);
        }
    }

    /**
     * Determines if a code is valid.
     *
     * To be valid, all characters must be from the Open Location Code character
     * set with at most one separator. The separator can be in any even-numbered
     * position up to the eighth digit.
     *
     * @return bool
     */
    public function isValid()
    {
        if (!$this->code) {
            return false;
        }
        // The separator is required.
        if (strpos($this->code, static::SEPARATOR) === false) {
            return false;
        }
        if (strpos($this->code, static::SEPARATOR) != strrpos($this->code, static::SEPARATOR)) {
            return false;
        }
        // Is it the only character?
        if (strlen($this->code) == 1) {
            return false;
        }
        // Is it in an illegal position?
        if (strpos($this->code, static::SEPARATOR) > static::SEPARATOR_POSITION ||
            strpos($this->code, static::SEPARATOR) % 2 == 1
        ) {
            return false;
        }
        // We can have an even number of padding characters before the separator,
        // but then it must be the final character.
        if (strpos($this->code, static::PADDING_CHARACTER) !== false) {
            // Not allowed to start with them!
            if (strpos($this->code, static::PADDING_CHARACTER) == 0) {
                return false;
            }
            // There can only be one group and it must have even length.
            $padMatch = [];
            preg_match_all('(' . static::PADDING_CHARACTER . '+)', $this->code, $padMatch);
            if (count($padMatch[0]) > 1 || (strlen($padMatch[0][0]) % 2 == 1) || (strlen($padMatch[0][0]) > static::SEPARATOR_POSITION - 2)) {
                return false;
            }
            // If the code is long enough to end with a separator, make sure it does.
            if (substr($this->code, -1) != static::SEPARATOR) {
                return false;
            }
        }
        // If there are characters after the separator, make sure there isn't just
        // one of them (not legal).
        if (strlen($this->code) - strpos($this->code, static::SEPARATOR) - 1 == 1) {
            return false;
        }

        // Strip the separator and any padding characters.
        $code = str_replace([static::SEPARATOR, static::PADDING_CHARACTER], '', $this->code);
        // Check the code contains only valid characters.
        $codeLength = strlen($code);
        for ($i = 0; $i < $codeLength; $i++) {
            $character = strtoupper($code[$i]);
            if ($character != static::SEPARATOR && strpos(static::CODE_ALPHABET, $character) === false) {
                return false;
            }
        }

        return true;
    }
}
