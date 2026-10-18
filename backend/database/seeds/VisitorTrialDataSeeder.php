<?php

namespace Database\Seeders;

use App\Models\Visitor;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Legacy tenant-specific fixture retained only for reproducibility.
 *
 * New environments must use Database\Seeders\VisitorTrialDataSeeder.
 */
class LegacyVisitorTrialDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $tenantId = 7;
        $browsers = [
            'Chrome 120.0.0.0',
            'Firefox 121.0',
            'Safari 17.2',
            'Edge 120.0.0.0',
            'Opera 104.0.0.0',
        ];

        $operatingSystems = [
            'Windows 10',
            'Windows 11',
            'macOS 14.2',
            'Ubuntu 22.04',
            'iOS 17.2',
            'Android 14',
        ];

        $deviceTypes = ['Desktop', 'Mobile', 'Tablet'];

        // Generate data for the last 30 days
        for ($day = 29; $day >= 0; $day--) {
            $date = Carbon::now()->subDays($day);

            // Generate random number of visitors for each day (between 5 and 50)
            $visitorCount = rand(5, 50);

            for ($i = 0; $i < $visitorCount; $i++) {
                $hour = rand(0, 23);
                $minute = rand(0, 59);
                $second = rand(0, 59);

                $visitedAt = $date->copy()->setTime($hour, $minute, $second);

                Visitor::create([
                    'tenant_id' => $tenantId,
                    'ip_address' => '192.168.'.rand(1, 255).'.'.rand(1, 255),
                    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'browser' => $browsers[array_rand($browsers)],
                    'operating_system' => $operatingSystems[array_rand($operatingSystems)],
                    'device_type' => $deviceTypes[array_rand($deviceTypes)],
                    'visited_at' => $visitedAt,
                ]);
            }
        }

        echo 'Created '.($visitorCount * 30).' trial visitor records for tenant ID '.$tenantId."\n";
    }
}
