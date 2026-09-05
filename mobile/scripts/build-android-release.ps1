[CmdletBinding()]
param(
    [ValidateSet('direct', 'play')]
    [string]$Channel = 'direct',

    [ValidateSet('apk', 'aab')]
    [string]$Artifact = 'apk',

    [ValidateSet('test', 'production')]
    [string]$Profile = 'test',

    [string]$EnvFile = ''
)

$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$buildTemporaryDirectory = Join-Path $projectRoot '.cache\android-tmp'
New-Item -ItemType Directory -Path $buildTemporaryDirectory -Force | Out-Null
$env:TEMP = $buildTemporaryDirectory
$env:TMP = $buildTemporaryDirectory
$androidRoot = Join-Path $projectRoot 'android'
$checkedInGradleWrapper = Join-Path $androidRoot 'gradlew.bat'
$wrapperPropertiesPath = Join-Path $androidRoot 'gradle\wrapper\gradle-wrapper.properties'
$wrapperProperties = Get-Content -LiteralPath $wrapperPropertiesPath -Raw
$wrapperVersionMatch = [regex]::Match(
    $wrapperProperties,
    'distributionUrl=.*gradle-(?<version>\d+(?:\.\d+)+)-bin\.zip'
)
if (-not $wrapperVersionMatch.Success) {
    throw 'Could not determine the checked-in Gradle version from gradle-wrapper.properties.'
}
$gradleVersion = $wrapperVersionMatch.Groups['version'].Value
$offlineGradleLauncher = Join-Path $projectRoot ".gradle-local\gradle-$gradleVersion\bin\gradle.bat"
$wrapperCacheRoot = if ($env:USERPROFILE) {
    Join-Path $env:USERPROFILE ".gradle\wrapper\dists\gradle-$gradleVersion-bin"
} else {
    $null
}
$cachedWrapperGradleLauncher = if (
    $wrapperCacheRoot -and
    (Test-Path -LiteralPath $wrapperCacheRoot -PathType Container)
) {
    Get-ChildItem -LiteralPath $wrapperCacheRoot -Recurse -Filter 'gradle.bat' -ErrorAction SilentlyContinue |
        Where-Object { $_.FullName -match "gradle-$([regex]::Escape($gradleVersion))[\\/]bin[\\/]gradle\.bat$" } |
        Select-Object -First 1 -ExpandProperty FullName
} else {
    $null
}
$gradleLauncher = if (
    $env:ROKN_USE_LOCAL_GRADLE -eq '1' -and
    (Test-Path -LiteralPath $offlineGradleLauncher -PathType Leaf)
) {
    # Opt-in only. Keeping an extracted Gradle distribution inside a synced or
    # sandboxed workspace can make its JARs temporarily non-readable on
    # Windows. The checked-in wrapper is the reproducible default.
    $offlineGradleLauncher
} elseif ($cachedWrapperGradleLauncher) {
    # Use an already verified wrapper extraction without making an unnecessary
    # network request on restricted or offline build machines.
    $cachedWrapperGradleLauncher
} else {
    $checkedInGradleWrapper
}
$artifactDirectory = Join-Path $projectRoot 'artifacts'
$localPluginRepository = Join-Path $projectRoot '.gradle-local\plugin-repo'
$localPluginInitScript = Join-Path $projectRoot 'scripts\local-plugin-repository.init.gradle'

function Import-PublicEnvironmentFile {
    param([Parameter(Mandatory = $true)][string]$Path)

    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        throw "The requested environment file does not exist: $Path"
    }

    foreach ($rawLine in Get-Content -LiteralPath $Path) {
        if ($rawLine -notmatch '^\s*(?:export\s+)?(?<key>EXPO_PUBLIC_[A-Z0-9_]+)\s*=\s*(?<value>.*)\s*$') {
            continue
        }

        $key = $Matches.key
        $value = $Matches.value.Trim()
        if (
            $value.Length -ge 2 -and
            (($value.StartsWith('"') -and $value.EndsWith('"')) -or
             ($value.StartsWith("'") -and $value.EndsWith("'")))
        ) {
            $value = $value.Substring(1, $value.Length - 2)
        }

        # An explicitly exported process value wins over a file. This makes CI
        # overrides predictable and prevents a developer's local file from
        # silently changing a signed build.
        if (-not [Environment]::GetEnvironmentVariable($key, 'Process')) {
            [Environment]::SetEnvironmentVariable($key, $value, 'Process')
        }
    }
}

