<?php
declare(strict_types=1);

namespace Kinobiweb;

use Kinobiweb\OpenLocationCode\Exception;

class OpenLocationCodeTest extends \PHPUnit_Framework_TestCase
{
    public function testItCanbeInstantiateEmpty()
    {
        $olc = new OpenLocationCode();

        $this->assertInstanceOf(OpenLocationCode::class, $olc);
    }

    public function testItCanBeInstantiateWithACode()
    {
        $code = '6GCRMQRG+59';
        $olc = new OpenLocationCode($code);

        $this->assertInstanceOf(OpenLocationCode::class, $olc);
        $this->assertSame($code, $olc->getCode());
    }

    public function testItValidateOnInstantiation()
    {
        $this->expectException(Exception::class);

        $olc = new OpenLocationCode('azerty');
        $this->assertInstanceOf(OpenLocationCode::class, $olc);
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
}
