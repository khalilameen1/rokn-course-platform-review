/** Transport progress is not server acceptance or video processing readiness. */
export type PortfolioTransferProgress = {
  loaded: number;
  total: number | null;
  phase: 'preparing' | 'uploading' | 'saving';
};

export type PortfolioUploadProgress = {
  completed: number;
  total: number;
  percentage: number | null;
  phase: PortfolioTransferProgress['phase'] | 'finalizing';
};

export type PortfolioTransferObserver = (
  progress: PortfolioTransferProgress,
) => void;

/** Multipart transport fractions are weighted by file payload size, not file count. */
export const createPortfolioUploadProgress = (
  sizes: Array<number | undefined>,
  notify?: (progress: PortfolioUploadProgress) => void,
) => {
  const positiveSize = (size: unknown) =>
    typeof size === 'number' && Number.isFinite(size) && size > 0 ? size : null;
  const weights = sizes.map(positiveSize);
  const fractions: Array<number | null> = sizes.map(() => 0);
  const settled = new Set<number>();
  let completed = 0;
  let phase: PortfolioUploadProgress['phase'] = 'preparing';
  let discarded = false;
  const publish = () => {
    const totalBytes = weights.reduce<number>(
      (sum, size) => sum + (size || 0),
      0,
    );
    const known =
      weights.every(size => size !== null) &&
      fractions.every(value => value !== null) &&
      totalBytes > 0;
    const snapshot: PortfolioUploadProgress = {
      completed,
      total: sizes.length,
      percentage:
        known && !discarded && phase !== 'finalizing' && phase !== 'preparing'
          ? Math.floor(
              (weights.reduce<number>(
                (sum, size, index) =>
                  sum + (size || 0) * (fractions[index] || 0),
                0,
              ) /
                totalBytes) *
                100,
            )
          : null,
      phase,
    };
    try {
      notify?.(snapshot);
    } catch {
      // Initial/settled notifications also run outside the delivery event
      // guard. Presentation must never stop the remaining durable uploads.
    }
  };
  publish();
  return {
    transfer(index: number, progress: PortfolioTransferProgress) {
      if (settled.has(index) || index < 0 || index >= sizes.length) return;
      const total = positiveSize(progress.total);
      if (!Number.isFinite(progress.loaded) || progress.loaded < 0) return;
      phase = progress.phase;
      if (total) {
        weights[index] ??= total;
        // A resumed/rejected attempt may legitimately move backwards to the
        // provider-confirmed offset; never invent retained bytes.
        fractions[index] = Math.min(1, progress.loaded / total);
      } else fractions[index] = null;
      publish();
    },
    settle(index: number, uploaded: boolean) {
      if (settled.has(index) || index < 0 || index >= sizes.length) return;
      settled.add(index);
      if (uploaded) {
        fractions[index] = 1;
        completed += 1;
      } else discarded = true;
      phase = settled.size === sizes.length ? 'finalizing' : 'preparing';
      publish();
    },
  };
};
