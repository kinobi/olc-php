<?php
declare(strict_types=1);

namespace Kinobiweb;

use Kinobiweb\OpenLocationCode\Exception;

class OpenLocationCodeTest extends \PHPUnit_Framework_TestCase
{
    public function testValidityTests()
    {
        $tests = $this->getTestData('validityTests.csv');
        while ($test = fgetcsv($tests)) {
            if (preg_match('/^\s*#/', $test[0])) {
                continue;
            }
            $code = $test[0];
            $is_valid = $test[1] == 'true';
            $is_short = $test[2] == 'true';
            $is_full = $test[3] == 'true';
            $is_valid_olc = OpenLocationCode::isValid($code);
            $is_short_olc = OpenLocationCode::isShort($code);
            $is_full_olc = OpenLocationCode::isFull($code);
            $result = $is_valid_olc == $is_valid && $is_short_olc == $is_short && $is_full_olc == $is_full;
            $this->assertTrue($result);
        }
    }

    public function testEncodingDecodingTests()
    {
        $tests = $this->getTestData('encodingTests.csv');
        while ($test = fgetcsv($tests)) {
            if (preg_match('/^\s*#/', $test[0])) {
                continue;
            }
            // Convert the string numbers to float.
            $test[1] = floatval($test[1]);
            $test[2] = floatval($test[2]);
            $test[3] = floatval($test[3]);
            $test[4] = floatval($test[4]);
            $test[5] = floatval($test[5]);
            $test[6] = floatval($test[6]);
            $codeArea = OpenLocationCode::decode($test[0]);
            $code = OpenLocationCode::encode($test[1], $test[2], $codeArea->codeLength);
            $this->assertEquals($code, $test[0]);

        }
    }

    public function testItCanEncode()
    {
        $code = OpenLocationCode::encode(41.380872, 2.123002);
        $this->assertSame('8FH494JF+86', $code);

        $code = OpenLocationCode::encode(90, 0, 8);
        $this->assertSame('CFX2X2X2+', $code);

        $code = OpenLocationCode::encode(90, 0, 11);
        $this->assertSame('CFX2X2X2+R25', $code);

        $code = OpenLocationCode::encode(-37.848760, -216.966358);
        $this->assertSame('4RJ5522M+FF', $code);
    }

    public function testItRejectAWrongCodeLengthOnDecode()
    {
        $this->expectException(Exception::class);
        $olc = OpenLocationCode::decode('22WM+PW');
    }

    public function testItRejectAWrongCodeLengthOnEncode()
    {
        $this->expectException(Exception::class);
        $olc = OpenLocationCode::encode(48.847003, 2.286061, 1);
    }

    public function testItCanValidateACode()
    {
        $tests = [
            '' => false,
            '6' => false,
            '6++' => false,
            '+' => false,
            '6GCRMQRG0+' => false,
            '6GCRMQR+' => false,
            '0GCRMQRG+' => false,
            '60C0MQRG+' => false,
            '60CRMQRG+' => false,
            '60000000+' => false,
            '6GCRMQ00+5' => false,
            '6GCRMQRG+5' => false,
            '6GCRMQRG+5Z' => false,
            '6GCRMQRG+59' => true
        ];
        foreach ($tests as $code => $expected) {
            $isValid = OpenLocationCode::isValid((string)$code);
            if (!$expected) {
                $this->assertFalse($isValid);
            } else {
                $this->assertTrue($isValid);
            }
        }
    }

    public function testItCanValidateAShortCode()
    {
        $tests = [
            '' => false,
            '58GR22WM+PW' => false,
            '22WM+PW' => true
        ];
        foreach ($tests as $code => $expected) {
            $isShort = OpenLocationCode::isShort((string)$code);
            if (!$expected) {
                $this->assertFalse($isShort);
            } else {
                $this->assertTrue($isShort);
            }
        }
    }

    public function testItCanValidateAFullCode()
    {
        $tests = [
            '' => false,
            '22WM+PW' => false,
            'X8GR22WM+PW' => false,
            '5XGR22WM+PW' => false,
            '58GR22WM+PW' => true,
        ];
        foreach ($tests as $code => $expected) {
            $isFull = OpenLocationCode::isFull((string)$code);
            if (!$expected) {
                $this->assertFalse($isFull);
            } else {
                $this->assertTrue($isFull);
            }
        }
    }

    private function getTestData($csv)
    {
        $url = sprintf("https://raw.githubusercontent.com/google/open-location-code/master/test_data/%s", $csv);
        return fopen($url, 'r');
    }
}
