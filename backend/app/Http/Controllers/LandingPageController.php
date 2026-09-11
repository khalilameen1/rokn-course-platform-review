<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DesignSetting;
use App\Models\Setting;
use App\Services\AppReleaseChannelService;
use App\Services\PackageChannelPricingService;
use App\Services\PublicAppSettingsService;
use App\Services\StudentNotificationService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LandingPageController extends Controller
{
    public function index(
        Request $request,
        PackageChannelPricingService $pricing,
        AppReleaseChannelService $releases,
        PublicAppSettingsService $publicSettings
    ): View
    {
        $locale = $request->query('lang');

        if ($locale && in_array($locale, config('app.locales', ['ar', 'en']))) {
            session(['locale' => $locale]);
        }

        $locale = session('locale', config('app.locale', 'ar'));
        app()->setLocale($locale);

        $setting = Setting::first();
        $designSetting = DesignSetting::getDefaultSettings();
        $downloadChannels = $releases->urls($setting);
        $directDiscountPercent = $pricing->directDiscountPercent();
        $welcomeCoins = StudentNotificationService::registrationBonusOffer();
        $howPlatformWorksVideoUrl = $publicSettings->embedVideoUrl(
            $designSetting->how_platform_works_video_link
        );

        return view('landing.index', compact(
            'setting',
            'designSetting',
            'locale',
            'downloadChannels',
            'directDiscountPercent',
            'welcomeCoins',
            'howPlatformWorksVideoUrl'
        ));
    }
}