function Get-JavaMajorVersion {
    param([Parameter(Mandatory = $true)][string]$JavaHome)

    $releaseFile = Join-Path $JavaHome 'release'
    if (-not (Test-Path -LiteralPath $releaseFile -PathType Leaf)) {
        return $null
    }

    $releaseText = Get-Content -LiteralPath $releaseFile -Raw
    $versionMatch = [regex]::Match($releaseText, 'JAVA_VERSION\s*=\s*"(?<major>\d+)')
    if (-not $versionMatch.Success) {
        return $null
    }

    return [int]$versionMatch.Groups['major'].Value
}

function Test-ProductionApiUrl {
    param([Parameter(Mandatory = $true)][string]$Value)

    $parsed = $null
    if (-not [System.Uri]::TryCreate($Value, [System.UriKind]::Absolute, [ref]$parsed)) {
        throw 'EXPO_PUBLIC_API_URL must be an absolute URL.'
    }
    if ($parsed.Scheme -ne 'https') {
        throw 'Production builds require an HTTPS EXPO_PUBLIC_API_URL.'
    }

    $blockedHosts = @('localhost', '127.0.0.1', '0.0.0.0', 'example.com', 'api.example.com')
    if ($blockedHosts -contains $parsed.Host.ToLowerInvariant() -or $parsed.Host.EndsWith('.local')) {
        throw "Production builds cannot use the API host '$($parsed.Host)'."
    }

    $normalized = $parsed.GetLeftPart([System.UriPartial]::Path).TrimEnd('/') + '/'
    if ($normalized -cne 'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/api/v1/' -or $parsed.Query -or $parsed.Fragment) {
        throw "Production builds must use the deployed API base 'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/api/v1/'."
    }
}

function Get-StringSha256 {
    param([Parameter(Mandatory = $true)][string]$Value)

    $sha256 = [System.Security.Cryptography.SHA256]::Create()
    try {
        $bytes = [System.Text.Encoding]::UTF8.GetBytes($Value)
        return ([System.BitConverter]::ToString($sha256.ComputeHash($bytes))).Replace('-', '').ToLowerInvariant()
    }
    finally {
        $sha256.Dispose()
    }
}

function Get-FileSha256 {
    param([Parameter(Mandatory = $true)][string]$Path)

    $stream = [System.IO.File]::OpenRead($Path)
    $sha256 = [System.Security.Cryptography.SHA256]::Create()
    try {
        return ([System.BitConverter]::ToString($sha256.ComputeHash($stream))).Replace('-', '').ToLowerInvariant()
    }
    finally {
        $sha256.Dispose()
        $stream.Dispose()
    }
}

function Read-GradleProperties {
    param([Parameter(Mandatory = $true)][string[]]$Paths)

    $result = @{}
    foreach ($path in $Paths) {
        if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
            continue
        }
        foreach ($line in Get-Content -LiteralPath $path) {
            if ($line -match '^\s*(?<key>[A-Za-z0-9_.-]+)\s*[=:]\s*(?<value>.*)$') {
                $result[$Matches.key] = $Matches.value.Trim()
            }
        }
    }
    return $result
}

