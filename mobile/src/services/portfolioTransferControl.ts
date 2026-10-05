/** Standard AbortSignal contract shared by Axios, fetch and native XHR. */
export const assertPortfolioTransferActive = (signal?: AbortSignal) => {
  if (signal?.aborted) throw new Error('PORTFOLIO_UPLOAD_PAUSED');
};
