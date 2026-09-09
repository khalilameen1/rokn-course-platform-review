[CmdletBinding()]
param(
    [string]$GradleUserHome = $(if ($env:GRADLE_USER_HOME) {
        $env:GRADLE_USER_HOME
    } else {
        Join-Path $env:USERPROFILE '.gradle'
    }),
    [string]$Java = 'java',
    [ValidateSet('metadata', 'lifecycle')]
    [string]$Suite = 'metadata',
    [switch]$KeepBuild,
    [string[]]$TestArguments = @()
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

# Offline JVM coverage of real Kotlin, not an Android build or native-device test.
$mobileRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..\..\..')).Path
$probeSource = Join-Path $mobileRoot 'android\app\src\main\java\com\rokn\downloads\AttachmentMetadataProbe.kt'
$testSource = Join-Path $PSScriptRoot 'ProbeNetworkTest.kt'
$productionSources = @($probeSource)
$mainClass = 'com.rokn.downloads.ProbeNetworkTestKt'
if ($Suite -eq 'lifecycle') {
    $lifecycleRoot = Join-Path $PSScriptRoot '..\android-downloads-lifecycle'
    $testSource = Join-Path $lifecycleRoot 'DownloadLifecycleTest.kt'
    $mainClass = 'com.rokn.downloads.DownloadLifecycleTestKt'
    $productionSources += @('RoknDownloadsModule.kt', 'AttachmentFileValidation.kt', 'AttachmentDownloadReceiver.kt') | ForEach-Object {
        Join-Path (Split-Path -Parent $probeSource) $_
    }
}
$cacheRoot = Join-Path $GradleUserHome 'caches\modules-2\files-2.1'
$javaCommand = (Get-Command $Java -CommandType Application -ErrorAction Stop).Source
foreach ($source in ($productionSources + @($testSource))) {
    if (-not (Test-Path -LiteralPath $source -PathType Leaf)) {
        throw "Required Kotlin source is missing: $source"
    }
}

function Get-CachedJar([string]$Group, [string]$Artifact, [string]$Version) {
    $versionRoot = Join-Path $cacheRoot "$Group\$Artifact\$Version"
    if (-not (Test-Path -LiteralPath $versionRoot -PathType Container)) {
        throw "Missing cached dependency $Group`:$Artifact`:$Version. This runner never downloads dependencies."
    }
    $jars = @(Get-ChildItem -LiteralPath $versionRoot -Recurse -File -Filter "$Artifact-$Version.jar")
    if ($jars.Count -ne 1) {
        throw "Expected one cached $Artifact-$Version.jar, found $($jars.Count) in $versionRoot"
    }
    return $jars[0].FullName
}

$stdlib = Get-CachedJar 'org.jetbrains.kotlin' 'kotlin-stdlib' '2.1.20'
$annotations = Get-CachedJar 'org.jetbrains' 'annotations' '13.0'
# Runtime dependencies from kotlin-compiler-embeddable 2.1.20's cached POM.
$compilerJars = @(
    (Get-CachedJar 'org.jetbrains.kotlin' 'kotlin-compiler-embeddable' '2.1.20')
    $stdlib
    (Get-CachedJar 'org.jetbrains.kotlin' 'kotlin-script-runtime' '2.1.20')
    (Get-CachedJar 'org.jetbrains.kotlin' 'kotlin-reflect' '1.6.10')
    (Get-CachedJar 'org.jetbrains.kotlin' 'kotlin-daemon-embeddable' '2.1.20')
    (Get-CachedJar 'org.jetbrains.intellij.deps' 'trove4j' '1.0.20200330')
    (Get-CachedJar 'org.jetbrains.kotlinx' 'kotlinx-coroutines-core-jvm' '1.8.0')
    $annotations
)
$runtimeJars = @(
    $stdlib
    $annotations
    (Get-CachedJar 'com.squareup.okhttp3' 'okhttp' '4.12.0')
    (Get-CachedJar 'com.squareup.okio' 'okio-jvm' '3.6.0')
)
$separator = [IO.Path]::PathSeparator
$runtimeClasspath = $runtimeJars -join $separator
$stubSources = @(Get-ChildItem -LiteralPath (Join-Path $PSScriptRoot 'stubs') -File -Filter '*.kt' | Sort-Object Name | ForEach-Object FullName)
if ($stubSources.Count -ne 3) {
    throw 'Expected exactly the three Android/React Native platform stubs.'
}
if ($Suite -eq 'lifecycle') {
    $stubSources += Get-ChildItem -LiteralPath (Join-Path $lifecycleRoot 'stubs') -File -Filter '*.kt' | Sort-Object Name | ForEach-Object FullName
}

$temporaryRoot = (Resolve-Path -LiteralPath ([IO.Path]::GetTempPath())).Path.TrimEnd('\', '/')
$temporaryName = 'rokn-downloads-probe-' + [Guid]::NewGuid().ToString('D')
$buildPath = Join-Path $temporaryRoot $temporaryName
$buildDirectory = New-Item -ItemType Directory -Path $buildPath
try {
    $classesPath = Join-Path $buildDirectory.FullName 'classes'
    New-Item -ItemType Directory -Path $classesPath | Out-Null
    $compilerArguments = @(
        '-cp', ($compilerJars -join $separator),
        'org.jetbrains.kotlin.cli.jvm.K2JVMCompiler',
        '-no-stdlib', '-no-reflect', '-jvm-target', '17',
        '-classpath', $runtimeClasspath,
        '-d', $classesPath
    ) + $productionSources + @($testSource) + $stubSources
    & $javaCommand @compilerArguments
    if ($LASTEXITCODE -ne 0) {
        throw "Kotlin $Suite test compilation failed (exit $LASTEXITCODE)."
    }

    & $javaCommand '-cp' ($classesPath + $separator + $runtimeClasspath) $mainClass @TestArguments
    if ($LASTEXITCODE -ne 0) {
        throw "Download $Suite tests failed (exit $LASTEXITCODE)."
    }
} finally {
    if ($KeepBuild) {
        Write-Host "Probe test classes retained at $($buildDirectory.FullName)"
    } else {
        # Delete only this invocation's generated classes after validating the
        # exact direct child, never a cache/workspace root or a reparse point.
        $resolvedBuild = Get-Item -LiteralPath $buildDirectory.FullName
        if (
            $resolvedBuild.Parent.FullName.TrimEnd('\', '/') -ne $temporaryRoot -or
            $resolvedBuild.Name -ne $temporaryName -or
            ($resolvedBuild.Attributes -band [IO.FileAttributes]::ReparsePoint)
        ) {
            throw "Refusing to remove unexpected temporary path: $($resolvedBuild.FullName)"
        }
        Remove-Item -LiteralPath $resolvedBuild.FullName -Recurse -Force
    }
}
