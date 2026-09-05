import fs from 'fs';
import path from 'path';
import ts from 'typescript';

const sourceRoot = path.resolve(__dirname, '..', 'src');

const sourceFiles = (directory: string): string[] =>
  fs.readdirSync(directory, {withFileTypes: true}).flatMap(entry => {
    const absolutePath = path.join(directory, entry.name);
    if (entry.isDirectory()) return sourceFiles(absolutePath);
    return /\.(?:ts|tsx)$/.test(entry.name) ? [absolutePath] : [];
  });

const literalProperty = (
  node: ts.ObjectLiteralExpression,
  propertyName: string,
): string | undefined => {
  const property = node.properties.find(
    candidate =>
      ts.isPropertyAssignment(candidate) &&
      ((ts.isIdentifier(candidate.name) && candidate.name.text === propertyName) ||
        (ts.isStringLiteral(candidate.name) && candidate.name.text === propertyName)),
  );
  if (!property || !ts.isPropertyAssignment(property)) return undefined;
  return ts.isStringLiteral(property.initializer)
    ? property.initializer.text
    : undefined;
};

describe('RTL direction contract', () => {
  it('keeps physical-left text exclusive to explicit LTR machine values', () => {
    const violations: string[] = [];

    sourceFiles(sourceRoot).forEach(filePath => {
      const contents = fs.readFileSync(filePath, 'utf8');
      const sourceFile = ts.createSourceFile(
        filePath,
        contents,
        ts.ScriptTarget.Latest,
        true,
        filePath.endsWith('.tsx') ? ts.ScriptKind.TSX : ts.ScriptKind.TS,
      );

      const visit = (node: ts.Node) => {
        if (ts.isObjectLiteralExpression(node)) {
          const textAlign = literalProperty(node, 'textAlign');
          const flexDirection = literalProperty(node, 'flexDirection');
          if (
            textAlign === 'left' &&
            (literalProperty(node, 'direction') !== 'ltr' ||
              literalProperty(node, 'writingDirection') !== 'ltr')
          ) {
            const {line} = sourceFile.getLineAndCharacterOfPosition(node.getStart());
            violations.push(
              `${path.relative(sourceRoot, filePath)}:${line + 1} uses left without explicit LTR`,
            );
          }
          if (flexDirection === 'row-reverse') {
            const {line} = sourceFile.getLineAndCharacterOfPosition(node.getStart());
            violations.push(
              `${path.relative(sourceRoot, filePath)}:${line + 1} reverses the native RTL row`,
            );
          }
        }
        ts.forEachChild(node, visit);
      };

      visit(sourceFile);
    });

    expect(violations).toEqual([]);
  });
});
