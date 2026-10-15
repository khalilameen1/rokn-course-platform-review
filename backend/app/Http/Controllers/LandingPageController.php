<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DesignSetting;
use App\Models\Setting;
use App\Services\AppReleaseChannelService;
use App\Services\PublicAppSettingsService;
use App\Services\WelcomeRewardOfferService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LandingPageController extends Controller
{
    public function index(
        Request $request,
        AppReleaseChannelService $releases,
        PublicAppSettingsService $publicSettings,
        WelcomeRewardOfferService $welcomeOffer
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
        // This public page promotes store distribution only. Direct release
        // records and their API/download routes remain available independently.
        $downloadChannels = array_intersect_key($releases->urls($setting), array_flip(['play', 'appstore']));
        $welcomeCoins = $welcomeOffer->amountForProvider();
        $howPlatformWorksVideoUrl = $publicSettings->embedVideoUrl(
            $designSetting->how_platform_works_video_link
        );

        return view('landing.index', compact(
            'setting',
            'designSetting',
            'locale',
            'downloadChannels',
            'welcomeCoins',
            'howPlatformWorksVideoUrl'
        ));
    }
}
