// Regression tests for the garden editor's anchored, rotated resize geometry.
// Run with: node tests/garden_editor_geometry_test.js
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('admin/assets/garden.js', 'utf8');
function productionFunction(name) {
  const start = source.indexOf('function ' + name + '(');
  assert.ok(start >= 0, name + ' must exist in production');
  const brace = source.indexOf('{', start);
  let depth = 0, end = -1;
  for (let i = brace; i < source.length; i++) {
    if (source[i] === '{') depth++;
    if (source[i] === '}' && --depth === 0) { end = i + 1; break; }
  }
  assert.ok(end > brace, name + ' must be complete');
  return vm.runInNewContext('(' + source.slice(start, end) + ')');
}
const state = productionFunction('state');
const differs = productionFunction('differs');
const corners = productionFunction('corners');

function world(b, x, y) {
  const angle = b.rot * Math.PI / 180, c = Math.cos(angle), s = Math.sin(angle);
  const dx = x - b.len / 2, dy = y - b.wid / 2;
  return [b.x + b.len / 2 + dx * c - dy * s,
          b.y + b.wid / 2 + dx * s + dy * c];
}
function resized(old, length, width) {
  const angle = old.rot * Math.PI / 180, c = Math.cos(angle), s = Math.sin(angle);
  const dl = length - old.len, dw = width - old.wid;
  return { ...old, len: length, wid: width,
    x: old.x + (dl * (c - 1) - dw * s) / 2,
    y: old.y + (dl * s + dw * (c - 1)) / 2 };
}
function close(actual, expected) {
  assert.ok(Math.abs(actual - expected) < 1e-8, `Expected ${actual} to equal ${expected}`);
}
for (const rot of [0, 15, 45, 90, 135, 270, 359]) {
  for (const [length, width] of [[6, 3], [15, 3], [6, 9], [1, 1]]) {
    const before = { x: 10, y: 20, len: 6, wid: 3, rot };
    const after = resized(before, length, width);
    assert.equal(differs(before, state(after)), length !== before.len || width !== before.wid);
    assert.deepEqual(Object.keys(state(after)).sort(), ['id','len','rot','wid','x','y'].sort());
    const worldCorners = corners(after);
    close(worldCorners[0][0], world(after, 0, 0)[0]);
    close(worldCorners[0][1], world(after, 0, 0)[1]);
    const origin = world(before, 0, 0);
    const newOrigin = world(after, 0, 0);
    close(origin[0], newOrigin[0]);
    close(origin[1], newOrigin[1]);
    for (const [x, y] of [[0, 0], [6, 0], [0, 3], [6, 3]]) {
      const angle = rot * Math.PI / 180;
      const scaled = [origin[0] + Math.cos(angle) * x * length / before.len - Math.sin(angle) * y * width / before.wid,
                      origin[1] + Math.sin(angle) * x * length / before.len + Math.cos(angle) * y * width / before.wid];
      // Preview scales old local coordinates before original rotation/translation.
      const actual = world(after, x * length / before.len, y * width / before.wid);
      close(scaled[0], actual[0]);
      close(scaled[1], actual[1]);
    }
  }
}
console.log('Garden resize geometry regression checks passed.');
