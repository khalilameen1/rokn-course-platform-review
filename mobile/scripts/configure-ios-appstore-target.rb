# Reuse the project's locked CocoaPods Xcodeproj library; app-target only.
require 'json'
require 'xcodeproj'

directory = File.realpath(ARGV.fetch(0))
metadata = JSON.parse(File.read(File.join(directory, 'rokn-signing-public.json')))
app = JSON.parse(File.read(File.expand_path('../app.json', __dir__))).fetch('expo')
project = Xcodeproj::Project.open(File.expand_path('../ios/Rokn.xcodeproj', __dir__))
targets = project.targets.select { |target| target.name == 'Rokn' }
raise 'Expected exactly one Rokn application target' unless targets.length == 1
target = targets.first
raise 'Rokn target is not an application' unless target.product_type == 'com.apple.product-type.application'
configuration = target.build_configurations.find { |candidate| candidate.name == 'Release' }
raise 'Release configuration absent' unless configuration
settings = configuration.build_settings
raise 'App bundle differs' unless settings['PRODUCT_BUNDLE_IDENTIFIER'] == metadata.fetch('bundleId')
raise 'App version differs' unless settings['MARKETING_VERSION'] == app.fetch('version')
raise 'App build differs' unless settings['CURRENT_PROJECT_VERSION'].to_s == app.fetch('ios').fetch('buildNumber').to_s
settings['CODE_SIGN_STYLE'] = 'Manual'
settings['DEVELOPMENT_TEAM'] = metadata.fetch('team')
settings['PROVISIONING_PROFILE_SPECIFIER'] = metadata.fetch('uuid')
settings['CODE_SIGN_IDENTITY'] = metadata.fetch('certificateSha1')
settings['CODE_SIGN_IDENTITY[sdk=iphoneos*]'] = metadata.fetch('certificateSha1')
settings['OTHER_CODE_SIGN_FLAGS'] = "$(inherited) --keychain #{File.join(directory, 'rokn-signing.keychain-db')}"
project.save
