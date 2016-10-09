<?php
declare(strict_types=1);

namespace Kinobiweb;

use Kinobiweb\OpenLocationCode\CodeArea;
use Kinobiweb\OpenLocationCode\Exception;

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
 * use Kinobiweb\OpenLocationCode;
 *
 * Encode a location, default accuracy:
 * $code = OpenLocationCode::encode(47.365590, 8.524997);
 *
 * Encode a location using one stage of additional refinement:
 * $code = OpenLocationCode::encode(47.365590, 8.524997, 11);
 *
 * Decode a full code:
 * $coord = OpenLocationCode::decode($code);
 * $msg = 'Center is ' . $coord->getLatitudeCenter() . ',' . $coord->getLongitudeCenter();
 *
 * Attempt to trim the first characters from a code:
 * $shortCode = OpenLocationCode::shorten('8FVC9G8F+6X', 47.5, 8.5);
 *
 * Recover the full code from a short code:
 * $code = OpenLocationCode::recoverNearest('9G8F+6X', 47.4, 8.6);
 * $code = OpenLocationCode::recoverNearest('8F+6X', 47.4, 8.6);
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
    const PAIR_RESOLUTIONS = [20.0, 1.0, 0.05, 0.0025, 0.000125];

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
     * Determines if a code is valid.
     *
     * To be valid, all characters must be from the Open Location Code character
     * set with at most one separator. The separator can be in any even-numbered
     * position up to the eighth digit.
     *
     * @param string $code
     *
     * @return bool
     */
    public static function isValid(string $code): bool
    {
        if (!$code) {
            return false;
        }
        // The separator is required.
        if (strpos($code, static::SEPARATOR) === false) {
            return false;
        }
        if (strpos($code, static::SEPARATOR) != strrpos($code, static::SEPARATOR)) {
            return false;
        }
        // Is it the only character?
        if (strlen($code) == 1) {
            return false;
        }
        // Is it in an illegal position?
        if (strpos($code, static::SEPARATOR) > static::SEPARATOR_POSITION ||
            strpos($code, static::SEPARATOR) % 2 == 1
        ) {
            return false;
        }
        // We can have an even number of padding characters before the separator,
        // but then it must be the final character.
        if (strpos($code, static::PADDING_CHARACTER) !== false) {
            // Not allowed to start with them!
            if (strpos($code, static::PADDING_CHARACTER) == 0) {
                return false;
            }
            // There can only be one group and it must have even length.
            $padMatch = [];
            preg_match_all('(' . static::PADDING_CHARACTER . '+)', $code, $padMatch);
            if (count($padMatch[0]) > 1 || (strlen($padMatch[0][0]) % 2 == 1) || (strlen($padMatch[0][0]) > static::SEPARATOR_POSITION - 2)) {
                return false;
            }
            // If the code is long enough to end with a separator, make sure it does.
            if (substr($code, -1) != static::SEPARATOR) {
                return false;
            }
        }
        // If there are characters after the separator, make sure there isn't just
        // one of them (not legal).
        if (strlen($code) - strpos($code, static::SEPARATOR) - 1 == 1) {
            return false;
        }

        // Strip the separator and any padding characters.
        $code = str_replace([static::SEPARATOR, static::PADDING_CHARACTER], '', $code);
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

    /**
     * Determines if a code is a valid short code.
     *
     * A short Open Location Code is a sequence created by removing four or more
     * digits from an Open Location Code. It must include a separator
     * character.
     *
     * @param string $code
     *
     * @return bool
     */
    public static function isShort(string $code): bool
    {
        // Check it's valid.
        if (!static::isValid($code)) {
            return false;
        }
        // If there are less characters than expected before the SEPARATOR.
        if (strpos($code, static::SEPARATOR) >= 0
            && strpos($code, static::SEPARATOR) < static::SEPARATOR_POSITION
        ) {
            return true;
        }
        return false;
    }

    /**
     * Determines if a code is a valid full Open Location Code.
     *
     * Not all possible combinations of Open Location Code characters decode to
     * valid latitude and longitude values. This checks that a code is valid
     * and also that the latitude and longitude values are legal. If the prefix
     * character is present, it must be the first character. If the separator
     * character is present, it must be after four characters.
     *
     * @param string $code
     *
     * @return bool
     */
    public static function isFull(string $code): bool
    {
        if (!static::isValid($code)) {
            return false;
        }
        // If it's short, it's not full.
        if (static::isShort($code)) {
            return false;
        }

        // Work out what the first latitude character indicates for latitude.
        $firstLatValue = strpos(static::CODE_ALPHABET, strtoupper($code[0])) * static::ENCODING_BASE;
        if ($firstLatValue >= static::LATITUDE_MAX * 2) {
            // The code would decode to a latitude of >= 90 degrees.
            return false;
        }
        if (strlen($code) > 1) {
            // Work out what the first longitude character indicates for longitude.
            $firstLngValue = strpos(static::CODE_ALPHABET, strtoupper($code[1])) * static::ENCODING_BASE;
            if ($firstLngValue >= static::LONGITUDE_MAX * 2) {
                // The code would decode to a longitude of >= 180 degrees.
                return false;
            }
        }

        return true;
    }

    /**
     * Encode a location into an Open Location Code.
     *
     * Produces a code of the specified length, or the default length if no length
     * is provided.
     *
     * The length determines the accuracy of the code. The default length is
     * 10 characters, returning a code of approximately 13.5x13.5 meters. Longer
     * codes represent smaller areas, but lengths > 14 are sub-centimetre and so
     * 11 or 12 are probably the limit of useful codes.
     *
     * @param float $latitude A latitude in signed decimal degrees. Will be clipped to the range -90 to 90.
     * @param float $longitude A longitude in signed decimal degrees. Will be normalised to the range -180 to 180.
     * @param int $codeLength The number of significant digits in the output code, not  including any separator characters.
     *
     * @return string
     *
     * @throws Exception
     */
    public static function encode(float $latitude, float $longitude, int $codeLength = null): string
    {
        if (is_null($codeLength)) {
            $codeLength = static::PAIR_CODE_LENGTH;
        }
        if ($codeLength < 2 || ($codeLength < static::SEPARATOR_POSITION && $codeLength % 2 == 1)) {
            throw new Exception('Invalid Open Location Code length');
        }
        // Ensure that latitude and longitude are valid.
        $latitude = static::clipLatitude($latitude);
        $longitude = static::normalizeLongitude($longitude);
        // Latitude 90 needs to be adjusted to be just less, so the returned code can also be decoded.
        if ($latitude == 90) {
            $latitude = $latitude - static::computeLatitudePrecision($codeLength);
        }
        $code = static::encodePairs($latitude, $longitude, min($codeLength, static::PAIR_CODE_LENGTH));
        // If the requested length indicates we want grid refined codes.
        if ($codeLength > static::PAIR_CODE_LENGTH) {
            $code .= static::encodeGrid($latitude, $longitude, $codeLength - static::PAIR_CODE_LENGTH);
        }
        return $code;
    }

    /**
     * Decodes an Open Location Code into the location coordinates.
     *
     * Returns a CodeArea object that includes the coordinates of the bounding box - the lower left,
     * center and upper right.
     *
     * @param string $code The Open Location Code to decode.
     * @return CodeArea A CodeArea object that provides the latitude and longitude of two of the
     * corners of the area, the center, and the length of the original code.
     *
     * @throws Exception
     */
    public static function decode(string $code): CodeArea
    {
        if (!static::isFull($code)) {
            throw new Exception('Passed Open Location Code is not a valid full code: ' . $code);
        }
        // Strip out separator character (we've already established the code is
        // valid so the maximum is one), padding characters and convert to upper
        // case.
        $code = str_replace([static::SEPARATOR, static::PADDING_CHARACTER], '', $code);
        $code = strtoupper($code);
        // Decode the lat/lng pair component.
        $codeArea = static::decodePairs(substr($code, 0, static::PAIR_CODE_LENGTH));
        // If there is a grid refinement component, decode that.
        if (strlen($code) <= static::PAIR_CODE_LENGTH) {
            return $codeArea;
        }
        $gridArea = static::decodeGrid(substr($code, static::PAIR_CODE_LENGTH));
        return new CodeArea(
            $codeArea->latitudeLo() + $gridArea->latitudeLo(),
            $codeArea->longitudeLo() + $gridArea->longitudeLo(),
            $codeArea->latitudeLo() + $gridArea->latitudeHi(),
            $codeArea->longitudeLo() + $gridArea->longitudeHi(),
            $codeArea->codeLength() + $gridArea->codeLength());
    }

    /**
     * Recover the nearest matching code to a specified location.
     *
     * Given a valid short Open Location Code this recovers the nearest matching
     * full code to the specified location.
     * Short codes will have characters prepended so that there are a total of
     * eight characters before the separator.
     *
     * @param string $shortCode A valid short OLC character sequence.
     * @param float $referenceLatitude The latitude (in signed decimal degrees) to use to find the nearest matching full code.
     * @param float $referenceLongitude The longitude (in signed decimal degrees) to use to find the nearest matching full code.
     *
     * @return string The nearest full Open Location Code to the reference location that matches
     * the short code. Note that the returned code may not have the same
     * computed characters as the reference location. This is because it returns
     * the nearest match, not necessarily the match within the same cell. If the
     * passed code was not a valid short code, but was a valid full code, it is
     * returned unchanged.
     *
     * @throws Exception
     */
    public static function recoverNearest(string $shortCode, float $referenceLatitude, float $referenceLongitude): string
    {
        if (!static::isShort($shortCode)) {
            if (static::isFull($shortCode)) {
                return $shortCode;
            }
            throw new Exception("Passed short code is not valid: " . $shortCode);
        }
        // Ensure that latitude and longitude are valid.
        $referenceLatitude = static::clipLatitude($referenceLatitude);
        $referenceLongitude = static::normalizeLongitude($referenceLongitude);

        // Clean up the passed code.
        $shortCode = strtoupper($shortCode);
        // Compute the number of digits we need to recover.
        $paddingLength = static::SEPARATOR_POSITION - strpos($shortCode, static::SEPARATOR);
        // The resolution (height and width) of the padded area in degrees.
        $resolution = pow(20, 2 - ($paddingLength / 2));
        // Distance from the center to an edge (in degrees).
        $areaToEdge = $resolution / 2;

        // Use the reference location to pad the supplied short code and decode it.
        $codeArea = static::decode(substr(static::encode($referenceLatitude, $referenceLongitude), 0, $paddingLength) . $shortCode);
        // How many degrees latitude is the code from the reference? If it is more than half the resolution, we need to move it east or west.
        $degreesDifference = $codeArea->latitudeCenter() - $referenceLatitude;
        $nearestAreaLatitude = $codeArea->latitudeCenter();
        if ($degreesDifference > $areaToEdge) {
            // If the center of the short code is more than half a cell east,
            // then the best match will be one position west.
            $nearestAreaLatitude -= $resolution;
        } elseif ($degreesDifference < (-1 * $areaToEdge)) {
            $nearestAreaLatitude += $resolution;
        }
        $degreesDifference = $codeArea->longitudeCenter() - $referenceLongitude;
        $nearestAreaLongitude = $codeArea->longitudeCenter();
        if ($degreesDifference > $areaToEdge) {
            $nearestAreaLongitude -= $resolution;
        } elseif ($degreesDifference < (-1 * $areaToEdge)) {
            $nearestAreaLongitude += $resolution;
        }

        return static::encode($nearestAreaLatitude, $nearestAreaLongitude, $codeArea->codeLength());
    }

    /**
     * Remove characters from the start of an OLC code.
     *
     * This uses a reference location to determine how many initial characters
     * can be removed from the OLC code. The number of characters that can be
     * removed depends on the distance between the code center and the reference
     * location.
     * The minimum number of characters that will be removed is four. If more than
     * four characters can be removed, the additional characters will be replaced
     * with the padding character. At most eight characters will be removed.
     * The reference location must be within 50% of the maximum range. This ensures
     * that the shortened code will be able to be recovered using slightly different
     * locations.
     *
     * @param string $code      A full, valid code to shorten.
     * @param float $latitude   A latitude, in signed decimal degrees, to use as the reference point.
     * @param float $longitude  A longitude, in signed decimal degrees, to use as the reference point.
     *
     * @return string Either the original code, if the reference location was not close enough, or the shortened code.
     *
     * @throws Exception
     */
    public static function shorten(string $code, float $latitude, float $longitude): string
    {
        if (!static::isFull($code)) {
            throw new Exception('Passed code is not valid and full: ' . $code);
        }
        if (strpos($code, static::PADDING_CHARACTER) !== false) {
            throw new Exception('Cannot shorten padded codes: ' . $code);
        }
        $code = strtoupper($code);
        $codeArea = static::decode($code);
        if ($codeArea->codeLength() < static::MIN_TRIMMABLE_CODE_LEN) {
            throw new Exception('Code length must be at least ' . static::MIN_TRIMMABLE_CODE_LEN);
        }
        // Ensure that latitude and longitude are valid.
        $latitude = static::clipLatitude($latitude);
        $longitude = static::normalizeLongitude($longitude);
        // How close are the latitude and longitude to the code center.
        $range = max(abs($codeArea->latitudeCenter() - $latitude), abs($codeArea->longitudeCenter() - $longitude));
        for ($i = count(static::PAIR_RESOLUTIONS) - 2; $i >= 1; $i--) {
            // Check if we're close enough to shorten. The range must be less than 1/2
            // the resolution to shorten at all, and we want to allow some safety, so
            // use 0.3 instead of 0.5 as a multiplier.
            if ($range < (static::PAIR_RESOLUTIONS[$i] * 0.3)) {
                // Trim it.
                return substr($code, ($i + 1) * 2);
            }
        }
        return $code;
    }

    /**
     * Clip a latitude into the range -90 to 90.
     *
     * @param float $latitude A latitude in signed decimal degrees.
     *
     * @return float
     */
    private static function clipLatitude(float $latitude): float
    {
        return min(90, max(-90, $latitude));
    }

    /**
     * Compute the latitude precision value for a given code length.
     *
     * Lengths <= 10 have the same precision for latitude and longitude, but lengths > 10
     * have different precisions due to the grid method having fewer columns than rows.
     *
     * @param int $codeLength
     *
     * @return float
     */
    private static function computeLatitudePrecision(int $codeLength): float
    {
        if ($codeLength <= 10) {
            return (float)pow(20, floor($codeLength / -2 + 2));
        }
        return pow(20, -2) / pow(static::GRID_ROWS, $codeLength - 10);
    }

    /**
     * Normalize a longitude into the range -180 to 180, not including 180.
     *
     * @param float $longitude A longitude in signed decimal degrees.
     *
     * @return float
     */
    private static function normalizeLongitude(float $longitude): float
    {
        while ($longitude < -180) {
            $longitude = $longitude + 360;
        }
        while ($longitude >= 180) {
            $longitude = $longitude - 360;
        }
        return $longitude;
    }

    /**
     * Encode a location into a sequence of OLC lat/lng pairs.
     *
     * This uses pairs of characters (longitude and latitude in that order) to
     * represent each step in a 20x20 grid. Each code, therefore, has 1/400th
     * the area of the previous code.
     *
     * @param float $latitude   A latitude in signed decimal degrees.
     * @param float $longitude  A longitude in signed decimal degrees.
     * @param int   $codeLength The number of significant digits in the output code, not including any separator characters.
     *
     * @return string
     */
    private static function encodePairs(float $latitude, float $longitude, int $codeLength): string
    {
        $code = '';
        // Adjust latitude and longitude so they fall into positive ranges.
        $adjustedLatitude = $latitude + static::LATITUDE_MAX;
        $adjustedLongitude = $longitude + static::LONGITUDE_MAX;
        // Count digits - can't use string length because it may include a separator character.
        $digitCount = 0;
        while ($digitCount < $codeLength) {
            // Provides the value of digits in this place in decimal degrees.
            $placeValue = static::PAIR_RESOLUTIONS[(int)floor($digitCount / 2)];
            // Do the latitude - gets the digit for this place and subtracts that for
            // the next digit.
            $digitValue = floor($adjustedLatitude / $placeValue);
            $adjustedLatitude -= $digitValue * $placeValue;
            $code .= static::CODE_ALPHABET[(int)$digitValue];
            $digitCount += 1;
            // And do the longitude - gets the digit for this place and subtracts that
            // for the next digit.
            $digitValue = floor($adjustedLongitude / $placeValue);
            $adjustedLongitude -= $digitValue * $placeValue;
            $code .= static::CODE_ALPHABET[(int)$digitValue];
            $digitCount += 1;
            // Should we add a separator here?
            if ($digitCount == static::SEPARATOR_POSITION && $digitCount < $codeLength) {
                $code .= static::SEPARATOR;
            }
        }
        if (strlen($code) < static::SEPARATOR_POSITION) {
            $code = str_pad($code, static::SEPARATOR_POSITION, static::PADDING_CHARACTER);
        }
        if (strlen($code) == static::SEPARATOR_POSITION) {
            $code = $code . static::SEPARATOR;
        }
        return $code;
    }

    /**
     * Encode a location using the grid refinement method into an OLC string.
     *
     * The grid refinement method divides the area into a grid of 4x5, and uses a
     * single character to refine the area. This allows default accuracy OLC codes
     * to be refined with just a single character.
     *
     * @param float $latitude   A latitude in signed decimal degrees.
     * @param float $longitude  A longitude in signed decimal degrees.
     * @param int   $codeLength The number of significant digits in the output code, not including any separator characters.
     *
     * @return string
     */
    private static function encodeGrid(float $latitude, float $longitude, int $codeLength): string
    {
        $code = '';
        $latPlaceValue = static::GRID_SIZE_DEGREES;
        $lngPlaceValue = static::GRID_SIZE_DEGREES;
        // Adjust latitude and longitude so they fall into positive ranges and
        // get the offset for the required places.
        $adjustedLatitude = fmod($latitude + static::LATITUDE_MAX, $latPlaceValue);
        $adjustedLongitude = fmod($longitude + static::LONGITUDE_MAX, $lngPlaceValue);
        for ($i = 0; $i < $codeLength; $i++) {
            // Work out the row and column.
            $row = floor($adjustedLatitude / ($latPlaceValue / static::GRID_ROWS));
            $col = floor($adjustedLongitude / ($lngPlaceValue / static::GRID_COLUMNS));
            $latPlaceValue /= static::GRID_ROWS;
            $lngPlaceValue /= static::GRID_COLUMNS;
            $adjustedLatitude -= $row * $latPlaceValue;
            $adjustedLongitude -= $col * $lngPlaceValue;
            $code .= static::CODE_ALPHABET[intval($row * static::GRID_COLUMNS + $col)];
        }
        return $code;
    }

    /**
     * Decode an OLC code made up of lat/lng pairs.
     *
     * This decodes an OLC code made up of alternating latitude and longitude characters, encoded using base 20.
     *
     * @param string $code A valid OLC code, presumed to be full, but with the separator removed.
     *
     * @return CodeArea
     */
    private static function decodePairs(string $code): CodeArea
    {
        $latitude = static::decodePairsSequence($code, 0);
        $longitude = static::decodePairsSequence($code, 1);
        // Correct the values and set them into the CodeArea object.
        return new CodeArea(
            $latitude[0] - static::LATITUDE_MAX,
            $longitude[0] - static::LONGITUDE_MAX,
            $latitude[1] - static::LATITUDE_MAX,
            $longitude[1] - static::LONGITUDE_MAX,
            strlen($code)
        );
    }

    /**
     * Decode either a latitude or longitude sequence.
     *
     * This decodes the latitude or longitude sequence of a lat/lng pair encoding.
     * Starting at the character at position offset, every second character is decoded and the value returned.
     *
     * @param string $code      A valid OLC code, presumed to be full, with the separator removed.
     * @param int    $offset    The character to start from.
     *
     * @return array A pair of the low and high values. The low value comes from decoding the
     * characters. The high value is the low value plus the resolution of the
     * last position. Both values are offset into positive ranges and will need
     * to be corrected before use.
     */
    private static function decodePairsSequence(string $code, int $offset): array
    {
        $i = $value = 0;
        $codeLength = strlen($code);
        while ($i * 2 + $offset < $codeLength) {
            $value += strpos(static::CODE_ALPHABET, $code[$i * 2 + $offset]) * static::PAIR_RESOLUTIONS[$i];
            $i++;
        }
        return [$value, $value + static::PAIR_RESOLUTIONS[$i - 1]];
    }

    /**
     * Decode the grid refinement portion of an OLC code.
     *
     * This decodes an OLC code using the grid refinement method.
     *
     * @param $code string A valid OLC code sequence that is only the grid refinement
     * portion. This is the portion of a code starting at position 11.
     *
     * @return CodeArea
     */
    private static function decodeGrid(string $code): CodeArea
    {
        $latitudeLo = $longitudeLo = 0;
        $latPlaceValue = $lngPlaceValue = static::GRID_SIZE_DEGREES;
        $i = 0;
        $codeLength = strlen($code);
        while ($i < $codeLength) {
            $codeIndex = strpos(static::CODE_ALPHABET, $code[$i]);
            $row = floor($codeIndex / static::GRID_COLUMNS);
            $col = $codeIndex % static::GRID_COLUMNS;

            $latPlaceValue /= static::GRID_ROWS;
            $lngPlaceValue /= static::GRID_COLUMNS;

            $latPlaceValue += $row * $latPlaceValue;
            $longitudeLo += $col * $lngPlaceValue;
            $i++;
        }
        return new CodeArea($latitudeLo, $longitudeLo, $latitudeLo + $latPlaceValue,
            $longitudeLo + $lngPlaceValue, $codeLength);
    }
}