function Assert-ProductionSigningConfiguration {
    $propertyFiles = @(
        (Join-Path $androidRoot 'gradle.properties'),
        $(if ($env:USERPROFILE) { Join-Path $env:USERPROFILE '.gradle\gradle.properties' })
    ) | Where-Object { $_ }
    $properties = Read-GradleProperties -Paths $propertyFiles
    $requiredKeys = @(
        'UPLOAD_STORE_FILE',
        'UPLOAD_STORE_PASSWORD',
        'UPLOAD_KEY_ALIAS',
        'UPLOAD_KEY_PASSWORD'
    )
    foreach ($key in $requiredKeys) {
        $environmentKey = "ORG_GRADLE_PROJECT_$key"
        $environmentValue = [Environment]::GetEnvironmentVariable($environmentKey, 'Process')
        if ($environmentValue) {
            $properties[$key] = $environmentValue
        }
    }

    $missing = $requiredKeys | Where-Object {
        -not $properties.ContainsKey($_) -or [string]::IsNullOrWhiteSpace($properties[$_])
    }
    if ($missing) {
        throw "Production signing is incomplete. Missing Gradle properties: $($missing -join ', ')."
    }

    $configuredStore = $properties['UPLOAD_STORE_FILE']
    $resolvedStore = if ([System.IO.Path]::IsPathRooted($configuredStore)) {
        $configuredStore
    } else {
        Join-Path (Join-Path $androidRoot 'app') $configuredStore
    }
    if (-not (Test-Path -LiteralPath $resolvedStore -PathType Leaf)) {
        throw "The production keystore does not exist: $resolvedStore"
    }
}

if ($Channel -eq 'play' -and ($Profile -ne 'production' -or $Artifact -ne 'aab')) {
    throw 'Google Play output is always a signed production AAB. Use -Channel play -Profile production -Artifact aab.'
}
if ($Channel -eq 'direct' -and $Artifact -ne 'apk') {
    throw 'The direct channel produces an APK. Use -Channel direct -Artifact apk.'
}

$resolvedEnvironmentFile = $null
if ($EnvFile) {
    $resolvedEnvironmentFile = if ([System.IO.Path]::IsPathRooted($EnvFile)) {
        $EnvFile
    } else {
        Join-Path $projectRoot $EnvFile
    }
} elseif ($Profile -eq 'production') {
    $environmentCandidates = @(
        (Join-Path $projectRoot '.env.production.local'),
        (Join-Path $projectRoot '.env.production')
    )
    $resolvedEnvironmentFile = $environmentCandidates |
        Where-Object { Test-Path -LiteralPath $_ -PathType Leaf } |
        Select-Object -First 1
}

if ($resolvedEnvironmentFile) {
    Import-PublicEnvironmentFile -Path $resolvedEnvironmentFile
}

$appConfigPath = Join-Path $projectRoot 'app.json'
$packagePath = Join-Path $projectRoot 'package.json'
$appConfig = Get-Content -LiteralPath $appConfigPath -Raw | ConvertFrom-Json
$packageConfig = Get-Content -LiteralPath $packagePath -Raw | ConvertFrom-Json
$rootBuildGradle = Get-Content -LiteralPath (Join-Path $androidRoot 'build.gradle') -Raw
$minSdkMatch = [regex]::Match($rootBuildGradle, 'minSdkVersion\s*=\s*(?<value>\d+)')
$targetSdkMatch = [regex]::Match($rootBuildGradle, 'targetSdkVersion\s*=\s*(?<value>\d+)')
if (-not $minSdkMatch.Success -or -not $targetSdkMatch.Success) {
    throw 'Could not read Android minSdkVersion/targetSdkVersion for artifact provenance.'
}
if ($packageConfig.version -ne $appConfig.expo.version) {
    throw "Version mismatch: package.json is $($packageConfig.version), app.json is $($appConfig.expo.version)."
}
if (-not $appConfig.expo.android.versionCode -or [int]$appConfig.expo.android.versionCode -lt 1) {
    throw 'app.json must contain a positive expo.android.versionCode.'
}

