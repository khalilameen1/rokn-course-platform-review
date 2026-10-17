import { copyFileSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const select2Source = resolve(root, 'node_modules/legacy-select2/dist');
const select2Target = resolve(root, 'public/admin/assets/js/vendor/select2');
const sortableSource = resolve(root, 'node_modules/sortablejs/Sortable.min.js');
const sortableTarget = resolve(root, 'public/admin/assets/js/vendor/sortablejs/Sortable.min.js');
const tusSource = resolve(root, 'node_modules/tus-js-client/dist/tus.min.js');
const tusTarget = resolve(root, 'public/admin/assets/js/vendor/tus-js-client/tus.min.js');

mkdirSync(select2Target, { recursive: true });
copyFileSync(resolve(select2Source, 'css/select2.min.css'), resolve(select2Target, 'select2.min.css'));
copyFileSync(resolve(select2Source, 'js/select2.min.js'), resolve(select2Target, 'select2.min.js'));
mkdirSync(dirname(sortableTarget), { recursive: true });
copyFileSync(sortableSource, sortableTarget);
mkdirSync(dirname(tusTarget), { recursive: true });
copyFileSync(tusSource, tusTarget);

console.log('Published exact Select2, SortableJS and tus-js-client distribution assets.');
