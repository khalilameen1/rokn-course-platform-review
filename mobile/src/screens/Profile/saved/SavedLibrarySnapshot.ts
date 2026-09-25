import type {SavedFolderOption} from '../../../components/VideoPlayer/courseLearning/savedFolderIndex';
import type {SavedLesson} from '../../../services/roknApi';

type LibrarySnapshot = {
  saved: SavedLesson[];
  folders: SavedFolderOption[];
};
type LessonRemoval = {
  owner: object;
  item: SavedLesson;
  index: number;
  countSnapshot: object | undefined;
  decrementedCount: boolean;
  settled: boolean;
};
type FolderRemoval = {
  owner: object;
  folder: SavedFolderOption;
  index: number;
  rows: Array<{index: number; item: SavedLesson}>;
  settled: boolean;
};
type FolderCountRead = {owner: object; folderId: string; snapshot: object};

/**
 * In-memory view state only. No network, persistence, account lookup or alerts.
 * Async scope ownership belongs to the controller; rollback/count receipts belong
 * here so every command applies the same snapshot and reconciliation rules.
 */
export class SavedLibrarySnapshot {
  private value: LibrarySnapshot = {saved: [], folders: []};
  private owner = {};
  private countSnapshots = new Map<string, object>();
  private listeners = new Set<() => void>();

  getSnapshot = () => this.value;
  subscribe = (listener: () => void) => {
    this.listeners.add(listener);
    return () => {
      this.listeners.delete(listener);
    };
  };

  private publish(next: LibrarySnapshot) {
    this.value = next;
    this.listeners.forEach(listener => listener());
  }

  clear() {
    this.owner = {};
    this.countSnapshots.clear();
    this.publish({saved: [], folders: []});
  }

  replaceRows(saved: SavedLesson[]) {
    this.publish({...this.value, saved});
  }

  appendRows(rows: SavedLesson[]) {
    const existing = new Set(this.value.saved.map(item => this.rowKey(item)));
    this.replaceRows([
      ...this.value.saved,
      ...rows.filter(item => !existing.has(this.rowKey(item))),
    ]);
  }

  replaceFolderIndex(folders: SavedFolderOption[], retainFolderId?: string) {
    folders.forEach(folder => this.countSnapshots.set(folder.id, {}));
    const selected = this.value.folders.find(
      folder => folder.id === retainFolderId,
    );
    // A successful folder-page read outranks absence in a possibly offline index.
    this.publish({
      ...this.value,
      folders:
        selected && !folders.some(folder => folder.id === selected.id)
          ? [...folders, selected]
          : folders,
    });
  }

  replaceFolderTotal(folderId: string, lessonsCount: number | undefined) {
    this.countSnapshots.set(folderId, {});
    this.publish({
      ...this.value,
      folders: this.value.folders.map(folder =>
        folder.id === folderId ? {...folder, lessonsCount} : folder,
      ),
    });
  }

  acceptCreatedFolder(folder: SavedFolderOption) {
    this.countSnapshots.set(folder.id, {});
    this.publish({
      ...this.value,
      folders: [
        ...this.value.folders.filter(item => item.id !== folder.id),
        folder,
      ],
    });
  }

  removeFolder(folderId: string) {
    this.publish({
      ...this.value,
      folders: this.value.folders.filter(folder => folder.id !== folderId),
    });
  }

  private rowKey(item: SavedLesson) {
    return `${item.folderId}:${item.id}`;
  }

  private removeRow(item: SavedLesson) {
    const key = this.rowKey(item);
    this.replaceRows(this.value.saved.filter(row => this.rowKey(row) !== key));
  }

