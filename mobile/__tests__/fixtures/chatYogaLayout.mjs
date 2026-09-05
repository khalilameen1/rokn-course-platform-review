// Run the real Yoga WASM engine outside Jest's mocked React Native renderer.
import Yoga from 'yoga-layout';
import {readFileSync} from 'node:fs';

const enums = value => value.toUpperCase().replaceAll('-', '_');
const edges = {
  Horizontal: 'HORIZONTAL',
  Vertical: 'VERTICAL',
  Top: 'TOP',
  Bottom: 'BOTTOM',
  Left: 'LEFT',
  Right: 'RIGHT',
};
const input = JSON.parse(readFileSync(0, 'utf8'));
const named = [];
function make(spec) {
  const node = Yoga.Node.create();
  const style = spec.style || {};
  for (const key of [
    'width',
    'height',
    'minWidth',
    'maxWidth',
    'minHeight',
    'maxHeight',
    'flex',
    'flexGrow',
    'flexShrink',
  ]) {
    if (style[key] !== undefined)
      node[`set${key[0].toUpperCase()}${key.slice(1)}`](style[key]);
  }
  for (const [key, prefix] of Object.entries({
    flexDirection: 'FLEX_DIRECTION',
    alignItems: 'ALIGN',
    alignSelf: 'ALIGN',
    justifyContent: 'JUSTIFY',
    direction: 'DIRECTION',
  })) {
    if (style[key])
      node[`set${key[0].toUpperCase()}${key.slice(1)}`](
        Yoga[`${prefix}_${enums(style[key])}`],
      );
  }
  for (const kind of ['padding', 'margin']) {
    if (style[kind] !== undefined)
      node[`set${kind[0].toUpperCase()}${kind.slice(1)}`](
        Yoga.EDGE_ALL,
        style[kind],
      );
    for (const [suffix, edge] of Object.entries(edges)) {
      if (style[kind + suffix] !== undefined)
        node[`set${kind[0].toUpperCase()}${kind.slice(1)}`](
          Yoga[`EDGE_${edge}`],
          style[kind + suffix],
        );
    }
  }
  if (style.borderWidth !== undefined)
    node.setBorder(Yoga.EDGE_ALL, style.borderWidth);
  for (const [suffix, edge] of Object.entries(edges)) {
    if (style[`border${suffix}Width`] !== undefined)
      node.setBorder(Yoga[`EDGE_${edge}`], style[`border${suffix}Width`]);
  }
  if (style.gap !== undefined) node.setGap(Yoga.GUTTER_ALL, style.gap);
  if (spec.measure) node.setMeasureFunc(() => spec.measure);
  (spec.children || []).forEach((child, index) =>
    node.insertChild(make(child), index),
  );
  if (spec.name) named.push([spec.name, node]);
  return node;
}
const root = make(input);
root.calculateLayout(undefined, undefined, Yoga.DIRECTION_RTL);
const result = Object.fromEntries(
  named.map(([name, node]) => {
    const layout = node.getComputedLayout();
    let parent = node.getParent();
    while (parent) {
      layout.top += parent.getComputedTop();
      layout.left += parent.getComputedLeft();
      parent = parent.getParent();
    }
    return [name, layout];
  }),
);
process.stdout.write(JSON.stringify(result));
root.freeRecursive();
