<?php

use Illuminate\Support\Facades\Request;

/** Invalidate local frontend assets on deployment without expiring page data. */
function versioned_asset(string $path): string
{
    $file = public_path($path);

    return asset($path).(is_file($file) ? '?v='.filemtime($file) : '');
}

/**
 * @param $route
 * @return bool
 */
function isRouteActive($route)
{
    return !! preg_match("/^{$route}$/", Request::route() ? Request::route()->getName() : '');
}


/**
 * @param $route
 * @return string
 */
function activateRouteClass($route)
{
    if (! isRouteActive($route)) {
        return '';
    }
    return 'is-active';
}

function translateCurrency($currency)
{
    // Don't translate if the locale is English
    $code = strtoupper(trim((string) $currency));
    if (!\App\Support\RoknLocale::isArabic()) {
        return $code;
    }

    return [
        'EGP' => 'جنيه',
        'USD' => 'دولار',
        'EUR' => 'يورو',
        'AED' => 'درهم إماراتي',
        'SAR' => 'ريال سعودي',
        'GBP' => 'جنيه إسترليني',
        'KWD' => 'دينار كويتي',
    ][$code] ?? $code;
}

/**
 * Calculates the great-circle distance between two points, with
 * the Vincenty formula.
 * @param float $latitudeFrom Latitude of start point in [deg decimal]
 * @param float $longitudeFrom Longitude of start point in [deg decimal]
 * @param float $latitudeTo Latitude of target point in [deg decimal]
 * @param float $longitudeTo Longitude of target point in [deg decimal]
 * @param float $earthRadius Mean earth radius in [m]
 * @return float Distance between points in [m] (same as earthRadius)
 */
function vincentyGreatCircleDistance(
  $latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo, $earthRadius = 6371000)
{
  // convert from degrees to radians
  $latFrom = deg2rad($latitudeFrom);
  $lonFrom = deg2rad($longitudeFrom);
  $latTo = deg2rad($latitudeTo);
  $lonTo = deg2rad($longitudeTo);

  $lonDelta = $lonTo - $lonFrom;
  $a = pow(cos($latTo) * sin($lonDelta), 2) +
    pow(cos($latFrom) * sin($latTo) - sin($latFrom) * cos($latTo) * cos($lonDelta), 2);
  $b = sin($latFrom) * sin($latTo) + cos($latFrom) * cos($latTo) * cos($lonDelta);

  $angle = atan2(sqrt($a), $b);
  $distance_in_meter =  $angle * $earthRadius;
  $distance_in_km = $distance_in_meter/1000;
  return round($distance_in_km,00);
}



