<?php
declare(strict_types=1);

namespace Kinobiweb;

class OpenLocationCodeTest extends \PHPUnit_Framework_TestCase
{
    public function testItCanbeInstantiate()
    {
        $olc = new OpenLocationCode();

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
            '6GCRMQ00+5' => false,
            '6GCRMQRG+5' => false,
            '6GCRMQRG+5Z' => false,
            '6GCRMQRG+59' => true
        ];
        foreach ($tests as $code => $expected) {
            $olc = new OpenLocationCode((string)$code);
            $isValid = $olc->isValid();
            if (!$expected) {
                $this->assertFalse($isValid);
            } else {
                $this->assertTrue($isValid);
            }
        }
    }
}
