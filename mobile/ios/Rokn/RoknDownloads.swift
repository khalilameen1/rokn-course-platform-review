import Foundation
import React

@objc(RoknDownloads)
final class RoknDownloads: NSObject {
  @objc static func requiresMainQueueSetup() -> Bool { false }

  @objc(inspectMetadata:resolver:rejecter:)
  func inspectMetadata(
    _ value: String,
    resolver resolve: @escaping RCTPromiseResolveBlock,
    rejecter reject: @escaping RCTPromiseRejectBlock
  ) {
    AttachmentMetadataSession().start(value, resolve: resolve, reject: reject)
  }
}

private final class AttachmentMetadataSession: NSObject, URLSessionDataDelegate {
  private var redirects = 0
  private var completed = false
  private var resolveResult: RCTPromiseResolveBlock?
  private var rejectResult: RCTPromiseRejectBlock?

  private func allowed(_ url: URL) -> Bool {
    url.scheme?.lowercased() == "https" && url.host != nil &&
      url.user == nil && url.password == nil
  }

  func start(
    _ value: String,
    resolve: @escaping RCTPromiseResolveBlock,
    reject: @escaping RCTPromiseRejectBlock
  ) {
    guard let url = URL(string: value), allowed(url) else {
      reject("INVALID_DOWNLOAD_URL", "Only secure download links are supported", nil)
      return
    }
    resolveResult = resolve
    rejectResult = reject
    let config = URLSessionConfiguration.ephemeral
    config.httpCookieStorage = nil
    config.httpShouldSetCookies = false
    config.urlCredentialStorage = nil
    config.urlCache = nil
    config.timeoutIntervalForRequest = 8
    config.timeoutIntervalForResource = 8
    let session = URLSession(configuration: config, delegate: self, delegateQueue: nil)
    var request = URLRequest(url: url)
    // GET works with links signed for GET only. The response delegate cancels
    // before accepting any body bytes, including large file responses.
    request.httpMethod = "GET"
    request.httpShouldHandleCookies = false
    request.setValue("identity", forHTTPHeaderField: "Accept-Encoding")
    session.dataTask(with: request).resume()
  }

  func urlSession(
    _ session: URLSession,
    dataTask: URLSessionDataTask,
    didReceive response: URLResponse,
    completionHandler: @escaping (URLSession.ResponseDisposition) -> Void
  ) {
    if !completed {
      completed = true
      if let http = response as? HTTPURLResponse, let finalURL = http.url {
        var result: [String: Any] = ["url": finalURL.absoluteString, "statusCode": http.statusCode]
        result["contentType"] = http.value(forHTTPHeaderField: "Content-Type")
        result["contentDisposition"] = http.value(forHTTPHeaderField: "Content-Disposition")
        let rangeTotal = http.value(forHTTPHeaderField: "Content-Range")?.components(separatedBy: "/").last ?? ""
        let total = http.statusCode == 206 ? Int64(rangeTotal) ?? -1 : http.expectedContentLength
        if total > 0 { result["contentLength"] = total }
        resolveResult?(result)
      } else {
        rejectResult?("DOWNLOAD_METADATA_FAILED", "The host could not provide file metadata", nil)
      }
    }
    completionHandler(.cancel)
    session.invalidateAndCancel()
  }

  func urlSession(
    _ session: URLSession,
    task: URLSessionTask,
    didCompleteWithError error: Error?
  ) {
    if !completed {
      completed = true
      rejectResult?("DOWNLOAD_METADATA_FAILED", "The host could not provide file metadata", error)
    }
    session.invalidateAndCancel()
  }

  func urlSession(
    _ session: URLSession,
    task: URLSessionTask,
    willPerformHTTPRedirection response: HTTPURLResponse,
    newRequest request: URLRequest,
    completionHandler: @escaping (URLRequest?) -> Void
  ) {
    redirects += 1
    guard redirects <= 5, let url = request.url, allowed(url) else {
      completionHandler(nil)
      return
    }
    var next = URLRequest(url: url)
    next.httpMethod = "GET"
    next.httpShouldHandleCookies = false
    next.setValue("identity", forHTTPHeaderField: "Accept-Encoding")
    completionHandler(next)
  }

  func urlSession(
    _ session: URLSession,
    task: URLSessionTask,
    didReceive challenge: URLAuthenticationChallenge,
    completionHandler: @escaping (URLSession.AuthChallengeDisposition, URLCredential?) -> Void
  ) {
    completionHandler(
      challenge.protectionSpace.authenticationMethod == NSURLAuthenticationMethodServerTrust
        ? .performDefaultHandling : .cancelAuthenticationChallenge,
      nil
    )
  }
}