if ($Profile -eq 'production') {
    $productionApiUrl = [Environment]::GetEnvironmentVariable('EXPO_PUBLIC_API_URL', 'Process')
    if ([string]::IsNullOrWhiteSpace($productionApiUrl)) {
        throw 'Production builds require EXPO_PUBLIC_API_URL. Set it in the process or in .env.production.'
    }
    Test-ProductionApiUrl -Value $productionApiUrl.Trim()

    $googleServicesPath = Join-Path $androidRoot 'app\google-services.json'
    if (-not (Test-Path -LiteralPath $googleServicesPath -PathType Leaf)) {
        throw 'Production builds require android/app/google-services.json so remote notifications can register with FCM.'
    }

    Assert-ProductionSigningConfiguration
}

$localJavaHomes = @()
$localJavaRoot = Join-Path $projectRoot '.jdk17'
if (Test-Path -LiteralPath $localJavaRoot -PathType Container) {
    $localJavaHomes += Get-ChildItem -LiteralPath $localJavaRoot -Directory -ErrorAction SilentlyContinue |
        Select-Object -ExpandProperty FullName
}
$javaCandidates = @(
    $localJavaHomes
    $env:JAVA_HOME
    'C:\Program Files\Android\Android Studio\jbr'
) | Where-Object { $_ -and (Test-Path -LiteralPath (Join-Path $_ 'bin\java.exe') -PathType Leaf) }
$javaHome = $javaCandidates |
    Where-Object { (Get-JavaMajorVersion -JavaHome $_) -eq 17 } |
    Select-Object -First 1

if (-not $javaHome) {
    throw 'A JDK 17 installation is required. Set JAVA_HOME to JDK 17 or place it below .jdk17.'
}

$sdkCandidates = @(
    $env:ANDROID_HOME
    $env:ANDROID_SDK_ROOT
    $(if ($env:LOCALAPPDATA) { Join-Path $env:LOCALAPPDATA 'Android\Sdk' })
) | Where-Object { $_ -and (Test-Path -LiteralPath $_ -PathType Container) }
$androidSdk = $sdkCandidates | Select-Object -First 1
if (-not $androidSdk) {
    throw 'Android SDK was not found. Set ANDROID_HOME or install it with Android Studio.'
}
if (-not (Test-Path -LiteralPath $gradleLauncher -PathType Leaf)) {
    throw "Neither the checked-in Gradle wrapper nor the local Gradle $gradleVersion cache was found."
}

$env:JAVA_HOME = $javaHome
$env:ANDROID_HOME = $androidSdk
$env:ANDROID_SDK_ROOT = $androidSdk
$env:NODE_ENV = 'production'
$env:EXPO_PUBLIC_DISTRIBUTION_CHANNEL = $Channel
$env:EXPO_PUBLIC_BUILD_PROFILE = $Profile
$env:EXPO_PUBLIC_REQUIRE_FEATURE_FLAGS = '1'

if ($Profile -eq 'production') {
    $dirtyPaths = @(& git -C $projectRoot status --porcelain 2>$null)
    if ($dirtyPaths.Count -gt 0) {
        throw @"
Production release refused because the source tree is not clean.
Commit the reviewed source first so this artifact can be reproduced from its recorded commit.
Use the test profile for local investigations; production artifacts never allow an override.
"@
    }
    $npmCommand = Get-Command 'npm.cmd' -ErrorAction SilentlyContinue
    if (-not $npmCommand) {
        throw 'npm.cmd was not found on PATH.'
    }
    & $npmCommand.Source run verify:release
    if ($LASTEXITCODE -ne 0) {
        throw "JavaScript release quality gates failed with exit code $LASTEXITCODE."
    }
}

$androidArchitectures = if ($Channel -eq 'play') {
    'armeabi-v7a,arm64-v8a,x86,x86_64'
} elseif ($env:ROKN_ANDROID_ARCHITECTURES) {
    $env:ROKN_ANDROID_ARCHITECTURES
} elseif ($Profile -eq 'test') {
    # The internal artifact must exercise the same Android 7+ device floor as
    # production as well as the common 64-bit emulator. A 32-bit Android 7–9
    # tester must not receive INSTALL_FAILED_NO_MATCHING_ABIS.
    'armeabi-v7a,arm64-v8a,x86_64'
} else {
    'armeabi-v7a,arm64-v8a'
}

