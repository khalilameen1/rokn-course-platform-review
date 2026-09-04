import fs from 'fs';
import path from 'path';

const source = (relativePath: string) =>
  fs.readFileSync(path.resolve(__dirname, '..', relativePath), 'utf8');

describe('portfolio gallery architecture', () => {
  it('keeps the public screen as a small controller-to-view boundary', () => {
    const screen = source('src/screens/Profile/Gallery.tsx');

    expect(screen.split('\n').length).toBeLessThan(40);
    expect(screen).toContain('usePortfolioGalleryController(props)');
    expect(screen).toContain(
      '<PortfolioGalleryView controller={controller} />',
    );
    expect(screen).not.toContain('useState');
    expect(screen).not.toContain('roknApi');
    expect(screen).not.toContain('StyleSheet.create');
  });

  it('keeps network, owner and draft state out of the presentation layer', () => {
    const view = source('src/screens/Profile/gallery/PortfolioGalleryView.tsx');
    const cards = source(
      'src/screens/Profile/gallery/PortfolioProjectGrid.tsx',
    );

    expect(view).not.toContain('services/roknApi');
    expect(view).not.toContain('captureAccountSessionBoundary');
    expect(view).not.toContain('PortfolioEditorDraft');
    expect(view).not.toContain('PortfolioMediaOutbox');
    expect(view).not.toContain('replayPendingPortfolioMediaUploads');
    expect(view).not.toContain('StyleSheet.create');
    expect(view).toContain('<PortfolioProjectGrid');
    expect(cards).not.toContain('services/roknApi');
  });

  it('keeps presentation and navigation out of the gallery controller', () => {
    const controller = source(
      'src/screens/Profile/gallery/usePortfolioGalleryController.ts',
    );
    const owner = source(
      'src/screens/Profile/gallery/usePortfolioOwnerBoundary.ts',
    );
    const draft = source(
      'src/screens/Profile/gallery/usePortfolioDraftEditor.ts',
    );
    const replay = source(
      'src/screens/Profile/gallery/usePortfolioMediaReplay.ts',
    );
    const create = source(
      'src/screens/Profile/gallery/usePortfolioCreateFlow.ts',
    );
    const details = source(
      'src/screens/Profile/gallery/usePortfolioProjectDetails.ts',
    );
    const selection = source(
      'src/screens/Profile/gallery/usePortfolioProjectSelection.ts',
    );

    expect(owner).toContain('captureAccountSessionBoundary');
    expect(owner).toContain('previous.scope !== boundary.scope');
    expect(draft).toContain('readPortfolioEditorDraft');
    expect(draft).toContain('writePortfolioEditorDraft');
    expect(draft).toContain('persistenceRevisionRef.current += 1');
    expect(draft).toContain('await persistenceFlightRef.current');
    expect(replay).toContain('replayPendingPortfolioMediaUploads');
    expect(controller).not.toContain('replayPendingPortfolioMediaUploads');
    expect(controller).not.toContain('navigation.navigate');
    expect(controller).not.toContain('StyleSheet.create');
    expect(controller).not.toContain('<View');
    expect(controller).not.toContain('<Modal');
    expect(controller).not.toContain('<Pressable');
    expect(controller.split('\n').length).toBeLessThan(180);
    expect(controller).not.toContain('launchImageLibrary');
    expect(controller).not.toContain('finalizePortfolioItem');
    expect(create).toContain('usePortfolioDraftEditor');
    expect(details).toContain('usePortfolioPublication');
    expect(details).toContain('usePortfolioProjectSelection');
    expect(details).not.toContain('getPortfolioItem');
    expect(selection).toContain('getPortfolioItem');
    expect(selection).toContain('detailGenerationRef');
  });

  it('keeps all gallery styles in the dedicated style module', () => {
    const styles = source('src/screens/Profile/gallery/galleryStyles.ts');
    const view = source('src/screens/Profile/gallery/PortfolioGalleryView.tsx');

    expect(styles).toContain('StyleSheet.create');
    expect(view).toContain(
      "import {galleryStyles as styles} from './galleryStyles'",
    );
  });
});
