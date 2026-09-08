#import <React/RCTBridgeModule.h>

@interface RCT_EXTERN_MODULE(RoknDownloads, NSObject)
RCT_EXTERN_METHOD(inspectMetadata:(NSString *)url
                  resolver:(RCTPromiseResolveBlock)resolve
                  rejecter:(RCTPromiseRejectBlock)reject)
@end
