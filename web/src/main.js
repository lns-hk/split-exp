import { startPage } from './start.js';
import { tripPage } from './trip.js';

const root = document.getElementById('app');
const rel = location.pathname.slice(new URL(document.baseURI).pathname.length);
const m = /^t\/([0-9A-Za-z]{22})\/?$/.exec(rel);
if (m) tripPage(root, m[1]);
else startPage(root);