$builtArtifact = if ($Artifact -eq 'aab') {
    Join-Path $androidRoot 'app\build\outputs\bundle\release\app-release.aab'
} else {
    Join-Path $androidRoot 'app\build\outputs\apk\release\app-release.apk'
}
if (Test-Path -LiteralPath $builtArtifact -PathType Leaf) {
    Remove-Item -LiteralPath $builtArtifact -Force
}

$isProduction = $Profile -eq 'production'
$releaseTask = if ($Artifact -eq 'aab') { ':app:bundleRelease' } else { ':app:assembleRelease' }
$gradleArguments = @(
    '--no-daemon',
    '--no-parallel',
    '--max-workers=1',
    "-PreactNativeArchitectures=$androidArchitectures",
    "-ProknDistributionChannel=$Channel",
    "-ProknBuildProfile=$Profile",
    "-ProknRequireReleaseSigning=$($isProduction.ToString().ToLowerInvariant())",
    "-ProknRequireApiUrl=$($isProduction.ToString().ToLowerInvariant())",
    "-ProknEnableMinify=$($isProduction.ToString().ToLowerInvariant())",
    "-ProknEnableResourceShrink=$($isProduction.ToString().ToLowerInvariant())",
    '-Dkotlin.incremental=false',
    '-Dkotlin.compiler.execution.strategy=in-process'
)
if (
    (Test-Path -LiteralPath $localPluginRepository -PathType Container) -and
    (Test-Path -LiteralPath $localPluginInitScript -PathType Leaf)
) {
    $env:ROKN_LOCAL_GRADLE_PLUGIN_REPOSITORY = $localPluginRepository
    $gradleArguments = @('--init-script', $localPluginInitScript) + $gradleArguments
}
if ($isProduction) {
    $gradleArguments += @(':app:lintRelease', ':app:testReleaseUnitTest')
}
$gradleArguments += $releaseTask
$buildStartedAtUtc = [DateTime]::UtcNow

Push-Location $androidRoot
try {
    & $gradleLauncher @gradleArguments
    if ($null -eq $LASTEXITCODE) {
        throw 'Gradle ended without returning an exit code.'
    }
    if ($LASTEXITCODE -ne 0) {
        throw "Gradle failed with exit code $LASTEXITCODE."
    }
} finally {
    Pop-Location
}

if (-not (Test-Path -LiteralPath $builtArtifact -PathType Leaf)) {
    throw "Gradle finished without producing the expected artifact at $builtArtifact"
}
if ((Get-Item -LiteralPath $builtArtifact).LastWriteTimeUtc -lt $buildStartedAtUtc) {
    throw "Gradle returned a stale artifact older than this build: $builtArtifact"
}