  beginLessonRemoval(item: SavedLesson): LessonRemoval | null {
    const index = this.value.saved.findIndex(
      row => this.rowKey(row) === this.rowKey(item),
    );
    if (index < 0) return null;
    const count = this.value.folders.find(
      folder => folder.id === item.folderId,
    )?.lessonsCount;
    const receipt: LessonRemoval = {
      owner: this.owner,
      item,
      index,
      countSnapshot: this.countSnapshots.get(item.folderId),
      decrementedCount: count !== undefined && count > 0,
      settled: false,
    };
    this.publish({
      saved: this.value.saved.filter(
        row => this.rowKey(row) !== this.rowKey(item),
      ),
      folders: this.value.folders.map(folder =>
        folder.id === item.folderId && count !== undefined && count > 0
          ? {...folder, lessonsCount: count - 1}
          : folder,
      ),
    });
    return receipt;
  }

  confirmLessonRemoval(receipt: LessonRemoval): FolderCountRead | null {
    if (receipt.owner !== this.owner || receipt.settled) return null;
    receipt.settled = true;
    this.removeRow(receipt.item);
    if (
      this.countSnapshots.get(receipt.item.folderId) === receipt.countSnapshot
    )
      return null;
    // A replacement total may predate or include the server delete. Never guess.
    this.replaceFolderTotal(receipt.item.folderId, undefined);
    return {
      owner: this.owner,
      folderId: receipt.item.folderId,
      snapshot: this.countSnapshots.get(receipt.item.folderId)!,
    };
  }

  isCurrentCountRead(read: FolderCountRead) {
    return (
      read.owner === this.owner &&
      this.countSnapshots.get(read.folderId) === read.snapshot
    );
  }

  acceptFolderCount(read: FolderCountRead, lessonsCount: number | undefined) {
    if (!this.isCurrentCountRead(read)) return;
    this.replaceFolderTotal(read.folderId, lessonsCount);
  }

  rollbackLessonRemoval(receipt: LessonRemoval) {
    if (receipt.owner !== this.owner || receipt.settled) return;
    receipt.settled = true;
    const saved = [...this.value.saved];
    if (!saved.some(row => this.rowKey(row) === this.rowKey(receipt.item))) {
      saved.splice(Math.min(receipt.index, saved.length), 0, receipt.item);
    }
    this.publish({
      saved,
      folders: this.value.folders.map(folder =>
        receipt.decrementedCount &&
        this.countSnapshots.get(receipt.item.folderId) ===
          receipt.countSnapshot &&
        folder.id === receipt.item.folderId &&
        folder.lessonsCount !== undefined
          ? {...folder, lessonsCount: folder.lessonsCount + 1}
          : folder,
      ),
    });
  }

  beginFolderRemoval(folder: SavedFolderOption): FolderRemoval {
    const receipt: FolderRemoval = {
      owner: this.owner,
      folder,
      index: this.value.folders.findIndex(item => item.id === folder.id),
      rows: this.value.saved.flatMap((item, index) =>
        item.folderId === folder.id ? [{index, item}] : [],
      ),
      settled: false,
    };
    this.publish({
      saved: this.value.saved.filter(item => item.folderId !== folder.id),
      folders: this.value.folders.filter(item => item.id !== folder.id),
    });
    return receipt;
  }

  confirmFolderRemoval(receipt: FolderRemoval) {
    if (receipt.owner !== this.owner || receipt.settled) return;
    receipt.settled = true;
    this.removeFolder(receipt.folder.id);
  }

  rollbackFolderRemoval(receipt: FolderRemoval) {
    if (receipt.owner !== this.owner || receipt.settled) return;
    receipt.settled = true;
    const folders = [...this.value.folders];
    if (!folders.some(folder => folder.id === receipt.folder.id)) {
      folders.splice(
        Math.min(Math.max(0, receipt.index), folders.length),
        0,
        receipt.folder,
      );
    }
    const saved = [...this.value.saved];
    receipt.rows.forEach(({index, item}) => {
      if (!saved.some(row => this.rowKey(row) === this.rowKey(item)))
        saved.splice(Math.min(index, saved.length), 0, item);
    });
    this.publish({saved, folders});
  }
}
