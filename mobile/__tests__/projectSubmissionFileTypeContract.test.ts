import {
  projectFileMatchesAllowedTypes,
  validateProjectFileType,
} from '../src/config/projects';

const DOCX =
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

describe('project submission file type contract', () => {
  it('accepts a supported Word document when Android omits its MIME type', () => {
    const file = {
      name: 'project-final.DOCX',
      type: 'application/octet-stream',
    };

    expect(projectFileMatchesAllowedTypes(file, [DOCX])).toBe(true);
    expect(() => validateProjectFileType(file)).not.toThrow();
  });

  it('lets a supported filename reach server byte validation when the provider reports a conflicting MIME', () => {
    expect(
      projectFileMatchesAllowedTypes(
        {name: 'project.docx', type: 'application/pdf'},
        [DOCX],
      ),
    ).toBe(true);
  });

  it('does not let the filename bypass the project-specific allowed types', () => {
    expect(
      projectFileMatchesAllowedTypes(
        {name: 'project.docx', type: 'application/octet-stream'},
        ['application/pdf'],
      ),
    ).toBe(false);
  });

  it('normalizes the image/jpg alias used by some Android providers', () => {
    expect(
      projectFileMatchesAllowedTypes(
        {name: 'project', type: 'image/jpg'},
        ['image/jpeg'],
      ),
    ).toBe(true);
  });

  it('rejects an unsupported extension with a generic MIME type', () => {
    expect(() =>
      validateProjectFileType({
        name: 'project.exe',
        type: 'application/octet-stream',
      }),
    ).toThrow('PROJECT_FILE_TYPE_UNSUPPORTED');
  });
});