$signerSha256 = $null
$signerRole = $null
if ($Artifact -eq 'apk') {
    $buildToolsRoot = Join-Path $androidSdk 'build-tools'
    $apkSigner = Get-ChildItem -LiteralPath $buildToolsRoot -Directory -ErrorAction SilentlyContinue |
        Sort-Object { [version]$_.Name } -Descending |
        ForEach-Object { Join-Path $_.FullName 'apksigner.bat' } |
        Where-Object { Test-Path -LiteralPath $_ -PathType Leaf } |
        Select-Object -First 1
    if (-not $apkSigner) {
        throw 'apksigner was not found in the Android SDK build-tools directory.'
    }
    $signatureReport = (& $apkSigner verify --print-certs $builtArtifact 2>&1 | Out-String)
    if ($LASTEXITCODE -ne 0) {
        throw "APK signature verification failed.`n$signatureReport"
    }
    $isDebugSigner = $signatureReport -match '(?im)Signer #1 certificate DN:.*CN=Android Debug'
    if ($isProduction -and $isDebugSigner) {
        throw 'A production APK was signed with the Android debug certificate.'
    }
    $signerMatch = [regex]::Match(
        $signatureReport,
        '(?im)^Signer #1 certificate SHA-256 digest:\s*(?<digest>[0-9a-f:]+)\s*$'
    )
    if (-not $signerMatch.Success) {
        throw 'Unable to read the production APK signer SHA-256 fingerprint.'
    }
    $signerSha256 = $signerMatch.Groups['digest'].Value.Replace(':', '').ToLowerInvariant()
    $signerRole = if ($isDebugSigner) { 'internal-debug' } else { 'release-app-signing' }

    if ($isProduction -and $Channel -eq 'direct') {
        $expectedAppSigner = [Environment]::GetEnvironmentVariable(
            'ROKN_ANDROID_APP_SIGNING_SHA256',
            'Process'
        )
        $normalizedExpectedSigner = ($expectedAppSigner -replace '[^0-9A-Fa-f]', '').ToLowerInvariant()
        if ($normalizedExpectedSigner -notmatch '^[0-9a-f]{64}$') {
            throw @"
Direct production APKs must pin ROKN_ANDROID_APP_SIGNING_SHA256 to the public
SHA-256 certificate fingerprint configured as the Google Play App Signing key
and in APP_LINK_ANDROID_SHA256_FINGERPRINTS. This keeps direct/store installs
upgrade-compatible instead of forcing an uninstall that would erase local data.
"@
        }
        if ($signerSha256 -ne $normalizedExpectedSigner) {
            throw 'The direct APK signer does not match ROKN_ANDROID_APP_SIGNING_SHA256.'
        }
    }
}
if ($isProduction -and $Artifact -eq 'aab') {
    $jarSigner = Join-Path $javaHome 'bin\jarsigner.exe'
    if (-not (Test-Path -LiteralPath $jarSigner -PathType Leaf)) {
        throw 'jarsigner was not found in the selected JDK 17 installation.'
    }
    $signatureReport = (& $jarSigner -verify -verbose -certs $builtArtifact 2>&1 | Out-String)
    if ($LASTEXITCODE -ne 0 -or $signatureReport -notmatch 'jar verified') {
        throw "AAB signature verification failed.`n$signatureReport"
    }
    if ($signatureReport -match 'Android Debug') {
        throw 'A production AAB was signed with the Android debug certificate.'
    }
    $keyTool = Join-Path $javaHome 'bin\keytool.exe'
    $certificateReport = (& $keyTool -printcert -jarfile $builtArtifact 2>&1 | Out-String)
    $signerMatch = [regex]::Match(
        $certificateReport,
        '(?im)^\s*SHA256:\s*(?<digest>[0-9a-f:]+)\s*$'
    )
    if ($LASTEXITCODE -ne 0 -or -not $signerMatch.Success) {
        throw "Unable to read the production AAB signer SHA-256 fingerprint.`n$certificateReport"
    }
    $signerSha256 = $signerMatch.Groups['digest'].Value.Replace(':', '').ToLowerInvariant()
    $signerRole = 'play-upload'
}

New-Item -ItemType Directory -Path $artifactDirectory -Force | Out-Null
$artifactName = if ($Profile -eq 'test') {
    'Rokn-internal-test.apk'
} elseif ($Channel -eq 'play') {
    'Rokn-play.aab'
} else {
    'Rokn-direct.apk'
}
$artifactPath = Join-Path $artifactDirectory $artifactName
Copy-Item -LiteralPath $builtArtifact -Destination $artifactPath -Force

