<?php

namespace GuidoCella\EloquentPopulator;

use Closure;
use GuidoCella\EloquentPopulator\Factories\CompanyFactory;
use GuidoCella\EloquentPopulator\Models\Company;
use GuidoCella\EloquentPopulator\Models\User;

class PopulatorTest extends PopulatorTestCase
{
    public function testColumnTypeGuesser()
    {
        $user = array_map(fn ($closure) => $closure instanceof Closure ? $closure() : $closure, Populator::guessFormatters(User::class));
        $this->assertIsInt($user['smallint']);
        $this->assertIsInt($user['integer']);
        $this->assertIsInt($user['bigint']);
        $this->assertIsFloat($user['decimal']);
        $this->assertIsFloat($user['float']);
        $this->assertTrue(is_string($user['string']) && strlen($user['string']));
        $this->assertTrue(is_string($user['text']) && strlen($user['text']));
        $this->assertInstanceOf(\DateTime::class, $user['date']);
        $this->assertInstanceOf(\DateTime::class, $user['datetime']);
        $this->assertInstanceOf(\DateTime::class, $user['timestamp']);
        $this->assertMatchesRegularExpression('/\d\d:\d\d:\d\d/', $user['time']);
        $this->assertIsBool($user['boolean']);
        // DATETIME-TZ, JSON and UUID are not supported by SQLite, so there's no point in testing them.
    }

    public function testColumnNameGuesser()
    {
        $this->assertMatchesRegularExpression(
            '/^(?!(?:(?:\\x22?\\x5C[\\x00-\\x7E]\\x22?)|(?:\\x22?[^\\x5C\\x22]\\x22?)){255,})(?!(?:(?:\\x22?\\x5C[\\x00-\\x7E]\\x22?)|(?:\\x22?[^\\x5C\\x22]\\x22?)){65,}@)(?:(?:[\\x21\\x23-\\x27\\x2A\\x2B\\x2D\\x2F-\\x39\\x3D\\x3F\\x5E-\\x7E]+)|(?:\\x22(?:[\\x01-\\x08\\x0B\\x0C\\x0E-\\x1F\\x21\\x23-\\x5B\\x5D-\\x7F]|(?:\\x5C[\\x00-\\x7F]))*\\x22))(?:\\.(?:(?:[\\x21\\x23-\\x27\\x2A\\x2B\\x2D\\x2F-\\x39\\x3D\\x3F\\x5E-\\x7E]+)|(?:\\x22(?:[\\x01-\\x08\\x0B\\x0C\\x0E-\\x1F\\x21\\x23-\\x5B\\x5D-\\x7F]|(?:\\x5C[\\x00-\\x7F]))*\\x22)))*@(?:(?:(?!.*[^.]{64,})(?:(?:(?:xn--)?[a-z0-9]+(?:-+[a-z0-9]+)*\\.){1,126}){1,}(?:(?:[a-z][a-z0-9]*)|(?:(?:xn--)[a-z0-9]+))(?:-+[a-z0-9]+)*)|(?:\\[(?:(?:IPv6:(?:(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){7})|(?:(?!(?:.*[a-f0-9][:\\]]){7,})(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,5})?::(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,5})?)))|(?:(?:IPv6:(?:(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){5}:)|(?:(?!(?:.*[a-f0-9]:){5,})(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,3})?::(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,3}:)?)))?(?:(?:25[0-5])|(?:2[0-4][0-9])|(?:1[0-9]{2})|(?:[1-9]?[0-9]))(?:\\.(?:(?:25[0-5])|(?:2[0-4][0-9])|(?:1[0-9]{2})|(?:[1-9]?[0-9]))){3}))\\]))$/iD',
            Populator::guessFormatters(User::class)['email']()
        );
    }

    public function testBelongsTo()
    {
        $this->assertInstanceOf(CompanyFactory::class, Populator::guessFormatters(User::class)['company_id']);
    }

    public function testBelongsToItself()
    {
        $this->assertNull(Populator::guessFormatters(User::class)['friend_id']);
    }

    public function testTranslatableColumns()
    {
        $name = Populator::guessFormatters(Company::class)['name']();

        $this->assertIsArray($name);
        $this->assertArrayHasKey('en', $name);
        $this->assertArrayHasKey('es', $name);
        $this->assertGreaterThan(1, strlen($name['en']));
        $this->assertGreaterThan(1, strlen($name['es']));
    }
}
// vim: nolinebreak
