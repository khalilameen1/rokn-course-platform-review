<?php

declare(strict_types=1);

namespace Tests\Unit;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\CourseCodeSeeder;
use Database\Seeders\RoknExperienceDemoSeeder;
use Database\Seeders\VisitorTrialDataSeeder;
use Database\Seeders\Concerns\GuardsDevelopmentFixtures;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class DemoSeederSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The checked-in vendor autoloader predates the new Database\\Seeders
        // PSR-4 entry. A normal composer install/dump in CI or deploy picks it
        // up; load it directly so this working tree can test the policy now.
        if (!trait_exists(GuardsDevelopmentFixtures::class)) {
            require_once database_path('seeders/Concerns/GuardsDevelopmentFixtures.php');
        }
        if (!class_exists(DatabaseSeeder::class)) {
            require_once database_path('seeders/DatabaseSeeder.php');
        }
        foreach ([CourseCodeSeeder::class, RoknExperienceDemoSeeder::class, VisitorTrialDataSeeder::class] as $seeder) {
            if (!class_exists($seeder)) {
                require_once database_path('seeders/'.class_basename($seeder).'.php');
            }
        }
    }

    public function test_production_blocks_database_seeder_even_when_fixture_flag_is_enabled(): void
    {
        $this->app['env'] = 'production';
        config()->set('demo.seed_enabled', true);

        $this->expectException(LogicException::class);
        (new DatabaseSeeder())->run();
    }

    #[DataProvider('directFixtureSeeders')]
    public function test_direct_fixture_seeder_cannot_bypass_production_gate(string $seeder): void
    {
        $this->app['env'] = 'production';
        config()->set('demo.seed_enabled', true);

        $this->expectException(LogicException::class);
        (new $seeder())->run();
    }

    public static function directFixtureSeeders(): array
    {
        return [
            'course codes' => [CourseCodeSeeder::class],
            'experience demo' => [RoknExperienceDemoSeeder::class],
            'synthetic visitors' => [VisitorTrialDataSeeder::class],
        ];
    }
}