$symbolDirectory = Join-Path $artifactDirectory ([System.IO.Path]::GetFileNameWithoutExtension($artifactName) + '-symbols')
New-Item -ItemType Directory -Path $symbolDirectory -Force | Out-Null
foreach ($oldSymbolFile in @('index.android.bundle.map', 'mapping.txt')) {
    $oldSymbolPath = Join-Path $symbolDirectory $oldSymbolFile
    if (Test-Path -LiteralPath $oldSymbolPath -PathType Leaf) {
        Remove-Item -LiteralPath $oldSymbolPath -Force
    }
}
$sourceMap = Join-Path $androidRoot 'app\build\generated\sourcemaps\react\release\index.android.bundle.map'
$r8Mapping = Join-Path $androidRoot 'app\build\outputs\mapping\release\mapping.txt'
if ($isProduction) {
    if (-not (Test-Path -LiteralPath $sourceMap -PathType Leaf) -or
        (Get-Item -LiteralPath $sourceMap).Length -eq 0) {
        throw 'Production build completed without a Hermes source map.'
    }
    if (-not (Test-Path -LiteralPath $r8Mapping -PathType Leaf) -or
        (Get-Item -LiteralPath $r8Mapping).Length -eq 0) {
        throw 'Production build completed without an R8 mapping file.'
    }
}
if (Test-Path -LiteralPath $sourceMap -PathType Leaf) {
    Copy-Item -LiteralPath $sourceMap -Destination (Join-Path $symbolDirectory 'index.android.bundle.map') -Force
}
if ($isProduction -and (Test-Path -LiteralPath $r8Mapping -PathType Leaf)) {
    Copy-Item -LiteralPath $r8Mapping -Destination (Join-Path $symbolDirectory 'mapping.txt') -Force
}

$gitCommit = (& git -C $projectRoot rev-parse HEAD 2>$null | Select-Object -First 1)
$gitDirty = [bool](& git -C $projectRoot status --porcelain 2>$null | Select-Object -First 1)
$artifactSha256 = Get-FileSha256 -Path $artifactPath
$metadataApiHost = 'rokn-course-platform-review-production-b7gpy1.laravel.cloud'
$metadataApiBase = 'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/api/v1/'
$metadataApiSource = 'source-default'
if ($env:EXPO_PUBLIC_API_URL) {
    $metadataApiUrl = $null
    if ([System.Uri]::TryCreate($env:EXPO_PUBLIC_API_URL, [System.UriKind]::Absolute, [ref]$metadataApiUrl)) {
        $metadataApiHost = $metadataApiUrl.Host
        $metadataApiBase = $metadataApiUrl.GetLeftPart([System.UriPartial]::Path).TrimEnd('/') + '/'
        $metadataApiSource = 'environment'
    } else {
        $metadataApiHost = 'invalid-development-url'
        $metadataApiSource = 'invalid-environment'
    }
}
$metadata = [ordered]@{
    name = $artifactName
    version = [string]$appConfig.expo.version
    versionCode = [int]$appConfig.expo.android.versionCode
    applicationId = [string]$appConfig.expo.android.package
    channel = $Channel
    profile = $Profile
    format = $Artifact
    minSdk = [int]$minSdkMatch.Groups['value'].Value
    targetSdk = [int]$targetSdkMatch.Groups['value'].Value
    abis = @($androidArchitectures.Split(',') | ForEach-Object { $_.Trim() })
    sha256 = $artifactSha256
    bytes = (Get-Item -LiteralPath $artifactPath).Length
    signerSha256 = $signerSha256
    signerRole = $signerRole
    publicDistributionEligible = [bool]($isProduction -and $signerRole -ne 'internal-debug')
    apiHost = $metadataApiHost
    apiBase = $metadataApiBase
    apiBaseSha256 = Get-StringSha256 -Value $metadataApiBase
    apiPathHash = Get-StringSha256 -Value ([System.Uri]$metadataApiBase).AbsolutePath
    apiSource = $metadataApiSource
    gitCommit = [string]$gitCommit
    gitDirty = $gitDirty
    builtAtUtc = [DateTime]::UtcNow.ToString('o')
}
$metadataPath = $artifactPath + '.json'
$metadata | ConvertTo-Json | Set-Content -LiteralPath $metadataPath -Encoding UTF8

Write-Output "Artifact ready: $artifactPath"
Write-Output "SHA-256: $($metadata.sha256)"
Write-Output "Build metadata: $metadataPath"
if ($Profile -eq 'test') {
    Write-Warning 'Internal test APK: debug-signed and not eligible for public website distribution or store upgrade testing.'
}
